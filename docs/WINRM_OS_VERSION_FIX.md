# Fix — "Operating System" Line Now Shows Real Version/Build

**Contents**
- [Status](#status)
- [Revision 1: the first version got `version`/`features` backwards](#revision-1-the-first-version-got-versionfeatures-backwards)
- [Revision 2: `Multiprocessor`/`Uniprocessor` was keyed on the wrong processor count](#revision-2-multiprocessoruniprocessor-was-keyed-on-the-wrong-processor-count)
- [Placement: `hardware-inventory`, not a new check](#placement-hardware-inventory-not-a-new-check)
- [Field mapping (final)](#field-mapping-final)
- [Proxy side](#proxy-side)
- [Next step](#next-step)

<a id="status"></a>

## Status
**Confirmed working end-to-end against the real target** (2026-08-10), **revised twice same day** — see "Revision 1"/"Revision 2" below. Final rendered state, confirmed via direct DB query:

- Devices-list table: `Microsoft Windows` / `Server 2019 Standard (1809) (Multiprocessor)`
- Single-device overview panel: `Server 2019 Standard (1809) Multiprocessor`

Both unambiguously say **Server 2019**, matching the exact shape a real SNMP-monitored Windows device on the same instance shows (`Microsoft Windows Server 2019 Datacenter (1809) (Multiprocessor)`) — including the processor-count label, confirmed correct after Revision 2. Icon correctly re-resolves to `images/os/windows.svg`. Repeated `device:discover` runs after each revision confirmed the unchanged-value path is clean — no spurious "changed" log lines, `isDirty()` correctly gates the log call. `phpstan`/`php -l` clean, 6/6 applicability tests, all 9 checks green in a final sweep.

<a id="revision-1-the-first-version-got-versionfeatures-backwards"></a>

## Revision 1: the first version got `version`/`features` backwards

The first version set `Device.version = "10.0.17763"` (the raw NT kernel version) and `Device.features = "Windows Server 2019 Standard"` (the edition, with "Microsoft " stripped). Rendered as `Microsoft Windows` / `10.0.17763 (Windows Server 2019 Standard)` — the user asked "is it Windows 10 or 2019?", and rightly so: **`10.0.17763` is shared between Windows 10 version 1809 and Windows Server 2019** — Microsoft's server SKUs share their raw NT version number with the client release they're derived from, so a bare build number next to a generic "Microsoft Windows" label is genuinely ambiguous on its own, even though it isn't technically wrong.

The user then supplied the actual missing piece: what a **real SNMP-monitored Windows device on the same instance** shows — `Microsoft Windows Server 2019 Datacenter (1809) (Multiprocessor)`. This was the real precedent this project should have found and matched from the start, per its own "match the SNMP shape" convention — and hadn't, because the first pass only checked the generic `LibreNMS\Modules\Os` module (which handles generic field plumbing) and never checked for a Windows-*specific* driver, even though `Os::discover()` explicitly calls `$os->discoverOS($device)` — the exact per-OS-driver hook a Windows-specific class would implement.

**`LibreNMS\OS\Windows::discoverOS()` exists, and decodes the real string completely:**

```php
$device->hardware = $this->parseHardware($matches['hardware']);   // CPU arch, e.g. "AMD x64" -- NOT edition/model
$device->features = $matches['smp'] ?: null;                       // "Multiprocessor"/"Uniprocessor" -- an SMP-kernel descriptor
$device->version  = $this->getServerVersion($build);                // "Server 2019 Datacenter (1809)" -- edition AND release codename together
```

Parsed from Windows' own SNMP `sysDescr` string via regex, with `version` resolved through **build-number-keyed lookup tables** (`getClientVersion()`/`getServerVersion()`/`getDatacenterVersion()`, one entry per known Windows build) since raw SNMP data has no edition-name field to read directly.

**This inverts the original assumption:** every other OS type on this instance uses `features` for a distro/edition string (Linux: `"Debian 13.6"`) — Windows's real convention is the outlier, using `features` for an SMP-kernel descriptor instead, with the edition name folded into `version` alongside the release codename. Copying the generic pattern without checking the Windows-specific driver was exactly the mistake the project's own "match the SNMP shape" convention exists to prevent — caught here by the user's own knowledge of real Windows versioning, not by process.

<a id="revision-2-multiprocessoruniprocessor-was-keyed-on-the-wrong-processor-count"></a>

## Revision 2: `Multiprocessor`/`Uniprocessor` was keyed on the wrong processor count

Revision 1 used `Win32_ComputerSystem.NumberOfProcessors` (physical sockets — the test target has 1) to decide `Multiprocessor` vs `Uniprocessor`, reasoning the classic label was about physical CPU count. Rendered `"Uniprocessor"` for the test target, which has 4 logical processors. The user corrected this directly, pointing at real SNMP-monitored Windows devices on the same instance: hosts with multiple cores on a single socket show `"Multiprocessor"` there too.

The correction holds up technically, not just by observed precedent: Windows had genuinely separate uniprocessor (`ntoskrnl.exe`) and multiprocessor (`ntkrnlmp.exe`) kernel builds / HAL types up through early Windows Server releases — the historical source of this `sysDescr` wording — but these were unified into a single kernel starting with Windows Vista. In the modern single-kernel era, the label reflects however many logical processors (cores/threads across all sockets) the OS is actually scheduling across, not physical package count specifically. A single-socket, 4-core host is genuinely "multiprocessor" in the sense the OS itself now uses the word.

**Fix: switched to `Win32_ComputerSystem.NumberOfLogicalProcessors`.** Re-verified against the real target: `4` logical processors → `"Multiprocessor"`, matching the real SNMP precedent's own label for comparable hardware. Renamed throughout (JEA function output field, proxy check field, `WinrmPoller.php` variable) from `NumberOfProcessors`/`number_of_processors` to `NumberOfLogicalProcessors`/`number_of_logical_processors` for clarity, not left as a same-named-but-different-meaning trap.

<a id="placement-hardware-inventory-not-a-new-check"></a>

## Placement: `hardware-inventory`, not a new check

OS version/build is static-ish identity data (changes only across an actual OS upgrade) — the same refresh cadence `hardware-inventory` already uses (discover-only, no `poll()`). A few more `Get-WmiObject`/registry calls inside that already-multi-source function, not a new check.

<a id="field-mapping-final"></a>

## Field mapping (final)

- **`Device.version`** = `"<edition> (<release-codename>)"`, matching the real driver's shape exactly:
  - *Edition*: `Win32_OperatingSystem.Caption` (e.g. `"Microsoft Windows Server 2019 Standard"`) with the `"Microsoft Windows "` prefix stripped (both words — confirmed by reading `App\Http\Controllers\Table\DeviceController.php` directly: the devices-list view's `os_text` already shows `"Microsoft Windows"` separately, so leaving `"Windows"` in `version` would read as `"Microsoft Windows Windows Server..."`).
  - *Release codename* ("1809", "22H2", etc.): **not available from `Win32_OperatingSystem` at all** — sourced from the registry, `HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion`, `DisplayVersion` preferred over the older `ReleaseId` value name (`DisplayVersion` superseded `ReleaseId` starting around Windows 10 2004/20H2; `ReleaseId` is what a Server 2019 host like the test target actually has). Same registry-read mechanism `reboot-pending` already uses — no new reachability question.
  - **Deliberately does NOT copy the real driver's build-number lookup tables.** WMI's `Caption` already gives the exact edition string directly and authoritatively — no hardcoded table needing a code change for every future Windows release. Matches the real convention's *output shape* using a more robust *mechanism* than SNMP's `sysDescr`-regex-plus-lookup-table approach needs.
- **`Device.features`** = `"Multiprocessor"` if `Win32_ComputerSystem.NumberOfLogicalProcessors > 1`, else `"Uniprocessor"` — total logical processors (cores/threads across all sockets), **not** physical socket count (see "Revision 2" above for why the first attempt used the wrong field). The test target has 1 physical socket / 4 logical cores, correctly resolving to `"Multiprocessor"`.
- **`Device.hardware`** — still deliberately **not** touched, even though the real driver *does* use this field (for CPU architecture, "AMD x64"/"Intel x64" — not machine model, a genuine surprise found while reading the real precedent). A real, now well-understood follow-up, kept out of this fix to stay scoped to the "Operating System" line specifically.
- **`Device.icon`** — re-resolved via `Url::findOsImage()`, same as before. Still a no-op in practice for `os='windows'` (confirmed: the per-distro icon lookup only triggers for `os == 'linux'`).

<a id="proxy-side"></a>

## Proxy side

`app/checks/hardware_inventory.py`: `OperatingSystem` section extended with `ReleaseId` (string|null) and `NumberOfLogicalProcessors` (int|null) alongside the existing `Caption`/`Version`. 19 tests, full proxy suite 140/140 passing.

<a id="next-step"></a>

## Next step

None outstanding for this fix — fully built, tested, and verified end-to-end across three passes (wrong field mapping, then wrong processor-count semantics, then corrected against the real precedent both times). `Device.hardware` (CPU architecture, confirmed as the real convention's actual meaning for that field on Windows) is a named, deliberately deferred follow-up if ever wanted.
