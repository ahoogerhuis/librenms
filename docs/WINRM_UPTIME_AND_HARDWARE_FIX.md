# Fix — Uptime and Hardware Overview-Page Gaps Closed

**Contents**
- [Status](#status)
- [Scope, as narrowed by the handoff](#scope-as-narrowed-by-the-handoff)
- [Uptime: placement corrected by checking the real precedent, not following the handoff's own suggestion](#uptime-placement-corrected-by-checking-the-real-precedent-not-following-the-handoffs-own-suggestion)
- [Hardware: the field's real meaning, and its real source, both confirmed by testing](#hardware-the-fields-real-meaning-and-its-real-source-both-confirmed-by-testing)
- [Proxy side](#proxy-side)
- [Next step](#next-step)

<a id="status"></a>

## Status
**Confirmed working end-to-end against the real target** (2026-08-10). `Device.hardware = "Intel x64"` (set at discover time), `Device.uptime` correctly computed and increasing across successive polls (`176523` → `176553` seconds, ~2 days, matching the real boot time), `uptime.rrd` created with `ds[uptime].type = "GAUGE"`. `phpstan`/`php -l` clean, 6/6 applicability tests, all 9 checks green in a final sweep.

<a id="scope-as-narrowed-by-the-handoff"></a>

## Scope, as narrowed by the handoff
Originally four fields (uptime, contact, location, hardware). Contact/location confirmed out of scope — admin-entered data on any device regardless of polling method (the `[lat,long]` format matches LibreNMS's manual Location-assignment feature, not polled device state), not a WinRM code gap. This fix covers the two genuine technical gaps: uptime and hardware.

<a id="uptime-placement-corrected-by-checking-the-real-precedent-not-following-the-handoffs-own-suggestion"></a>

## Uptime: placement corrected by checking the real precedent, not following the handoff's own suggestion

The handoff suggested the same `hardware-inventory`/discover-only placement as everything else, but flagged it explicitly for verification ("Apply the match the SNMP shape convention here too"). Reading the real mechanism — `LibreNMS\Modules\Core::calculateUptime()` — showed that suggestion was wrong for this specific field: uptime handling lives entirely in `Core::poll()`, never `discover()` (`Core::discover()` only handles `sysObjectID`/`sysName`/`sysDescr`/OS detection). Uptime is inherently dynamic; treating it as discover-only static data the way OS version/hardware are handled would be wrong.

`Core::calculateUptime()`'s real behavior, replicated directly:
- Writes a dedicated `uptime` RRD (`GAUGE`, `addDataset('uptime', 'GAUGE', 0)`).
- Calls `$os->enableGraph('uptime')`.
- **Detects reboots**: logs `Eventlog::log('Device rebooted after ...', ..., 'reboot', Severity::Warning, ...)` when the newly-computed uptime is *less than* the device's previous stored uptime — real, useful monitoring value, cheap to include since the raw value is already available every poll.

**Source**: `Win32_OperatingSystem.LastBootUpTime`, added to `memory-usage`'s existing JEA function (`Get-MemoryUsageStatus`) rather than `hardware-inventory` — `memory-usage` already queries `Win32_OperatingSystem` every poll cycle (the same cadence uptime needs), so this is one more field on an existing per-poll WMI call, not a new WinRM round-trip.

**A real bug caught by testing before it shipped**: `[System.Management.ManagementDateTimeConverter]::ToDateTime()` returns a `DateTime` with `Kind = Unspecified` (confirmed directly, not assumed). A naive `.ToString('o')` on that value **omits the UTC offset entirely** — PHP parsing that string would silently treat it as UTC when it's actually the target's local time, producing an uptime wrong by however many hours the target's timezone differs from UTC (7 hours on the Pacific-time test target — confirmed: `2026-08-08T07:18:51.9044430` naive vs. `2026-08-08T14:18:51.9044430Z` after `.ToUniversalTime()`, which correctly accounts for DST). Fixed by calling `.ToUniversalTime().ToString('o')` before ever leaving the JEA function — the proxy and `WinrmPoller.php` only ever see an unambiguous, correctly-offset UTC string.

**Avoiding a duplicate WinRM round-trip**: `pollMemoryUsage()` previously called `fetchMemoryMempoolModels()`, which did its own `proxy->check()` call internally. Refactored into `fetchMemoryUsageRaw()` (fetch + top-level validation, returns the raw array) and `buildMemoryMempoolModels()` (pure builder, no proxy access) — `pollMemoryUsage()` now fetches once per poll and feeds the same raw data to both the mempool-update path and the new `updateDeviceUptime()` call, rather than fetching the same check twice every cycle. Matches this project's established carefulness about proxy load (see `WINRM_PROXY_CONCURRENCY.md`, `librenms-bits`).

<a id="hardware-the-fields-real-meaning-and-its-real-source-both-confirmed-by-testing"></a>

## Hardware: the field's real meaning, and its real source, both confirmed by testing

**Content** (what the field should hold) was already known from the OS-version fix's research: `LibreNMS\OS\Windows::parseHardware()` uses `Device.hardware` for **CPU architecture** (`"AMD x64"`/`"Intel x64"`/`"Generic x86"`/`"Intel Itanium IA64"`), not machine model/manufacturer — the handoff explicitly flagged not to rebuild against the original (wrong) `Win32_ComputerSystem.Model`/`Manufacturer` guess.

**Source** (how to reconstruct it from WinRM/WMI) needed its own real testing, per the handoff's instruction not to assume the two already-available fields (`Win32_Processor.Manufacturer`, `Win32_OperatingSystem.OSArchitecture`) combine cleanly. Tested directly against the real target and found something better: the registry value `HKLM:\HARDWARE\DESCRIPTION\System\CentralProcessor\0\Identifier` (e.g. `"Intel64 Family 15 Model 107 Stepping 1"`) is **the exact source format** `parseHardware()`'s regex parses out of `sysDescr` — confirmed character-for-character, not approximated. `parseCpuArchitecture()` in `WinrmPoller.php` is a direct port of `parseHardware()`'s regex and lookup table (`AMD64`/`Intel64`/`EM64T`/`x86`/`ia64` → the four output strings), reading from this registry value rather than reconstructing the token from `Manufacturer`+`OSArchitecture` — same source data, same mapping, not an approximation that would need separately verifying it produces byte-identical results (e.g. distinguishing `EM64T`-era vs `Intel64`-era Intel CPUs, a distinction `Manufacturer` alone can't make, though it turns out not to matter since both map to the same `"Intel x64"` output anyway).

Added to `hardware-inventory` (discover-only, matching this field's genuinely static nature) as a new root-level `ProcessorIdentifier` field — same registry-read mechanism `reboot-pending`/the OS-version `ReleaseId` fix already use, no new reachability question.

<a id="proxy-side"></a>

## Proxy side

- `app/checks/memory_usage.py`: `LastBootUpTime` (string|null) added alongside the existing fields. 4 new tests, 17 total for this check.
- `app/checks/hardware_inventory.py`: `ProcessorIdentifier` (string|null, root-level, genuinely optional) added. 3 new tests, 22 total for this check.
- Full proxy suite: 146/146 passing.

<a id="next-step"></a>

## Next step

None outstanding for this fix — fully built, tested, and verified end-to-end.
