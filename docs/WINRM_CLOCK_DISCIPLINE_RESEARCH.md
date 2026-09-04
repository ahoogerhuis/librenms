# Research — Does Windows Expose Genuine Clock-Discipline Data? (2026-08-12)

**Status: implemented, deployed, and verified end-to-end against real infrastructure (2026-08-12).** Research and model-decision passes below are unchanged as the historical record of how the design was reached; see "Implementation status" at the end for what was actually built, where the two flagged open items (PPM-vs-PPB unit, `offset` vs. `phase_offset` relationship) landed, and what was verified against real, live data.

**Contents**
- [The real precedent: `chronyd`](#the-real-precedent-chronyd)
- [Lead 3 (the winner): `Clock Frequency Adjustment (PPB)` performance counter](#lead-3-the-winner-clock-frequency-adjustment-ppb-performance-counter)
- [Lead 1: `GetSystemTimeAdjustment()` via P/Invoke — works, but coarser](#lead-1-getsystemtimeadjustment-via-pinvoke-works-but-coarser)
- [Lead 2: `w32tm /query /status /verbose` — some real new fields, one false lead](#lead-2-w32tm-query-status-verbose-some-real-new-fields-one-false-lead)
- [Lead 4: the event log — "Diagnostic" channel doesn't exist; "Operational" channel itself says to use counters instead](#lead-4-the-event-log-diagnostic-channel-doesnt-exist-operational-channel-itself-says-to-use-counters-instead)
- [Lead 5: registry config values — ruled out, confirmed not assumed](#lead-5-registry-config-values-ruled-out-confirmed-not-assumed)
- [JEA feasibility, tested directly, not assumed](#jea-feasibility-tested-directly-not-assumed)
- [Model research pass (2026-08-12): three questions resolved against the real `chronyd` source](#model-research-pass-2026-08-12-three-questions-resolved-against-the-real-chronyd-source)
- [What comes next](#what-comes-next)
- [Implementation status (2026-08-12)](#implementation-status-2026-08-12)

<a id="the-real-precedent-chronyd"></a>

## The real precedent: `chronyd`

Read `includes/polling/applications/chronyd.inc.php` and `includes/html/graphs/application/chronyd_frequency.inc.php` directly before starting any Windows-side investigation, per the explicit instruction not to repeat `ntp-sync-status`'s "iterate through wrong precedents" pattern.

`chronyd`'s real tracking dataset (`chronyc tracking`, JSON-app-sourced): `stratum`, `reference_time`, `system_time`, `last_offset`, `rms_offset`, **`frequency`**, **`residual_frequency`**, **`skew`**, `root_delay`, `root_dispersion`, `update_interval` — plus a second, richer *per-source* dataset (`frequency`, `frequency_skew`, `offset`, `stddev` per configured peer). The genuinely-discipline-specific fields, and their real graph labels (`chronyd_frequency.inc.php`):
```php
$array = [
    'frequency' => ['descr' => 'Error rate'],
    'residual_frequency' => ['descr' => 'Ref clk offset'],
    'skew' => ['descr' => 'Sys clk skew'],
];
```
`frequency` = the correction/drift rate chrony is currently applying, in PPM. `skew` = chrony's own estimate of how *stable* that rate is. This is the concrete shape "genuine discipline data" needs to resemble to be a real analog, not `ntpd`'s specific vocabulary — the goal restated by the handoff. Kept in mind throughout, not force-fit toward.

<a id="lead-3-the-winner-clock-frequency-adjustment-ppb-performance-counter"></a>

## Lead 3 (the winner): `Clock Frequency Adjustment (PPB)` performance counter

**Real, live, working, genuinely analogous to `chronyd`'s own `frequency` field — confirmed against the real target, not assumed.**

The earlier locale-independence investigation (during `ntp-sync-status`'s own build) sampled only two counters in the `Windows Time Service` category (`NTP Roundtrip Delay`, `Computed Time Offset`) for a different purpose and never enumerated the full set. Doing that now (`Get-Counter -ListSet 'Windows Time Service'`) surfaces two counters never checked before:
```
\Windows Time Service\Clock Frequency Adjustment (PPB)
\Windows Time Service\Clock Frequency Adjustment
```
`(PPB)` = parts-per-billion — the exact same physical concept as `chronyd`'s `frequency` (PPM), just a finer unit. The non-PPB sibling stayed at `0` throughout every test here; not pursued further once the PPB variant was confirmed live and working.

**Confirmed genuinely live, not static, via a real forced correction**: `w32tm /resync /rediscover`, then sampled the counter every 2 seconds:
```
5587 → 4469 → 3631 → 3073 → 2514  (PPB, decaying smoothly over ~10s)
```
A real step disturbance followed by a smooth, monotonic decay back toward a steady low value — exactly the shape a genuine frequency-discipline signal should have (analogous to `chronyd`'s own `frequency` converging after a correction). Before the forced resync, steady-state value was `0` — plausible (nothing to correct at that moment), not a red flag on its own: real values only appear when there's real drift to correct.

<a id="lead-1-getsystemtimeadjustment-via-pinvoke-works-but-coarser"></a>

## Lead 1: `GetSystemTimeAdjustment()` via P/Invoke — works, but coarser

**Real, live data too, but meaningfully lower resolution than the performance counter — confirmed by direct comparison, not assumed either way.**

```powershell
[DllImport("kernel32.dll", SetLastError=true)]
public static extern bool GetSystemTimeAdjustment(out uint lpTimeAdjustment, out uint lpTimeIncrement, out bool lpTimeAdjustmentDisabled);
```
Baseline (steady state): `TimeAdjustment == TimeIncrement == 156250` (both equal the same static 100ns-tick constant already ruled out elsewhere in this doc — this is `w32tm /verbose`'s own `ClockRate` value, confirmed identical). During a forced `/resync`, real live movement was observed: `disabled` flipped `True → False`, and `TimeAdjustment` moved to `156251` (delta of exactly `1`, i.e. one 100ns unit) for several seconds before settling back to `156250`/delta `0`. Real and dynamic — but the *entire* observed range across a real correction event was `0` or `1`, while the performance counter showed a smooth few-thousand-count decay over the same kind of event. The classic Win32 API appears to expose the same underlying phenomenon at much coarser granularity than the counter does — plausibly because modern (Server 2016+) W32Time's finer-grained correction logic, described in Microsoft's own docs as a `PhaseCorrectRate`/`SystemClockRate`-based slewing formula, isn't fully represented through this older API surface. Not the primary recommendation given lead 3's better resolution, but confirmed real and JEA-reachable in case it's ever wanted as a supplementary/fallback source.

<a id="lead-2-w32tm-query-status-verbose-some-real-new-fields-one-false-lead"></a>

## Lead 2: `w32tm /query /status /verbose` — some real new fields, one false lead

Adds a second block beyond the base `/query /status` output already parsed by `Get-NtpSyncStatus`:
```
Phase Offset: -0.0000049s
ClockRate: 0.0156250s
State Machine: 2 (Sync)
Time Source Flags: 8 (SignatureAuthenticated)
Server Role: 0 (None)
Last Sync Error: 0 (The command completed successfully.)
Time since Last Good Sync Time: 947.7096578s
```
- **`ClockRate` — false lead, ruled out.** Sampled four times over ~12 seconds: identical (`0.0156250s`) every time, and identical to the value shown in Microsoft's own example output in their public docs. This is a static hardware/OS tick-granularity constant (matches `GetSystemTimeAdjustment()`'s `TimeIncrement`, confirmed identical), not a live discipline signal — don't be misled by the name.
- **`Phase Offset` — real, live, and a genuinely different quantity from the already-captured network-measured offset.** Sampled repeatedly and found smoothly, monotonically changing (`-0.0000028 → -0.0000025 → -0.0000021 → -0.0000017 → ... → -0.0000004`) even while a simultaneously-sampled `/stripchart` offset read a completely different value (`+0.0001363s`) at essentially the same moment — confirmed these are two distinct internal quantities, not the same number reported twice. Likely the system's own internally-tracked phase-correction state, closer in spirit to what's wanted than the raw network offset already captured.
- **`State Machine`** (`2` = `Sync` observed throughout) — a coarse discipline-loop state classification; only one value seen in this session's testing (no drift/spike state was forced), so its full value range and how informative it'd be wasn't fully explored.
- **`Time since Last Good Sync Time`** — a live-computed duration, distinct from the already-captured absolute `Last Successful Sync Time`; a real freshness/staleness signal, though somewhat redundant with what a poll-interval-aware monitoring system already infers from poll cadence.

<a id="lead-4-the-event-log-diagnostic-channel-doesnt-exist-operational-channel-itself-says-to-use-counters-instead"></a>

## Lead 4: the event log — "Diagnostic" channel doesn't exist; "Operational" channel itself says to use counters instead

**Ruled out cleanly, with an unusually direct piece of evidence: Microsoft's own event text names the performance counter as the right tool.**

`Microsoft-Windows-Time-Service/Diagnostic` — checked directly via `Get-WinEvent -ListLog *Time*` against the real target: **does not exist on this Server 2019 install.** Only real Time-Service-related channels present: `Microsoft-Windows-Time-Service/Operational` (already enabled by default, 927 records at time of testing) and `Microsoft-Windows-Time-Service-PTP-Provider/PTP-Operational` (empty, not relevant — no PTP in use here). Whether "Diagnostic" exists on some other Windows version wasn't chased further once it was confirmed genuinely absent here and a real alternative (`Operational`, already enabled, zero deployment cost) was available to check instead.

`Operational`'s real Event ID 262 ("W32time service has adjusted the system clock rate") is the one event actually about clock-rate discipline — and its own message text, verbatim from the real target:

> *"W32time service has adjusted the system clock rate by 856.53339 PPM and the new nominal clock rate is 156116. Previous nominal clock rate was 156107.*
> ***Clock adjustments below 800.00000 PPM are not logged. Performance counters are recommended to efficiently track small adjustment values.***"

This is Microsoft's own documentation-in-the-wild directly confirming lead 3's conclusion: the event log only captures large (≥800 PPM) corrections — in normal healthy operation this event essentially never fires — and explicitly recommends the performance counter for anything smaller, which is exactly what's needed for routine discipline-quality monitoring. Also checked event 260 ("periodic configuration and status message," fires roughly every poll cycle) — it embeds a full status block, but it's the same data `/query /status /verbose` already provides, not new.

Enabling a genuinely-disabled channel would have been a real, flaggable deployment cost (every target needing a prerequisite beyond the whitelisted function itself) — moot here since the specific channel doesn't exist, and the already-enabled channel's own text steers toward the counter anyway.

<a id="lead-5-registry-config-values-ruled-out-confirmed-not-assumed"></a>

## Lead 5: registry config values — ruled out, confirmed not assumed

`HKLM:\SYSTEM\CurrentControlSet\Services\W32Time\Config`, read directly: `FrequencyCorrectRate`, `PhaseCorrectRate`, `MaxAllowedPhaseOffset`, `MinPollInterval`/`MaxPollInterval`, etc. — all confirmed to be configured targets/rates and one static value (`LastClockRate: 156250`, the same tick-granularity constant already ruled out twice above), no live measured discipline state anywhere in this key. Quick to rule out as expected, checked for real rather than skipped.

<a id="jea-feasibility-tested-directly-not-assumed"></a>

## JEA feasibility, tested directly, not assumed

**A genuinely new class of question — calling a raw Win32 API via P/Invoke from inside a whitelisted, zero-parameter JEA function — confirmed to work, not assumed to transfer from the already-proven external-command/COM-interop precedents.**

A temporary, never-committed probe function (`Test-ClockDisciplineFeasibility`, same pattern as `winupdate-pending`'s original COM-interop feasibility spike) was added to the real JEA module, whitelisted, tested through the actual constrained `svc-winrmproxy`/Kerberos/`WinrmProbe` path (`add_cmdlet()`, not `add_script()`), and removed again immediately after — no trace left in the deployed module. Real result through the constrained path:
```json
{"PInvokeOk":true,"TimeAdjustment":156250,"TimeIncrement":156250,"CounterOk":true,"CounterValue":0}
```
Both mechanisms work: `Add-Type`/`DllImport`-based P/Invoke (`GetSystemTimeAdjustment`) and `Get-Counter` are both reachable from inside a whitelisted function under `RestrictedRemoteServer`, confirmed for real. Negative control held throughout (`Get-Process`, unwhitelisted, still correctly failed as "not recognized" in the same session) — the whitelist boundary isn't affected by either new mechanism.

<a id="model-research-pass-2026-08-12-three-questions-resolved-against-the-real-chronyd-source"></a>

## Model research pass (2026-08-12): three questions resolved against the real `chronyd` source

Separate, deliberate pass — read `chronyd`'s real discovery mechanism and device-apps page directly (not just its polling file, already read above) before touching any of the three open questions the first pass left standing. All three leans below are **confirmed**, each against something actually read, not reasoned from field names alone.

**1. One app, not two — confirmed, and more thoroughly than the field list alone suggested.** Checked `chronyd`'s real discovery path (`includes/discovery/applications.inc.php:36-41`): every file in `includes/polling/applications/*.inc.php` is auto-registered as its own `app_type` (`$name => $name`), with explicit overrides only for the handful whose real SNMP-extend name differs from the filename (`osupdate` → `os-updates`, etc.). `chronyd` has no override entry — its `app_type` is the plain, unremarkable `'chronyd'`, discovered through the exact same generic path as every other JSON-app check. There is no special-cased module, no separate discovery step, nothing tied to data volume or polling cadence that would explain a split if one existed — it's ordinary in every structural respect, and it still doesn't split sync-status from discipline data. Confirmed further by the real device-apps page (`includes/html/pages/device/apps/chronyd.inc.php:35-40`): the default "Tracking" view embeds **"System time," "System clock frequency," and "Stratum level" as three panels on the same page**, not separate apps or even separate tabs. `ntp-sync-status` gains two new RRD datasets on its existing `Component`, not a new check.

**2. Extend the existing `Component`/`RrdDefinition` — confirmed, with one real naming/unit question surfaced for the implementation pass, not resolved here.** `chronyd`'s real `RrdDefinition` (`includes/polling/applications/chronyd.inc.php:18-29`) names its discipline dataset literally `frequency` (`GAUGE, -1000.0, 1000.0`) — matching this project's own established "match the real dataset name" discipline, already applied to `stratum`/`offset`/`delay`/`dispersion`. **Real wrinkle, worth flagging now rather than deciding here**: `chronyd`'s `frequency` is chrony's own native unit, PPM. The winning Windows source (`Clock Frequency Adjustment (PPB)`) is parts-*per-billion* — a 1000x unit difference. Naming a new dataset `frequency` and populating it with a raw PPB number without conversion (or without a distinct name) would be a real, silent unit mismatch, not a cosmetic detail — the implementation pass needs to explicitly decide "convert to PPM to genuinely match the precedent's unit, or keep PPB under a name that says so" rather than copy the name and assume the unit follows.

**3. Single-record shape stays right, `chronyd`'s per-source model doesn't apply — confirmed, and the real code makes the fit tighter than first assumed.** `chronyd`'s per-source loop (`includes/polling/applications/chronyd.inc.php:68-89`, its own separate `$source_rrd_def`) exists for a real, structural reason tied to chrony itself: it genuinely tracks multiple configured sources concurrently, each with its own `frequency`/`frequency_skew`. But the fields actually comparable to what Windows exposes — `frequency`, `residual_frequency`, `skew` — are chrony's **top-level `tracking` record** (`chronyd.inc.php:31-43`), not part of the per-source loop at all; they're already single-valued in the real precedent, independent of how many sources are configured. Windows (`w32tm`) reports exactly one current sync source, and the winning discipline signals (`Clock Frequency Adjustment (PPB)`, `Phase Offset`) are inherently system-wide, not per-peer, matching the same "one synthetic peer, not several" constraint `ntp-sync-status` already resolved for `stratum`/`offset`. No new per-source dimension needed — the new fields are properties of the single existing `Component`, same as every field already on it.

**One more real observation surfaced while confirming #2, not itself one of the three questions**: `Phase Offset` (from `/verbose`) may not need to be a *new* field at all — it could instead be an additional, more-reliable *source* for the already-shipped `offset` field, since it's already present in the same `/query /status /verbose` call, unlike the current `offset`'s dependency on a second, separately-fallible `/stripchart` network round-trip. Flagged for the implementation pass to weigh explicitly, not decided here — outside this pass's three-question scope.

<a id="what-comes-next"></a>

## What comes next

**Real data exists and is genuinely analogous to `chronyd`'s own discipline fields** — this isn't the "document a real platform limitation" outcome; there's something real to build. `Clock Frequency Adjustment (PPB)` (via `Get-Counter`) is the recommended primary source: better resolution than `GetSystemTimeAdjustment()`, no deployment prerequisite unlike the event-log route, and it's the mechanism Microsoft's own event text names as correct. `Phase Offset` (from `/verbose`) is a plausible secondary/replacement field, genuinely distinct from the already-captured network offset.

**All three model questions resolved** (see the section directly above) — new RRD datasets on the existing `ntp-sync-status` `Component`, one unified app (not split), no per-source dimension. One real open item carried into the implementation pass: the `frequency` dataset's name/unit (PPM-to-match-precedent vs. native PPB) needs an explicit decision, not a silent copy of the name alone.

This is now ready for an implementation handoff — model, data source, and JEA feasibility are all confirmed against real evidence; nothing left to research before writing code.

<a id="implementation-status-2026-08-12"></a>

## Implementation status (2026-08-12)

**Built, deployed, and verified end-to-end against real infrastructure.** Both flagged open items from the model pass were decided during implementation:

- **PPM-vs-PPB unit question: kept native PPB, under an explicitly unit-suffixed name (`frequency_ppb`), not `frequency`.** Converting to PPM would have matched `chronyd`'s bare field name, but silently converting units to match a name is worse than a differently-named field that says what it holds — a reader diffing this dataset against `chronyd`'s should not have to already know the conversion happened. The RRD dataset, JEA field, proxy field, and PHP variable are named `frequency_ppb`/`FrequencyPpb`/`frequency_ppb` consistently top to bottom.
- **`offset` vs. `phase_offset` relationship: added as a second, distinct field, not a replacement.** Confirmed by real sampling (documented in Lead 2 above) that `Offset` (network-measured, from `/stripchart`) and `Phase Offset` (from `/verbose`, the actual value the clock-discipline algorithm is correcting against) are genuinely different quantities — different magnitude and sometimes different sign at the same instant. Replacing one with the other would have silently discarded a real, independently-useful signal.

**What was actually built, across all three repos:**
- **`infra-bits:librenms-winrm-stack/WINRM_JEA_WINDOWS.md`** — `Get-NtpSyncStatus` extended to call `w32tm /query /status /verbose` (was plain `/query /status`), parses `PhaseOffset` from the new verbose output, and reads `FrequencyPpb` via `Get-Counter -Counter '\Windows Time Service\Clock Frequency Adjustment (PPB)'` (both null-safe if unavailable). Deployed to `winrm-test01.vpp.local` and verified through the real constrained JEA path.
- **`alexh/librenms-bits:winrm-proxy/app/checks/ntp_sync_status.py`** — validates and passes through the two new fields (numeric-or-null). Tests extended (4 new cases: PPB at steady-state zero, PPB null while otherwise synced, non-numeric rejection for both new fields). 18/18 for this file, 168/168 for the full proxy suite. Deployed to the real proxy container.
- **`LibreNMS/Modules/WinrmPoller.php`** — `pollNtpSyncStatus()` reads both new fields, `RrdDefinition` extended with `phase_offset`/`frequency_ppb` (both `GAUGE, null`). `php -l`/`phpstan analyse` clean, 6/6 `WinrmPollerTest.php` tests pass.
- **`includes/html/graphs/device/ntp_frequency.inc.php`** and **`ntp_phase_offset.inc.php`** (new files) — deliberately NOT widened to the native-MIB `'ntp'` Component type the way the four original `ntp_*` graphs are, since real Cisco NTP-MIB RRD files genuinely lack these two datasets; a widened `DEF` against one would fail.
- **`includes/html/pages/device/apps/ntp.inc.php`** — two new panels (Frequency, Phase Offset), conditionally rendered only when a `ntp-client-winrm` component is actually present on the device, via a `$hasWinrmComponent` guard — matches the same reasoning as the graph-widening decision above.

**Verified for real, not just unit-tested:**
- RRD schema migration: old 4-dataset RRD file deleted and recreated (adding datasets to an existing `RrdDefinition` requires this — `rrdtool update` fails on a DS-count mismatch, established pattern from earlier migrations this session). Confirmed via `rrdtool info` showing all 6 real datasets with live values.
- Real HTTP verification against `lnms-test.vpp.local`: authenticated fetch of the device Apps page confirmed both new panels present in the rendered HTML; both new graphs fetched as real PNG images.
- **Visual verification of both graphs, including a real gotcha caught by actually looking rather than trusting a 200 status:** the first render of both graphs (immediately after the RRD file was recreated) showed correct axes/labels/legend but `-nan` for Now/Min/Max — expected RRD behavior when an `AVERAGE`-consolidated RRA has too few primary data points yet, not a bug, but something that would have gone unnoticed from HTTP status alone. Polled twice more, re-fetched narrowed to a 1-hour window: both graphs then showed real, live, non-nan values (`Frequency: 30.27 PPB`, `Phase Offset: -0.000033s`), confirming genuine data end-to-end from the JEA function through the proxy, `WinrmPoller.php`, the RRD file, and RRDtool's own graph rendering.
