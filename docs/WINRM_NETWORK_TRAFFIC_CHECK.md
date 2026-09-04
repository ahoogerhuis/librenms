# WinRM Check — Network Interface Traffic (`network-traffic`)

**Contents**
- [Status](#status)
- [Goal](#goal)
- [Why this check is architecturally different from everything before it](#why-this-check-is-architecturally-different-from-everything-before-it)
- [Sensor-shape decision: dynamic count, static type — not a `Storage` rebuild](#sensor-shape-decision-dynamic-count-static-type-not-a-storage-rebuild)
- [JEA function](#jea-function)
- [Proxy side](#proxy-side)
- [`WinrmPoller.php` side](#winrmpollerphp-side)
- [Open items — genuinely unverified, not assumed](#open-items-genuinely-unverified-not-assumed)
- [TODO: verify multi-NIC behavior — untested so far](#todo-verify-multi-nic-behavior-untested-so-far)
- [TODO: option 2, a `sensor_pair` graph type (deferred, not chosen)](#todo-option-2-a-sensorpair-graph-type-deferred-not-chosen)
- [Sensor `rrd_type`: GAUGE, not DERIVE (2026-08-10)](#sensor-rrdtype-gauge-not-derive-2026-08-10)
- [Sensor pair removed entirely (2026-08-10)](#sensor-pair-removed-entirely-2026-08-10)
- [Explicitly out of scope for this pass](#explicitly-out-of-scope-for-this-pass)
- [Deploying this check](#deploying-this-check)

<a id="status"></a>

## Status
**Confirmed working end-to-end against the real target** (2026-08-10), first attempt, no bugs — a first for this project's checks (every prior check needed at least one real-target fix). Real `Sensor` rows via `device:discover`/`device:poll` on `lnms-poller.vpp.local`: `sensor_current` 42064529 (sent) / 41479388 (received), consistent ordering with every earlier direct-API reading. Also proved the JEA whitelist constraint holds (`Get-NetworkInterfaceStats` works; `Get-Process` and `Get-NetAdapter` — the `NetAdapter` module, flagged as high-risk in the original handoff — both correctly fail as "not recognized," confirming that specific risk was real and correctly anticipated).

**The `Sensor` pair described throughout this doc was removed entirely on 2026-08-10 — see "Sensor pair removed" near the end.** Everything below this point (the `Sensor`-shape design decision, `rrd_type: DERIVE` then `GAUGE`, `sensorTypes()`/`NETWORK_TRAFFIC_SENSOR_TYPES`) describes the *original* design and is kept as history, not the current state. The check now populates only the real `Port` row.

All three open items below are now resolved by real testing:
1. **`Win32_PerfRawData_Tcpip_NetworkInterface` is reachable** via `Get-WmiObject`, confirmed for real.
2. **Interface identity**: the real target's adapter reports as `Red Hat VirtIO Ethernet Adapter` — a clean, stable name in this case (a VirtIO adapter under KVM/QEMU), not the heavily-mangled kind real physical NIC drivers sometimes produce. Still worth re-confirming on physical hardware before assuming this is universally clean.
3. **Counters increase correctly, live**: two consecutive real API calls showed `bytes_sent`/`bytes_received` both genuinely increasing (e.g. +21099/+26844 bytes between calls a fraction of a second apart) — organic confirmation these are live, monotonically increasing counters, not cached/static values. Counter bit-width/rollover behavior itself remains unverified (would need a very long observation window or a synthetic test to actually see), noted as a standing, low-urgency gap.

<a id="goal"></a>

## Goal
Per-interface bytes-sent/bytes-received, analogous to SNMP's `ifInOctets`/`ifOutOctets`-based interface graphs.

<a id="why-this-check-is-architecturally-different-from-everything-before-it"></a>

## Why this check is architecturally different from everything before it

Every prior check is a **point-in-time snapshot** — ask a question, get a current value. Network traffic isn't: bytes-sent/received are **cumulative counters**, and the useful number (throughput) is a **rate**, computed from a delta over time. This needed real research before building, not assumption — two questions in particular:

**1. Does LibreNMS compute rates in PHP, or let RRD do it?** Read the actual legacy interface poller (`includes/polling/ports.inc.php` — there's no modern `Ports` `Module` class, unlike `Storage`; port/interface polling is legacy, `ifIndex`-keyed, and far too SNMP-specific to hook into directly, so this check does **not** try to integrate with the `ports` table at all). Confirmed: `RrdDefinition::make()->addDataset('INOCTETS', 'DERIVE', 0, 12500000000)` — LibreNMS writes **raw cumulative counter values** into RRD as `DERIVE` type and lets RRDtool compute the rate. No manual delta math in PHP.

**2. Does `Sensor` (this module's existing pattern) support the same thing?** Yes — `Sensor.rrd_type` is a real column (`GAUGE`/`COUNTER`/`DERIVE`/`DCOUNTER`/`DDERIVE`), and `record_sensor_data()` already threads it into the RRD dataset type (`RrdDefinition::make()->addDataset('sensor', $sensor['rrd_type'])`). `Sensor::formatValue()` also already knows how to display an approximate rate for these types (`(sensor_current - sensor_prev) / rrd_step`). This means `network-traffic` doesn't need a `Storage`-style rebuild the way `disk-space` did — it fits the existing `Sensor` pattern, just with `rrd_type = 'DERIVE'` set explicitly (the other four checks rely on the column's `GAUGE` default, unset).

<a id="sensor-shape-decision-dynamic-count-static-type-not-a-storage-rebuild"></a>

## Sensor-shape decision: dynamic count, static type — not a `Storage` rebuild

`disk-space` needed `Storage` because the *set of sensor types* wasn't fixed data — but network interfaces don't have that problem the same way. `App\Discovery\Sensor` (`app('sensor-discovery')`, already used by every other check in this module) is a **generic dynamic-discovery mechanism** — it was never actually tied to a fixed set of checks, that's just how it had been used so far. Two fixed `sensor_type` values (known ahead of time, like every other check):

- `winrm-network-traffic-bytes-sent`
- `winrm-network-traffic-bytes-received`

What varies per host is `sensor_index` (the interface name) — the *count* of sensors is dynamic, not which type applies. This means `sensorTypes()` (used by `dataExists()`/`cleanup()`/`dump()`) needed exactly one change — merging in the two static type strings — and those three methods needed **no other changes**, unlike `disk-space` which needed its own `Storage`-specific handling in each. Discovered sensors are staged *before* the existing `sync(sensor_class: 'count', ...)` call runs, so they land in the same sync pass `winupdate-pending`'s sensor already uses — no new sync call needed, staging order is what matters.

<a id="jea-function"></a>

## JEA function

```powershell
function Get-NetworkInterfaceStats {
    [CmdletBinding()]
    param()  # deliberately no parameters

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
```

**Current version, including the `ifOperStatus`/`ifAdminStatus` port-status fix** (see "Explicitly out of scope for this pass" below, and `WINRM_NETWORK_TRAFFIC_PORT_STATUS_FIX.md` for the full fix writeup) — correlates each `Win32_PerfRawData_Tcpip_NetworkInterface` entry to a `Win32_NetworkAdapter` by exact `Name`-string match, confirmed directly against the real target that the two classes' `Name` fields match exactly (not `Win32_NetworkAdapter.NetConnectionID`, a different, more human-friendly field that doesn't line up). `Win32_NetworkAdapter` was already confirmed JEA-reachable via `hardware-inventory`, so this added zero new reachability question.

**Reused the `disk-space` findings from the start, not rediscovered:** `Get-WmiObject`, not `Get-CimInstance` (the latter isn't reachable inside JEA's `RestrictedRemoteServer` at all — module auto-loading is disabled); `@(...)` + `-InputObject` binding, not `-AsArray`/piping (`-AsArray` doesn't exist on Windows PowerShell 5.1). Both confirmed against the real target during the `disk-space` build; applied here on the reasoning that carried over, not re-verified from scratch — genuine risk reduction from having found these once already, but the class-specific unknowns below still need real confirmation.

**The `...Persec` property names are misleading.** `Win32_PerfRawData_*` classes expose the *raw* PerfLib counter value — for a `PERF_COUNTER_COUNTER`-type counter like this one, that's a monotonically increasing cumulative count (barring rollover), not a live per-second rate. Deliberately used `PerfRawData`, not `PerfFormattedData` — reporting the raw counter and letting RRD's `DERIVE` type compute the rate matches every other check's "report raw values, no math here" convention (`disk-space` reports raw size/free bytes, not a computed percentage; `winupdate-pending` reports raw counts, not a computed trend).

<a id="proxy-side"></a>

## Proxy side

`app/checks/network_traffic.py` (`alexh/librenms-bits`) invokes `Get-NetworkInterfaceStats`, validates each interface entry (name non-empty string, both byte counts integers), and returns `{"interfaces": [{"interface_name": ..., "bytes_sent": ..., "bytes_received": ...}, ...]}` — same list-not-scalar shape as `disk-space`, including the same defensive `isinstance(data, list)` guard against the `-AsArray`-style collapse-to-object gotcha. 11 tests (`tests/test_checks_network_traffic.py`), alongside the existing 42 for the other four checks (53/53 passing).

<a id="winrmpollerphp-side"></a>

## `WinrmPoller.php` side

`fetchNetworkInterfaces()` runs the check and validates the result — shared by `discover()` and `poll()`, same split as `fetchDiskStorageModels()`. `discover()` stages two `Sensor` rows per interface (`rrd_type: 'DERIVE'` set explicitly); `poll()` only updates values on already-discovered sensor pairs, same discover-handles-topology/poll-handles-values split `disk-space`/`Storage` use — an interface appearing in a poll result without a matching discovered sensor pair (added since the last discover cycle) is logged and skipped, not silently created mid-poll.

<a id="open-items-genuinely-unverified-not-assumed"></a>

## Open items — genuinely unverified, not assumed

1. **Interface identity (`.Name`) stability.** `Win32_PerfRawData_Tcpip_NetworkInterface.Name` is typically derived from the adapter's driver description with special characters replaced by underscores — not guaranteed to be a clean, short, predictable name, and not guaranteed stable across a NIC replacement or driver update. Same category of concern `disk-space` had with drive letters (a `sensor_index` change on next discovery reads as "interface removed, new interface added," not "renamed") — worth confirming the real value against the actual target rather than assumed, and revisiting if it turns out to be unstable in practice.
2. **Counter width / rollover.** Unlike `ports.inc.php`'s manual `RrdDefinition` (which bounds `DERIVE` to `[0, 12500000000]`, clipping a rollover-induced negative rate to "unknown" rather than a corrupt spike), `record_sensor_data()`'s generic `RrdDefinition::make()->addDataset('sensor', $sensor['rrd_type'])` sets no such bound. A counter rollover here would produce one bad data point rather than a gracefully-clipped gap. Not fixed in this pass — genuinely raw counters at whatever bit-width WMI reports them as, unverified against the real target. Worth checking the actual value's width during real testing (64-bit counters make rollover rare enough in practice not to matter much; 32-bit ones would wrap roughly every ~34s at 1Gbps sustained, which would matter a lot).
3. **Whether `Win32_PerfRawData_Tcpip_NetworkInterface` is even populated/reachable at all inside JEA** — reasoned to be likely (same always-loaded module, same style of classic CIMV2 WMI class as `Win32_LogicalDisk`), not confirmed. First real deployment is the actual test.

<a id="todo-verify-multi-nic-behavior-untested-so-far"></a>

## TODO: verify multi-NIC behavior — untested so far

Every real-target test so far (Sensor pair and the `ports`-table integration below) has been against `winrm-test01.vpp.local`, which has exactly one network adapter (`Red Hat VirtIO Ethernet Adapter`). Needs real verification once a multi-NIC target exists:
- Does the device-overview combined graph correctly sum all interfaces (expected, per how `includes/html/graphs/device/bits.inc.php` builds its port list — but not actually observed with more than one).
- Whether the synthetic-ifIndex hash (`crc32` of interface name, see `NETWORK_TRAFFIC_SYNTHETIC_IFINDEX_BASE`) can practically collide between two different real interface names on the same host — astronomically unlikely for a handful of interfaces, not actually exercised.
- Whether `discover()`/`poll()`'s per-interface loops behave correctly with more than one Sensor pair / `Port` row per device (the code is written generically or this, but only ever run against N=1).

<a id="todo-option-2-a-sensorpair-graph-type-deferred-not-chosen"></a>

## TODO: option 2, a `sensor_pair` graph type (deferred, not chosen)

Researched 2026-08-10: the device-overview combined bytes-in/out graph (`device_bits`/`port_bits`) is rigidly `ports`-table/`port_id`-keyed at every layer, including LibreNMS's own two-interface-combining graph type (`multiport/bits_duo`) — there's no existing mechanism to pair two arbitrary `sensors` rows into one combined graph. The underlying RRDtool-drawing code (`includes/html/graphs/generic_data.inc.php`) is genuinely data-source-agnostic (just wants two RRD paths + two DS names), so a new `includes/html/graphs/sensor_pair/` graph type reusing it, plus a small overview-panel entry, is a real, buildable option — no core files need changing. **Deliberately not chosen for `network-traffic`** (populating the real `ports` table instead — see below), but worth remembering as the lower-invasiveness alternative if a future check wants a combined-graph UI without touching `ports`.

<a id="sensor-rrdtype-gauge-not-derive-2026-08-10"></a>

## Sensor `rrd_type`: GAUGE, not DERIVE (2026-08-10)

**Reported symptom**: the device overview's "Count" panel showed `Red Hat VirtIO Ethernet Adapter bytes received: 525` / `bytes sent: 312` for a host with clearly sustained multi-kbps traffic for hours — looked implausibly small for a lifetime total.

**Investigation, in order, each tested for real rather than assumed:**
1. **Does the WMI raw counter actually behave as a monotonic cumulative counter?** Sampled `Win32_PerfRawData_Tcpip_NetworkInterface.BytesSentPersec` twice, 95 seconds apart, via the real `debugadmin`/`pypsrp` path: `56352468` → `56404496` (+52,028, ≈548/s) — genuinely increasing, in the tens-of-millions range, consistent with the OS's own live cooked `Get-Counter '\Network Interface(*)\Bytes Total/sec'` reading (5,482 B/s) taken at the same moment. **Confirmed monotonic and correct.**
2. **Recent reset (VM reboot or NIC reset)?** `Win32_OperatingSystem.LastBootUpTime` showed ~1d23h uptime; the NIC's own `TimeOfLastReset` exactly matched boot time (no separate adapter-level reset). **Ruled out.**
3. **Pipeline bug between the JEA function and the DB?** Called the real proxy `/check` endpoint directly (`bytes_sent=56428934`) and queried `sensors.sensor_current` in the DB moments later (`56301094`/`56464235`, same interface) — same order of magnitude, correctly flowing through end-to-end. **No pipeline bug.**
4. **Does the widget actually display `sensor_current`?** Read `App\Models\Sensor::formatValue()` directly (`app/Models/Sensor.php:107-109`): for any sensor whose `rrd_type` is `COUNTER`/`DERIVE`/`DCOUNTER`/`DDERIVE`, it computes and displays `(sensor_current - sensor_prev) / rrd.step` instead of the raw value — deliberate, generic LibreNMS behavior for any rate-type sensor, not something this project's code does differently. Confirmed with real numbers at the time: `sensor_current=56464235`, `sensor_prev=56301094`, delta over 300s → **543.8 bytes/sec** — the same figure family as the reported "525". **This was it**: the panel was correctly computing and showing a 5-minute-average throughput rate, not a lifetime total.

A side investigation during this thread also ruled out a "the `...Persec` properties are already a rate, so DERIVE double-applies rate math" theory (a real, worth-checking possibility, prompted by a Microsoft docs page describing `BytesSentPerSec`/`BytesReceivedPerSec` as "Rate at which bytes are..."). That page documented `Win32_PerfFormattedData_Tcpip_NBTConnection` — wrong protocol layer (NetBIOS-over-TCP, not this check's `Tcpip_NetworkInterface`) *and* the pre-calculated **Formatted** class, not the **Raw** class this check actually queries. Confirmed directly against the correct class's own docs: `Win32_PerfFormattedData_Tcpip_NetworkInterface`'s page states it "derives its raw data from the corresponding raw class `Win32_PerfRawData_Tcpip_NetworkInterface`" (the one this check calls) and its `BytesSentPerSec` carries `CookingType("PERF_COUNTER_COUNTER")` — the standard type requiring two raw samples and elapsed time to "cook" into a rate. The Raw class this check queries is genuinely uncooked, exactly as originally documented in this file.

**So there was no data-correctness bug anywhere in the pipeline — but there was a real, valid product decision to make.** `sensor_descr` (this project's own generated label, `"<interface name> bytes received"`/`"...bytes sent"`) reads as an absolute total, not a rate, and that's the actual intent: **for this metric, the desired behavior is showing the real cumulative byte count, not an averaged rate** — a deliberate choice, confirmed directly with the user ("we want to see total bytes, not rate, if a machine has been up 2 years I don't care about rate since that has no significant value over such a timespan").

**The fix: `rrd_type: 'GAUGE'`, not `'DERIVE'`, for this sensor pair specifically.** `record_sensor_data()` (`includes/polling/functions.inc.php:154`) uses the exact same `$sensor['rrd_type']` value both to build the RRD dataset type *and* (via `formatValue()`) to decide whether to compute a rate for display — the two are coupled by design in that one shared, generic helper used by every `Sensor`-class check in the whole codebase. `GAUGE` stores the raw value with no rate computation, so `formatValue()` falls through to displaying `sensor_current` directly.

**This does not affect the actual traffic-rate graph** (the device overview's combined bits-in/out graph, `device_bits`/`port_bits`) — that's served by this module's own separate, explicit `Port`-table RRD write in `pollNetworkTraffic()` (`INOCTETS`/`OUTOCTETS`, `DERIVE`), completely independent of this `Sensor` row's `rrd_type`. Confirmed directly on the real target after the change: `port-id38.rrd` (the WinRM device's Port RRD) still has both datasets as `DERIVE`. Only this sensor pair's own small individual graph and the "Count" panel's current-value display are affected — both now show/plot the cumulative total instead of a computed rate.

**Operational note, not just a code change**: RRD files have their dataset type baked in at creation and RRDtool can't change it in place. Switching the code alone would leave the already-created `DERIVE` RRD files on disk silently mismatched with the new `GAUGE`-typed Sensor rows. The two existing per-sensor RRD files on the WinRM test device were deleted (`sensor-count-winrm-network-traffic-bytes-{sent,received}-Red_Hat_VirtIO_Ethernet_Adapter.rrd`) so `device:poll` recreated them fresh — confirmed via `rrdtool info` afterward that the regenerated files are genuinely `ds[sensor].type = "GAUGE"`. Any other already-discovered device with this check enabled needs the same file deletion + rediscover/repoll treatment when this change reaches it.

<a id="sensor-pair-removed-entirely-2026-08-10"></a>

## Sensor pair removed entirely (2026-08-10)

The `GAUGE`-vs-`DERIVE` investigation above led directly to a bigger question, raised independently by both the user and a "cousin desktop" handoff arriving moments apart: **does this check need a `Sensor` pair at all?**

**The check, done properly before acting on it:** does `Port`-table alerting exist independent of the generic `Sensor`/`sensor-limits` mechanism — the original stated reason for keeping the `Sensor` pair alongside the `Port` row? Read `resources/definitions/alert_rules.json` directly (not assumed): it ships real, default, built-in alert rule templates keyed entirely off `Port`-table macros — `"Port status up/down"` (`macros.port_down`), `"Port utilisation over threshold"` (`macros.port_usage_perc`, `macros.port_up`), and `"Port status change from up to down"` (`ports.ifOperStatus`/`ports.ifOperStatus_prev` directly). **Zero `Sensor` dependency, including for traffic-rate thresholding** — the strongest hypothetical case for needing one. The `ifOperStatus`/`ifAdminStatus` fix earlier the same day means this alerting path is already live for this device.

**So the original justification didn't hold, on both counts it named:**
- *"Health/Sensors-tab visibility"* — a real SNMP-based Linux node's interface traffic isn't shown there either; it's the Ports tab, which this check already populates correctly via the real `Port` row.
- *"`sensor-limits` alerting"* — redundant with the native, default, `Port`-table alert rules confirmed above.

**And structurally, no SNMP-based device gets a parallel `Sensor` entry for interface byte counters at all** — interface traffic is a `Port`-table-only concept everywhere else in LibreNMS. The `Sensor` pair was a WinRM-specific pattern with no real precedent, not a gap-filler for something SNMP devices have that WinRM devices lacked.

**Removed**: `NETWORK_TRAFFIC_SENSOR_TYPES`, the `discover()`-time `Sensor` staging loop, `pollNetworkTraffic()`'s `Sensor`-lookup/`record_sensor_data()` path, and the now-stale "insurance" `sync(sensor_class: 'count', ...)` call whose own comment specifically named network-traffic as the reason for its existence. `sensorTypes()` now returns only `checks()`'s fixed sensor types (network-traffic was the only check contributing to it dynamically). `pollNetworkTraffic()` now looks up existing `Port` rows once upfront (`winrmPortsQuery($device)->get()->keyBy('ifIndex')`), matching the single-upfront-query pattern `pollDiskSpace()`/`pollMemoryUsage()`/`pollCpuUsage()` already use, rather than the old per-interface `Sensor::query()->groupBy()` + per-interface `Port::query()->first()`.

**What this makes moot**: the entire `rrd_type`/`formatValue()` display-semantics question above no longer applies — there's no separate "Count" panel entry to format, since the traffic data only lives in the `Port` table now, exactly matching how every SNMP-based device already works. The formatting handoff that prompted this whole investigation thread is moot as a result.

**Cleanup performed on the WinRM test device** (`lnms-poller.vpp.local`, device 5), same "code change alone doesn't retroactively fix already-created data" pattern as the `GAUGE` fix: the two orphaned `Sensor` rows (`winrm-network-traffic-bytes-sent`/`-received`) were deleted directly, along with their now-unwritten RRD files. Any other already-discovered device with this check enabled will accumulate the same orphaned rows/files until manually cleaned up the same way — `cleanup()`/`dataExists()`/`dump()` no longer know about the old sensor types, so they won't be found or removed automatically.

<a id="explicitly-out-of-scope-for-this-pass"></a>

## Explicitly out of scope for this pass

- Packets, errors, discards — bytes in/out only for v1, matching the check's stated minimal goal. If ever added, matches the `Port`-only pattern established by this section, not a revived `Sensor` pair.
- No commitment to full SNMP-interface-graph parity (interface speed, description) — admin/oper status was added (see above), throughput/speed graphing beyond raw byte counters wasn't.

<a id="deploying-this-check"></a>

## Deploying this check

Incremental add — assumes `WINRM_JEA_SETUP.md`'s first-time setup already ran, plus `reboot-pending`, the two `service-status-*` checks, `winupdate-pending`, and `disk-space` (see those docs). Adds `Get-NetworkInterfaceStats` (current version, with the port-status fields) and its `.psrc` whitelist entry. `.psrc`/`.psm1`-only — no `.pssc` change, no WinRM restart (see `WINRM_JEA_SETUP.md` §5).

```powershell
# Update-WinrmProbeJEA-NetworkTraffic.ps1
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

Export-ModuleMember -Function Get-RebootPendingStatus, Get-WuauservStatus, Get-W32timeStatus, Get-PendingUpdateStatus, Get-LocalDiskSpace, Get-NetworkInterfaceStats
'@ | Set-Content -Path (Join-Path $moduleRoot 'WinrmProbeJEA.psm1') -Encoding UTF8

@'
@{
    Author = 'Your Name'
    CompanyName = 'VPP'
    Description = 'Constrained check functions exposed to the WinRM proxy service account.'

    VisibleFunctions = @('Get-RebootPendingStatus', 'Get-WuauservStatus', 'Get-W32timeStatus', 'Get-PendingUpdateStatus', 'Get-LocalDiskSpace', 'Get-NetworkInterfaceStats')
    VisibleCmdlets = @()
    VisibleAliases = @()
    VisibleExternalCommands = @()
    VisibleProviders = @()
}
'@ | Set-Content -Path (Join-Path $roleCapDir 'WinrmProbe.psrc') -Encoding UTF8

Write-Host "Done, no WinRM restart performed. Validate with a NEW session:"
Write-Host "  Enter-PSSession -ComputerName $env:COMPUTERNAME -ConfigurationName WinrmProbe"
Write-Host "  Get-NetworkInterfaceStats   # expect a JSON array, one entry per physical network adapter"
```

Then run `WINRM_JEA_SETUP.md` §7's validation checklist to confirm the constraint still holds.
