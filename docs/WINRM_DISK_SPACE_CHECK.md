# WinRM Check — Local Disk Space (`disk-space`)

**Contents**
- [Status](#status)
- [Goal](#goal)
- [Sensor-shape decision: `Storage`, not `Sensor`](#sensor-shape-decision-storage-not-sensor)
- [JEA function](#jea-function)
- [Proxy side](#proxy-side)
- [`WinrmPoller.php` side](#winrmpollerphp-side)
- [Explicitly out of scope, deliberate v2 gaps (not oversights)](#explicitly-out-of-scope-deliberate-v2-gaps-not-oversights)
- [Next step](#next-step)
- [Deploying this check](#deploying-this-check)

<a id="status"></a>

## Status
**Confirmed working end-to-end against the real target** (2026-08-10) — `WinrmPoller.php` populating a real `Storage` row via `device:discover`/`device:poll` on `lnms-poller.vpp.local`: `{"type":"winrm","storage_index":"C:","storage_size":85253419008,"storage_used":19096739840,"storage_free":66156679168,"storage_perc":22}`. Also proved the JEA whitelist constraint holds for this function specifically (`Get-LocalDiskSpace` works, `Get-Process`/`Get-ItemProperty`/`Get-CimInstance` all still correctly fail), same as every check before it.

Two real bugs found and fixed during real-target testing, both PowerShell-version issues neither stub tests nor documentation-reading would have caught:
1. `Get-CimInstance` isn't reachable inside a JEA `RestrictedRemoteServer` session at all (module auto-loading is disabled, and `CimCmdlets` — `Get-CimInstance`'s module — was never loaded). Fixed with `Get-WmiObject`, which lives in the same always-loaded core module as `Get-ItemProperty`/`Get-Service`.
2. `-AsArray` (the first fix for `ConvertTo-Json`'s single-element-collapses-to-object gotcha) doesn't exist on Windows PowerShell 5.1 — added in PowerShell 6.0. Fixed with `@(...)` + `-InputObject` binding instead of piping.

Both fixes and the reasoning are in `WINRM_JEA_WINDOWS.md` (`vpp/infra-bits`).

<a id="goal"></a>

## Goal
Per-disk free/used space for local fixed disks, analogous to SNMP-based storage monitoring on Linux hosts.

<a id="sensor-shape-decision-storage-not-sensor"></a>

## Sensor-shape decision: `Storage`, not `Sensor`

Every prior check (`reboot-pending`, `service-status-*`, `winupdate-pending`) populates `App\Models\Sensor` via `WinrmPoller.php`'s generic `checks()` table — one scalar value per check, one sensor per check. `disk-space` doesn't fit that shape: a device can have any number of fixed disks, discovered dynamically, not known ahead of time the way `checks()`'s entries are.

Instead, `WinrmPoller.php` populates `App\Models\Storage` directly — the same model SNMP-based storage monitoring uses (`LibreNMS\Modules\Storage`), confirmed by reading that class's actual source rather than assumed. Reuses its established machinery as-is:
- `LibreNMS\DB\SyncsModels` trait (`syncModels()`) for discover-time add/update/remove of disk rows, keyed by `Storage`'s own composite key (`"$type-$storage_index"`).
- `App\Observers\ModuleModelObserver::observe(Storage::class)` before syncing — same call `LibreNMS\Modules\Storage::discover()` makes.
- `LibreNMS\RRD\RrdDefinition` + `$datastore->put(..., 'storage', ...)` for the RRD write in `poll()` — identical shape to `LibreNMS\Modules\Storage::poll()`'s own write.

**`Storage.type` is `'winrm'`** — a distinct namespace from SNMP-based types (e.g. `hrstorage`), so composite keys never collide even if a device somehow had both sources (not a real scenario today, since WinRM-only devices are `snmp_disable`, but free to guard against cheaply).

**Same discover-handles-topology / poll-handles-values split `LibreNMS\Modules\Storage` itself uses**, not something invented for this check: `discover()` calls the check once, builds `Storage` instances, and syncs (adds/updates/removes rows to match reality). `poll()` calls the check again, but only *updates values* on already-discovered rows — a disk appearing in a poll result with no matching discovered row (added since the last discover cycle) is logged and skipped, not silently created mid-poll. Both discover and poll query the *same* check (`disk-space` returns full data every call, unlike SNMP's separate walk-then-poll-by-OID split) — the difference is purely in what each does with the result.

<a id="jea-function"></a>

## JEA function

```powershell
function Get-LocalDiskSpace {
    [CmdletBinding()]
    param()  # deliberately no parameters

    $disks = @(
        Get-WmiObject -Class Win32_LogicalDisk -Filter "DriveType=3" | ForEach-Object {
            [pscustomobject]@{
                DriveLetter = $_.DeviceID
                SizeBytes   = [int64]$_.Size
                FreeBytes   = [int64]$_.FreeSpace
            }
        }
    )
    ConvertTo-Json -InputObject $disks -Compress
}
```

**`Get-WmiObject`, not `Get-CimInstance` — found for real, not a style choice.** The first version used `Get-CimInstance` and failed against the real target with `The term 'Get-CimInstance' is not recognized...`, confirmed both via the proxy's real HTTP API and directly via `pypsrp` — a genuine "cmdlet not reachable" error, not a JEA-authorization rejection. Reason: `RestrictedRemoteServer` disables module auto-loading, so only cmdlets from modules already imported at session startup exist *at all* in that runspace, for anyone — `VisibleCmdlets`/`VisibleFunctions` gates what the caller can invoke, not what modules got loaded in the first place. `Get-ItemProperty`/`Get-Service`/`New-Object` all work internally in the other three checks because they live in `Microsoft.PowerShell.Management`/`Utility`, always-loaded core modules; `Get-CimInstance` lives in `CimCmdlets`, normally pulled in by module auto-loading — exactly what JEA disables. `Get-WmiObject` is in that same always-loaded core module and queries identical WMI class/properties, so it works with no `.pssc` change needed (the alternative, `ModulesToImport = @('CimCmdlets')`, is a session-config structural change requiring a WinRM restart — avoided in favor of staying a hot-reloadable `.psrc`/`.psm1`-only change). `Get-WmiObject` is legacy (removed in PowerShell 7+) but fully present on Windows PowerShell 5.1, which Server 2019 actually runs — not a real compatibility risk here. **Generalizes: any future JEA function needing WMI/CIM data should default to `Get-WmiObject`, not `Get-CimInstance`, unless there's a specific reason to add explicit `ModulesToImport` instead.** Full detail in `WINRM_JEA_WINDOWS.md` (`vpp/infra-bits`).

`DriveType=3` filters to local fixed disks only — excludes removable (2), network (4), CD-ROM (5), RAM disk (6).

**Guaranteeing array output took two real attempts.** `ConvertTo-Json` on a single-element pipeline collapses to a bare JSON object — same category of gotcha as `service-status`'s `.ToString()` enum-serialization issue, would look correct on a multi-disk test host and silently break on a single-disk one. First attempt used `-AsArray`, which doesn't exist on Windows PowerShell 5.1 (added in PowerShell 6.0) — confirmed against the real target, not assumed from current-PowerShell-version docs. The fix that actually works on 5.1: build the array with `@(...)`, then bind it via `-InputObject` rather than piping — piping unrolls the array back to one item at a time regardless of how it was built, so only non-piped `-InputObject` binding preserves array-shaped output for 0/1/many elements alike. Guarded on both sides regardless: the proxy-side check (`app/checks/disk_space.py`) explicitly checks `isinstance(data, list)` and fails loud rather than iterating a bare dict's keys if this were ever broken again.

<a id="proxy-side"></a>

## Proxy side

`app/checks/disk_space.py` (`alexh/librenms-bits`) invokes `Get-LocalDiskSpace`, validates each disk entry (drive letter non-empty string, size/free both integers), and returns `{"disks": [{"drive_letter": ..., "size_bytes": ..., "free_bytes": ...}, ...]}` — the first check whose result is a list, not a flat scalar dict. Registered in `registry.py` as `"disk-space"`. 12 tests (`tests/test_checks_disk_space.py`), covering the `-AsArray` gotcha explicitly, alongside the existing 30 for the other three checks (42/42 passing).

<a id="winrmpollerphp-side"></a>

## `WinrmPoller.php` side

`fetchDiskStorageModels()` runs the check and builds (unsaved) `Storage` instances — shared by `discover()` and `poll()`, since the check itself is identical either way. Defensive validation happens **again** at this layer (non-empty drive letter, numeric size/free) even though the proxy already validates the same shape — this module doesn't trust the wire format just because the check that produced it happens to validate on its own side too.

`fillUsage(null, $sizeBytes, $freeBytes, null)` — `used`/`percent` left null, derived from `size`+`free` via `Storage::fillUsage()`'s existing `Number::fillMissingRatio()` logic, matching exactly what the check provides (total+free, not used directly).

`cleanup()`/`dataExists()`/`dump()` extended to cover `Storage` rows (`type = 'winrm'`) alongside the existing `Sensor` rows every other check populates.

<a id="explicitly-out-of-scope-deliberate-v2-gaps-not-oversights"></a>

## Explicitly out of scope, deliberate v2 gaps (not oversights)

1. **Path-mounted volumes** (NTFS mount points, no drive letter — e.g. `C:\Data\`, common when a server has more volumes than spare drive letters). `Win32_LogicalDisk`'s `DeviceID` is drive-letter-shaped; a path-only mount won't show up via this check at all. Would need `Get-Volume` (whose `DriveLetter` can be null for these) paired with mount-point enumeration (`Win32_MountPoint`/`Get-Partition`'s `AccessPaths`) — not designed now, just noting the gap. Also affects what the `Storage` composite-key "index" would even be, since drive letter (today's choice) doesn't exist for these.
2. **Cluster Shared Volumes (CSV).** CSVs mount as folders under `C:\ClusterStorage\`, not as a `Win32_LogicalDisk`-enumerable drive letter — invisible to this check entirely. Would need `MSCluster_*` WMI classes, a different query mechanism, if failover clustering is ever relevant to the fleet. Nothing about the current environment suggests clustering is in use; worth a deliberate decision if that changes, not an unnoticed gap.

<a id="next-step"></a>

## Next step

None outstanding — built, deployed, and verified end-to-end (see Status above). See "Deploying this check" below for the JEA side.

<a id="deploying-this-check"></a>

## Deploying this check

Incremental add — assumes `WINRM_JEA_SETUP.md`'s first-time setup already ran, plus `reboot-pending`, the two `service-status-*` checks, and `winupdate-pending` (see those docs). Adds `Get-LocalDiskSpace` and its `.psrc` whitelist entry. `.psrc`/`.psm1`-only — no `.pssc` change, no WinRM restart (see `WINRM_JEA_SETUP.md` §5).

```powershell
# Update-WinrmProbeJEA-DiskSpace.ps1
if ($env:COMPUTERNAME -ne 'WINRM-TEST01') {
    throw "This script must run on WINRM-TEST01, not $env:COMPUTERNAME. Aborting."
}

$moduleRoot = 'C:\Program Files\WindowsPowerShell\Modules\WinrmProbeJEA'
$roleCapDir = Join-Path $moduleRoot 'RoleCapabilities'

@'
function Get-RebootPendingStatus {
    [CmdletBinding()]
    param()

    $wuPath  = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired'
    $pfroKey = 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager'

    [pscustomobject]@{
        RebootRequired = Test-Path $wuPath
        PendingFileRenameOperations = $null -ne (
            Get-ItemProperty -Path $pfroKey -Name PendingFileRenameOperations -ErrorAction SilentlyContinue
        )
    } | ConvertTo-Json -Compress
}

function Get-WuauservStatus {
    [CmdletBinding()]
    param()
    (Get-Service -Name wuauserv).Status.ToString() | ConvertTo-Json -Compress
}

function Get-W32timeStatus {
    [CmdletBinding()]
    param()
    (Get-Service -Name W32Time).Status.ToString() | ConvertTo-Json -Compress
}

function Get-PendingUpdateStatus {
    [CmdletBinding()]
    param()

    try {
        $e = Get-WinEvent -LogName 'Microsoft-Windows-WindowsUpdateClient/Operational' -FilterXPath "*[System[EventID=26]]" -MaxEvents 1 -ErrorAction Stop
    } catch [Exception] {
        [pscustomobject]@{
            PendingCount = $null
            LastScanTime = $null
        } | ConvertTo-Json -Compress
        return
    }

    $count = $null
    if ($e.Message -match 'successfully found (\d+) updates') {
        $count = [int]$Matches[1]
    }

    [pscustomobject]@{
        PendingCount = $count
        LastScanTime = $e.TimeCreated.ToString('o')
    } | ConvertTo-Json -Compress
}

function Get-LocalDiskSpace {
    [CmdletBinding()]
    param()

    $disks = @(
        Get-WmiObject -Class Win32_LogicalDisk -Filter "DriveType=3" | ForEach-Object {
            [pscustomobject]@{
                DriveLetter = $_.DeviceID
                SizeBytes   = [int64]$_.Size
                FreeBytes   = [int64]$_.FreeSpace
            }
        }
    )
    ConvertTo-Json -InputObject $disks -Compress
}

Export-ModuleMember -Function Get-RebootPendingStatus, Get-WuauservStatus, Get-W32timeStatus, Get-PendingUpdateStatus, Get-LocalDiskSpace
'@ | Set-Content -Path (Join-Path $moduleRoot 'WinrmProbeJEA.psm1') -Encoding UTF8

@'
@{
    Author = 'Your Name'
    CompanyName = 'VPP'
    Description = 'Constrained check functions exposed to the WinRM proxy service account.'

    VisibleFunctions = @('Get-RebootPendingStatus', 'Get-WuauservStatus', 'Get-W32timeStatus', 'Get-PendingUpdateStatus', 'Get-LocalDiskSpace')
    VisibleCmdlets = @()
    VisibleAliases = @()
    VisibleExternalCommands = @()
    VisibleProviders = @()
}
'@ | Set-Content -Path (Join-Path $roleCapDir 'WinrmProbe.psrc') -Encoding UTF8

Write-Host "Done, no WinRM restart performed. Validate with a NEW session:"
Write-Host "  Enter-PSSession -ComputerName $env:COMPUTERNAME -ConfigurationName WinrmProbe"
Write-Host "  Get-LocalDiskSpace   # expect a JSON array, one entry per local fixed disk"
```

Then run `WINRM_JEA_SETUP.md` §7's validation checklist to confirm the constraint still holds.
