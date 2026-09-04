# WinRM Check — CPU Usage (`cpu-usage`)

**Contents**
- [Status](#status)
- [Goal](#goal)
- [The real complexity: per-core rules out the obvious source, and the real source needs delta math](#the-real-complexity-per-core-rules-out-the-obvious-source-and-the-real-source-needs-delta-math)
- [Three options, and why C was chosen after real testing](#three-options-and-why-c-was-chosen-after-real-testing)
- [JEA function](#jea-function)
- [Proxy side](#proxy-side)
- [`WinrmPoller.php` side: `Processor`, but a genuinely different code path than `Storage`/`Mempool`](#winrmpollerphp-side-processor-but-a-genuinely-different-code-path-than-storagemempool)
- [Layout](#layout)
- [Next step](#next-step)
- [Deploying this check](#deploying-this-check)

<a id="status"></a>

## Status
**Confirmed working end-to-end against the real target** (2026-08-10) — `WinrmPoller.php` populating four real per-core `Processor` rows via `device:discover`/`device:poll` on `lnms-poller.vpp.local`:

```
CPU 0: 2%   CPU 1: 0%   CPU 2: 0%   CPU 3: 2%
```

A second `device:poll` confirmed the update path: still exactly 4 rows (no duplicates), 17 RRD updates and 0 creates (vs. 4 creates on the first poll). `phpstan analyse` clean (after fixing one real type mismatch found by it, see below), `php -l` clean, existing `WinrmPollerTest.php` (applicability logic, untouched) still 6/6.

<a id="goal"></a>

## Goal
Per-core busy/idle CPU usage — used for debugging load issues, where an aggregate-only number isn't good enough. User/kernel time split explicitly not needed.

<a id="the-real-complexity-per-core-rules-out-the-obvious-source-and-the-real-source-needs-delta-math"></a>

## The real complexity: per-core rules out the obvious source, and the real source needs delta math

`Win32_Processor.LoadPercentage` is one aggregate number **per socket**, not per core — can't be decomposed after the fact. Per-core data lives in `Win32_PerfRawData_PerfOS_Processor` (one instance per logical core, plus a `_Total` aggregate) — but this is a fundamentally different counter type than `network-traffic`'s simple monotonic byte counters. `PercentProcessorTime`/`PercentIdleTime` are a `PERF_100NSEC_TIMER` counter: a raw cumulative value that only becomes a meaningful percentage when diffed against a second sample and the elapsed time between them (`Timestamp_Sys100NS`). There's no way to get a correct value from a single raw read.

<a id="three-options-and-why-c-was-chosen-after-real-testing"></a>

## Three options, and why C was chosen after real testing

- **A — sample twice inside the JEA function, return computed percentages.** Self-contained, ~1s added latency.
- **B — return raw counters, delta math in `WinrmPoller.php` between polls.** Avoids latency, but introduces cross-poll state (a real architectural first — nothing else in this module persists state between poll cycles) and real risk of getting the `PERF_100NSEC_TIMER` formula subtly wrong.
- **C — `Get-Counter`'s cooked `\Processor(*)\% Processor Time`.** Computes the percentage internally, no manual formula.

**Chose C, after real testing confirmed both open questions favorably — not assumed from precedent:**
1. **Reachability inside JEA.** `Get-Counter` lives in `Microsoft.PowerShell.Diagnostics`, the same module `Get-WinEvent` turned out to live in for `winupdate-pending` v2. Confirmed directly (not just inferred from that precedent) by deploying `Get-CpuUsageStatus` into the real JEA whitelist and calling it through the actual constrained path (`svc-winrmproxy`/Kerberos), not just via the unconstrained `debugadmin` session used for initial testing — same "deploy via one identity, verify via the other" discipline as every prior check.
2. **Since-boot-average trap.** `Get-Counter` with a single sample can return a cumulative since-boot average instead of a live reading if used carelessly — the exact bug category that hit the `zpool iostat` check (missing `-y 2 1`). Explicitly tested for: two `-SampleInterval 1 -MaxSamples 1` calls 3 seconds apart returned genuinely different per-core values (e.g. core 1 dropped from 1.5% to 0%), ruling out a frozen cumulative average.

C avoids A's formula-reimplementation risk and B's cross-poll-state risk entirely, at the same ~1s latency cost as A — once both risks were confirmed absent by real testing, not a coin flip between comparable options.

<a id="jea-function"></a>

## JEA function

```powershell
function Get-CpuUsageStatus {
    [CmdletBinding()]
    param()

    $counters = Get-Counter -Counter '\Processor(*)\% Processor Time' -SampleInterval 1 -MaxSamples 1

    $cores = @(
        $counters.CounterSamples | Where-Object { $_.InstanceName -ne '_total' } | ForEach-Object {
            [pscustomobject]@{
                CoreIndex   = $_.InstanceName
                BusyPercent = [math]::Round($_.CookedValue, 2)
            }
        }
    )
    ConvertTo-Json -InputObject $cores -Compress
}
```

`_total` (the aggregate `Get-Counter` also returns) filtered out at the source — per-core rows only, matching the explicit "per-core required" scope. `@(...)` wrapping applied proactively (same `disk-space`/`network-traffic`/`memory-usage` lesson), not rediscovered.

<a id="proxy-side"></a>

## Proxy side

`app/checks/cpu_usage.py` (`alexh/librenms-bits`) invokes `Get-CpuUsageStatus`, validates each core entry, returns `{"cores": [{"core_index": ..., "busy_percent": ...}, ...]}`. Registered in `registry.py` as `"cpu-usage"`. 12 tests, full proxy suite 117/117 passing.

<a id="winrmpollerphp-side-processor-but-a-genuinely-different-code-path-than-storagemempool"></a>

## `WinrmPoller.php` side: `Processor`, but a genuinely different code path than `Storage`/`Mempool`

Unlike `Storage`/`Mempool`, **`Processor` has no modern class-based `LibreNMS\Modules\*` counterpart to mirror**. The real SNMP-based discover/poll logic lives in the legacy `LibreNMS\Device\Processor` static class (`LibreNMS\Model`-based, driven from `includes/discovery/polling/processors.inc.php`), not an Eloquent/`Keyable`/`SyncsModels` pattern. Confirmed by reading that class's actual source before assuming otherwise, same discipline every other check got.

**A real gotcha found by reading the source, not assumed:** `App\Models\Processor` is a bare Eloquent model with no `$fillable` override, which means it inherits Eloquent's default `$guarded = ['*']` (confirmed against `vendor/.../GuardsAttributes.php`). Mass assignment (`new Processor([...])`, `updateOrCreate()`) would **silently discard every attribute** rather than throw — a real silent-data-loss risk, not a hypothetical one, since this codebase doesn't enable Laravel's `preventSilentlyDiscardingAttributes()` strict mode. Every write in this module uses **direct property assignment** instead (`$processor->device_id = ...`), which always works regardless of guarding — the same technique `LibreNMS\Device\Processor::discover()` itself uses, for the same reason.

**No `Keyable`/`SyncsModels` support either** — `fetchCpuCores()` returns raw validated data (not built model instances), and `discover()`/`pollCpuUsage()` each do their own manual upsert-by-hand, matching `fetchNetworkInterfaces()`'s `Port` handling (same no-`Keyable` situation) rather than `fetchDiskStorageModels()`/`fetchMemoryMempoolModels()`'s `syncModels()`-based pattern.

**A real type bug phpstan caught, worth recording:** the first version wrote `$processor->processor_usage = round($core['busy_percent'], 2)` — a float — directly to a property phpstan flagged as declared `int`. `LibreNMS\Device\Processor::poll()`'s own code does the analogous `round($data, 2)` and writes it via a raw `dbUpdate()` array, which bypasses Eloquent's typed-property checking entirely (a plain array has no static type) — not evidence that the column is meant to hold fractional precision, just a difference in write mechanism. Fixed by keeping the float for the RRD write (full precision) and explicitly `(int) round(...)`-ing only the value assigned to the Eloquent property.

**Composite identity**: `(device_id, processor_type='winrm', processor_index)`, `processor_index` being the raw core number string (`"0"`, `"1"`, ...) `Get-Counter`'s `InstanceName` already provides. Same caveat as drive letters/interface names/page files: not guaranteed stable across a vCPU count change, not worth solving unless it's ever a real problem. `processor_oid` (a real `NOT NULL` schema column with no applicable real OID) uses the same non-real-but-recognizable-string convention this module's `sensor_oid` fields already use (`winrm.processor.<index>`).

`cleanup()`/`dataExists()`/`dump()` extended to cover `Processor` rows (`processor_type = 'winrm'`) alongside `Sensor`/`Storage`/`Port`/`Mempool`.

<a id="layout"></a>

## Layout

Same confirmation as `memory-usage`: `overview/processors.inc.php` queries `processors` directly with zero module/poller_type gating, and `overview.inc.php`'s panel order places Processors first in the right pane — populating the real `Processor` model gets this check the same UI position a Linux node's CPU panel gets, automatically.

<a id="next-step"></a>

## Next step

None outstanding for this check — fully built, tested, and verified end-to-end.

<a id="deploying-this-check"></a>

## Deploying this check

Incremental add — assumes `WINRM_JEA_SETUP.md`'s first-time setup already ran, plus `reboot-pending`, the two `service-status-*` checks, `winupdate-pending`, `disk-space`, `network-traffic`, and `memory-usage` (see those docs). Adds `Get-CpuUsageStatus` and its `.psrc` whitelist entry — this was the first real test of whether `Get-Counter` is reachable inside a `RestrictedRemoteServer` session at all (module auto-loading is disabled, the same category of risk that made `Get-CimInstance`/`Get-NetAdapter` unreachable elsewhere) — it worked. `.psrc`/`.psm1`-only — no `.pssc` change, no WinRM restart (see `WINRM_JEA_SETUP.md` §5).

```powershell
# Update-WinrmProbeJEA-CpuUsage.ps1
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

function Get-CpuUsageStatus {
    [CmdletBinding()]
    param()

    $counters = Get-Counter -Counter '\Processor(*)\% Processor Time' -SampleInterval 1 -MaxSamples 1

    $cores = @(
        $counters.CounterSamples | Where-Object { $_.InstanceName -ne '_total' } | ForEach-Object {
            [pscustomobject]@{
                CoreIndex   = $_.InstanceName
                BusyPercent = [math]::Round($_.CookedValue, 2)
            }
        }
    )
    ConvertTo-Json -InputObject $cores -Compress
}

Export-ModuleMember -Function Get-RebootPendingStatus, Get-WuauservStatus, Get-W32timeStatus, Get-PendingUpdateStatus, Get-LocalDiskSpace, Get-NetworkInterfaceStats, Get-MemoryUsageStatus, Get-CpuUsageStatus
'@ | Set-Content -Path (Join-Path $moduleRoot 'WinrmProbeJEA.psm1') -Encoding UTF8

@'
@{
    Author = 'Your Name'
    CompanyName = 'VPP'
    Description = 'Constrained check functions exposed to the WinRM proxy service account.'

    VisibleFunctions = @('Get-RebootPendingStatus', 'Get-WuauservStatus', 'Get-W32timeStatus', 'Get-PendingUpdateStatus', 'Get-LocalDiskSpace', 'Get-NetworkInterfaceStats', 'Get-MemoryUsageStatus', 'Get-CpuUsageStatus')
    VisibleCmdlets = @()
    VisibleAliases = @()
    VisibleExternalCommands = @()
    VisibleProviders = @()
}
'@ | Set-Content -Path (Join-Path $roleCapDir 'WinrmProbe.psrc') -Encoding UTF8

Write-Host "Done, no WinRM restart performed. Validate with a NEW session:"
Write-Host "  Enter-PSSession -ComputerName $env:COMPUTERNAME -ConfigurationName WinrmProbe"
Write-Host "  Get-CpuUsageStatus   # expect a JSON array, one entry per logical core"
```

Then run `WINRM_JEA_SETUP.md` §7's validation checklist to confirm the constraint still holds.
