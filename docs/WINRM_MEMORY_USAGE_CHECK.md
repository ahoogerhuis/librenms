# WinRM Check — Memory Usage (`memory-usage`)

**Contents**
- [Status](#status)
- [Goal](#goal)
- [Three genuinely distinct things, not two](#three-genuinely-distinct-things-not-two)
- [The unit trap: two WMI classes, two different units](#the-unit-trap-two-wmi-classes-two-different-units)
- [JEA function](#jea-function)
- [Proxy side](#proxy-side)
- [`WinrmPoller.php` side: `Mempool`, not `Sensor` — same reasoning as `disk-space`/`Storage`](#winrmpollerphp-side-mempool-not-sensor-same-reasoning-as-disk-spacestorage)
- [Layout: confirmed to match a Linux node automatically, by construction](#layout-confirmed-to-match-a-linux-node-automatically-by-construction)
- [Next step](#next-step)
- [Deploying this check](#deploying-this-check)

<a id="status"></a>

## Status
**Confirmed working end-to-end against the real target** (2026-08-10) — `WinrmPoller.php` populating three real `Mempool` rows via `device:discover`/`device:poll` on `lnms-poller.vpp.local`, verified by direct DB query (not just "no errors"):

```
physical (system):   34%, used=2891419648  free=5627645952  total=8519065600
virtual  (virtual):  30%, used=3190210560  free=7342120960  total=10532331520
pagefile-0 (swap):    21%, used=429916160  free=1583349760  total=2013265920, descr="C:\pagefile.sys"
```

Matches the raw `Get-MemoryUsageStatus` output exactly (`{"PhysicalTotalBytes":8519065600,"PhysicalFreeBytes":5642317824,...}` at check-time — free values differ slightly between calls since real memory usage fluctuates, not a bug). A second `device:poll` confirmed the update path: still exactly 3 rows (no duplicates), 13 RRD updates and 0 creates (vs. 3 creates on the first poll). `phpstan analyse` clean, `php -l` clean, existing `WinrmPollerTest.php` (applicability logic, untouched by this change) still 6/6.

Worked correctly against the real target on the first deployment — no `Get-CimInstance`/`-AsArray` rediscovery needed, both lessons from `disk-space` applied from the start (see JEA function below).

<a id="goal"></a>

## Goal
Physical RAM, virtual memory, and page file(s) usage — analogous to SNMP-based memory-pool monitoring on Linux hosts.

<a id="three-genuinely-distinct-things-not-two"></a>

## Three genuinely distinct things, not two

- **Physical RAM** — `Win32_OperatingSystem.TotalVisibleMemorySize`/`FreePhysicalMemory`. One scalar pair.
- **Virtual memory** — `Win32_OperatingSystem.TotalVirtualMemorySize`/`FreeVirtualMemory`, same WMI class. **Not "swap"** — on Windows this is *total addressable virtual memory* (physical RAM + page file combined), a broader concept than page-file usage specifically. Also a scalar pair.
- **Page file(s)** — the actual "swap" — `Win32_PageFileUsage` (`Name`, `AllocatedBaseSize`, `CurrentUsage`), a genuinely separate WMI class, **not derived** by subtracting the two figures above. Windows doesn't guarantee exactly one page file exists — this is the one field that can return more than one row (or zero).

<a id="the-unit-trap-two-wmi-classes-two-different-units"></a>

## The unit trap: two WMI classes, two different units

`Win32_OperatingSystem`'s memory fields are in **KB**. `Win32_PageFileUsage`'s fields are in **MB**. Both normalized to **bytes** inside the JEA function itself (`* 1KB` / `* 1MB`, PowerShell's built-in size literals) — one consistent unit decided once at the source, matching `disk-space`'s convention, not left for the proxy or `WinrmPoller.php` to guess at or get wrong.

<a id="jea-function"></a>

## JEA function

```powershell
function Get-MemoryUsageStatus {
    [CmdletBinding()]
    param()

    $os = Get-WmiObject -Class Win32_OperatingSystem
    $pageFiles = @(
        Get-WmiObject -Class Win32_PageFileUsage | ForEach-Object {
            [pscustomobject]@{
                Name           = $_.Name
                AllocatedBytes = [int64]$_.AllocatedBaseSize * 1MB
                UsedBytes      = [int64]$_.CurrentUsage * 1MB
            }
        }
    )

    $lastBootUpTime = $null
    if ($os.LastBootUpTime) {
        $lastBootUpTime = [System.Management.ManagementDateTimeConverter]::ToDateTime($os.LastBootUpTime).ToUniversalTime().ToString('o')
    }

    [pscustomobject]@{
        PhysicalTotalBytes = [int64]$os.TotalVisibleMemorySize * 1KB
        PhysicalFreeBytes  = [int64]$os.FreePhysicalMemory * 1KB
        VirtualTotalBytes  = [int64]$os.TotalVirtualMemorySize * 1KB
        VirtualFreeBytes   = [int64]$os.FreeVirtualMemory * 1KB
        PageFiles          = $pageFiles
        LastBootUpTime     = $lastBootUpTime
    } | ConvertTo-Json -Compress -Depth 4
}
```

**Current version, including `LastBootUpTime`** (see `WINRM_UPTIME_AND_HARDWARE_FIX.md` — `WinrmPoller.php` uses this to compute `Device.uptime`). `.ToUniversalTime()` before `.ToString('o')` is not decorative: `ManagementDateTimeConverter.ToDateTime()` returns `Kind=Unspecified`, confirmed by real testing — a naive `.ToString('o')` omits the UTC offset entirely, which downstream parsing would silently misinterpret as UTC when it's really the target's local time.

`Get-WmiObject`, not `Get-CimInstance` (unreachable inside JEA, see `WINRM_DISK_SPACE_CHECK.md`), and `PageFiles` built via `@(...)` before assignment to avoid `ConvertTo-Json`'s single-element pipeline collapse (see the same doc) — both applied proactively this time, not rediscovered. `-Depth 4` explicit rather than relying on `ConvertTo-Json`'s default (2), since the nested `PageFiles` array of objects needs more than the default depth to serialize fully rather than silently truncating to type names.

Tested directly via `debugadmin`'s unconstrained session before JEA deployment (same discipline as every prior check) — worked correctly on the first real-target run; output cross-checked by hand (physical + page file ≈ virtual total, confirming both WMI classes and both unit conversions are consistent with each other).

<a id="proxy-side"></a>

## Proxy side

`app/checks/memory_usage.py` (`alexh/librenms-bits`) invokes `Get-MemoryUsageStatus` and returns:
```json
{
  "physical": {"total_bytes": ..., "free_bytes": ...},
  "virtual": {"total_bytes": ..., "free_bytes": ...},
  "page_files": [{"name": ..., "allocated_bytes": ..., "used_bytes": ...}]
}
```

First check needing **mixed scalar + list validation** in one function — every prior check is either pure scalar (`reboot-pending`/`service-status-*`/`winupdate-pending`) or pure list (`disk-space`/`network-traffic`). Combines both existing validation patterns rather than inventing a third: the missing/wrong-type-key guard from `winupdate_pending.py` for the four physical/virtual scalar fields, and the `isinstance(data, list)`-style guard from `disk_space.py` for `PageFiles` (same `ConvertTo-Json` single-element-collapse risk, guarded even though the JEA function already wraps in `@(...)`). Registered in `registry.py` as `"memory-usage"`. 14 tests (`tests/test_checks_memory_usage.py`), full proxy suite 105/105 passing.

<a id="winrmpollerphp-side-mempool-not-sensor-same-reasoning-as-disk-spacestorage"></a>

## `WinrmPoller.php` side: `Mempool`, not `Sensor` — same reasoning as `disk-space`/`Storage`

`App\Models\Mempool` fits this check for the same reason `Storage` fit `disk-space`: a variable-length list (0+ page files, plus two fixed entries), not the fixed-scalar shape `checks()`'s `Sensor` table handles. Confirmed by reading `LibreNMS\Modules\Mempools` and `App\Models\Mempool` directly before assuming the mapping (same discipline `Storage.php` got before `disk-space`, given how differently `network-traffic` ended up needing to work from its own original guess):

- **`Mempool.mempool_class` taxonomy already fits with zero new classes**: `'system'` (physical RAM — matches every other OS driver's convention for the primary RAM pool), `'virtual'` (virtual memory), `'swap'` (page files — literally what that class name means elsewhere in LibreNMS). No invented classes.
- **The built-in `LibreNMS\Modules\Mempools` module never runs for these devices** — its `shouldDiscover()`/`shouldPoll()` both require `ConnectivityHelper::snmpIsAvailable()`, false for `snmp_disable` WinRM-only devices. Zero collision risk with `WinrmPoller.php` writing to the same table directly, same situation as `Storage`/SNMP-based storage.
- **Same `SyncsModels`/`ModuleModelObserver`/composite-key machinery reused as-is**, not reimplemented — `Mempool::getCompositeKey()` (`"$mempool_type-$mempool_index"`) already implements `Keyable`, exactly parallel to `Storage`.
- **`Mempool.mempool_index` is a 16-char DB column** (`2018_07_03_091314_create_mempools_table.php`) — fine for the fixed `'physical'`/`'virtual'` indices, but a real page-file path (e.g. `C:\Program Files\pagefile.sys`) can exceed that easily. Page files are keyed by ordinal position (`pagefile-0`, `pagefile-1`, ...) instead, with the real path kept in `mempool_descr` (64 chars) — same short-stable-key + separate-human-descr split `disk-space` uses for `storage_index`/`storage_descr`. Same caveat as drive letters/interface names: a page-file reconfiguration (rare) reads as "removed, new one added" on the next discovery cycle, not worth solving preemptively.
- **Different `fillUsage()` argument pairing per source, not a bug**: physical/virtual give `(null, total, free, null)` — `Win32_OperatingSystem` reports total+free, matching `disk-space`'s exact pattern. Page files give `(used, total, null, null)` — `Win32_PageFileUsage` has no "free" field at all, only allocated size (total) and current usage (used), so free/percent are derived from that pair instead. Both call the same `Mempool::fillUsage()`/`Number::fillMissingRatio()` machinery, just supplying whichever two figures the underlying WMI class actually reports.

`fetchMemoryMempoolModels()` runs the check and builds (unsaved) `Mempool` instances, shared by `discover()`/`poll()` — same split as `fetchDiskStorageModels()`. Defensive validation happens again at this layer even though the proxy already validates the same shape, same "don't trust the wire format twice-removed" stance as every other check. `pollMemoryUsage()` mirrors `pollDiskSpace()`'s discover-handles-topology/poll-handles-values split exactly, including matching `LibreNMS\Modules\Mempools::poll()`'s own RRD tag/dataset convention (`'mempool', type, class, index`) so this interoperates with existing mempool-graph rendering code with zero changes needed there.

`cleanup()`/`dataExists()`/`dump()` extended to cover `Mempool` rows (`mempool_type = 'winrm'`) alongside `Sensor`/`Storage`/`Port`.

<a id="layout-confirmed-to-match-a-linux-node-automatically-by-construction"></a>

## Layout: confirmed to match a Linux node automatically, by construction

Checked directly (not assumed) whether populating the real `Mempool` model gets this check the same UI placement a Linux node's SNMP-based memory panel gets. `includes/html/pages/device/overview.inc.php` renders overview panels in a **fixed, hardcoded order** for every device regardless of OS/module/poller type — right pane: `processors → mempools → storage → toner → sensor → eventlog → services → syslog → graylog`. `overview/mempools.inc.php` itself queries `DeviceCache::getPrimary()->mempools` directly, with **no module/poller_type gating at all** — same for `overview/storage.inc.php` and `overview/processors.inc.php` (raw `SELECT * FROM storage/processors WHERE device_id = ?`). Because this check writes into the same real `Mempool` table any other OS driver would, the "Memory" panel (percentage bars, combined `device_mempool` graph, per-pool mini-graphs) renders in the exact same position a Linux node's would, automatically — confirmed by reading the rendering code, not just hoped for. This is the same "reuse the real upstream model, get the UI for free" outcome `disk-space`/`Storage` and `network-traffic`/`Port` already established; also the basis for `cpu-usage` targeting the real `Processors` model for the same reason (see `WINRM_DESIGN.md`'s backlog).

<a id="next-step"></a>

## Next step

None outstanding for this check — fully built, tested, and verified end-to-end. `cpu-usage` is next (separate handoff, separate doc).

<a id="deploying-this-check"></a>

## Deploying this check

Incremental add — assumes `WINRM_JEA_SETUP.md`'s first-time setup already ran, plus `reboot-pending`, the two `service-status-*` checks, `winupdate-pending`, `disk-space`, and `network-traffic` (see those docs). Adds `Get-MemoryUsageStatus` (current version, with `LastBootUpTime`) and its `.psrc` whitelist entry. `.psrc`/`.psm1`-only — no `.pssc` change, no WinRM restart (see `WINRM_JEA_SETUP.md` §5).

```powershell
# Update-WinrmProbeJEA-MemoryUsage.ps1
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

function Get-NetworkInterfaceStats {
    [CmdletBinding()]
    param()

    $adaptersByName = @{}
    Get-WmiObject -Class Win32_NetworkAdapter -Filter "PhysicalAdapter=True" | ForEach-Object {
        $adaptersByName[$_.Name] = $_
    }

    $stats = @(
        Get-WmiObject -Class Win32_PerfRawData_Tcpip_NetworkInterface | ForEach-Object {
            $adapter = $adaptersByName[$_.Name]

            $netEnabled = $null
            $netConnectionStatus = $null
            if ($adapter) {
                $netEnabled = $adapter.NetEnabled
                $netConnectionStatus = $adapter.NetConnectionStatus
            }

            [pscustomobject]@{
                InterfaceName       = $_.Name
                BytesSent           = [int64]$_.BytesSentPersec
                BytesReceived       = [int64]$_.BytesReceivedPersec
                NetEnabled          = $netEnabled
                NetConnectionStatus = $netConnectionStatus
            }
        }
    )
    ConvertTo-Json -InputObject $stats -Compress
}

function Get-MemoryUsageStatus {
    [CmdletBinding()]
    param()

    $os = Get-WmiObject -Class Win32_OperatingSystem
    $pageFiles = @(
        Get-WmiObject -Class Win32_PageFileUsage | ForEach-Object {
            [pscustomobject]@{
                Name           = $_.Name
                AllocatedBytes = [int64]$_.AllocatedBaseSize * 1MB
                UsedBytes      = [int64]$_.CurrentUsage * 1MB
            }
        }
    )

    $lastBootUpTime = $null
    if ($os.LastBootUpTime) {
        $lastBootUpTime = [System.Management.ManagementDateTimeConverter]::ToDateTime($os.LastBootUpTime).ToUniversalTime().ToString('o')
    }

    [pscustomobject]@{
        PhysicalTotalBytes = [int64]$os.TotalVisibleMemorySize * 1KB
        PhysicalFreeBytes  = [int64]$os.FreePhysicalMemory * 1KB
        VirtualTotalBytes  = [int64]$os.TotalVirtualMemorySize * 1KB
        VirtualFreeBytes   = [int64]$os.FreeVirtualMemory * 1KB
        PageFiles          = $pageFiles
        LastBootUpTime     = $lastBootUpTime
    } | ConvertTo-Json -Compress -Depth 4
}

Export-ModuleMember -Function Get-RebootPendingStatus, Get-WuauservStatus, Get-W32timeStatus, Get-PendingUpdateStatus, Get-LocalDiskSpace, Get-NetworkInterfaceStats, Get-MemoryUsageStatus
'@ | Set-Content -Path (Join-Path $moduleRoot 'WinrmProbeJEA.psm1') -Encoding UTF8

@'
@{
    Author = 'Your Name'
    CompanyName = 'VPP'
    Description = 'Constrained check functions exposed to the WinRM proxy service account.'

    VisibleFunctions = @('Get-RebootPendingStatus', 'Get-WuauservStatus', 'Get-W32timeStatus', 'Get-PendingUpdateStatus', 'Get-LocalDiskSpace', 'Get-NetworkInterfaceStats', 'Get-MemoryUsageStatus')
    VisibleCmdlets = @()
    VisibleAliases = @()
    VisibleExternalCommands = @()
    VisibleProviders = @()
}
'@ | Set-Content -Path (Join-Path $roleCapDir 'WinrmProbe.psrc') -Encoding UTF8

Write-Host "Done, no WinRM restart performed. Validate with a NEW session:"
Write-Host "  Enter-PSSession -ComputerName $env:COMPUTERNAME -ConfigurationName WinrmProbe"
Write-Host "  Get-MemoryUsageStatus   # expect JSON output including a LastBootUpTime field"
```

Then run `WINRM_JEA_SETUP.md` §7's validation checklist to confirm the constraint still holds.
