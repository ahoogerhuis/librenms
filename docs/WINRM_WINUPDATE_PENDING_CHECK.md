# WinRM Check — Windows Update Status (`winupdate-pending`)

**Contents**
- [v4 (2026-08-12) — fixed a real duplicate-Event-26 undercount bug, found via a real reported discrepancy](#v4-2026-08-12-fixed-a-real-duplicate-event-26-undercount-bug-found-via-a-real-reported-discrepancy)
- [v3 (2026-08-11) — migrated WinrmPoller.php from Sensor to Application, matching osupdate](#v3-2026-08-11-migrated-winrmpollerphp-from-sensor-to-application-matching-osupdate)
- [v2 (2026-08-10) — replaced the COM-based implementation entirely](#v2-2026-08-10-replaced-the-com-based-implementation-entirely)
- [Status (v1 history)](#status-v1-history)
- [Goal](#goal)
- [Function sketch](#function-sketch)
- [Proxy side — built (v1 description; see v2 section at top for the current field shape)](#proxy-side-built-v1-description-see-v2-section-at-top-for-the-current-field-shape)
- [One open item — RESOLVED by the v2 redesign, not by deciding a timeout](#one-open-item-resolved-by-the-v2-redesign-not-by-deciding-a-timeout)
- [Not yet decided, worth raising before LibreNMS wiring begins — RESOLVED](#not-yet-decided-worth-raising-before-librenms-wiring-begins-resolved)
- [Deploying this check](#deploying-this-check)

<a id="v4-2026-08-12-fixed-a-real-duplicate-event-26-undercount-bug-found-via-a-real-reported-discrepancy"></a>

## v4 (2026-08-12) — fixed a real duplicate-Event-26 undercount bug, found via a real reported discrepancy

**The report that triggered this:** the real target's in-OS Windows Update UI showed a genuine pending update (`Security Intelligence Update for Microsoft Defender Antivirus - KB2267602`, `Status: Pending install`), while LibreNMS's `OS Updates` graph showed `Now: 0`. Two genuinely different explanations produce the identical `0` from LibreNMS's side — v2's design accepts staleness (Windows hasn't scanned since the update appeared, a real non-bug tradeoff) vs. a real parsing bug (Windows scanned, found it, logged Event 26 — and the check missed it). Investigated for real rather than assumed either way, per this project's standing discipline.

**Real DB check first, not just the graph:** `application_metrics` showed `last_scan_time = 1786448742` (`2026-08-11 11:45:42 UTC`) — recent, not stale on its face. Compared directly against the real event log on the target (`Microsoft-Windows-WindowsUpdateClient/Operational`, via the `debugadmin` unconstrained debug path — pure investigation, no product-path change): the most recent Event 26 entries showed something the check's design had never accounted for.

**Root cause, confirmed directly from real event data, not inferred from the general pattern:** Windows Update Agent logs **one Event 26 per registered scan category**, not one per scan cycle. On this target (registered for both the "Windows Update" and "Microsoft Update" catalogs — the latter is what pulls in Defender Security Intelligence updates), every real scan produced **two** Event 26 entries, microseconds apart, one per category:
```
RecordId 257, 2026-08-11T04:45:42.6055152-07:00: "Windows Update successfully found 0 updates."
RecordId 256, 2026-08-11T04:45:42.6055032-07:00: "Windows Update successfully found 1 updates."
```
(120 ticks apart — 12 microseconds — confirmed via `.Ticks`, not assumed from second-precision display.) This pattern held across every real scan batch examined (four independent batches, spanning 2026-08-09 through 2026-08-11), not a one-off. `Get-PendingUpdateStatus`'s `-MaxEvents 1` selection picked whichever of the pair the log happened to return — in this case `RecordId 257` ("found 0"), silently discarding the "found 1" sibling from the same real scan. **A real, confirmed parsing bug — Windows had scanned, had found the update, and logged it — not staleness.**

**The fix: fetch a small batch of recent events, group by proximity to the newest, sum the counts.** Exact-timestamp equality doesn't work for grouping — same-batch events are microseconds to low-single-digit-seconds apart, never bit-identical — so grouping uses a 5-second window from the newest event's `TimeCreated` instead (a huge margin over the observed ~12-microsecond real gaps, and comfortably below the ~63-second-to-hours gaps between genuinely distinct scan cycles in the same real data). `Get-PendingUpdateStatus`:
```powershell
try {
    $events = @(Get-WinEvent -LogName 'Microsoft-Windows-WindowsUpdateClient/Operational' -FilterXPath "*[System[EventID=26]]" -MaxEvents 10 -ErrorAction Stop)
} catch [Exception] {
    [pscustomobject]@{ PendingCount = $null; LastScanTime = $null } | ConvertTo-Json -Compress
    return
}

$latestTime = $events[0].TimeCreated
$batch = @($events | Where-Object { ($latestTime - $_.TimeCreated).TotalSeconds -le 5 })

$count = 0
$anyMatched = $false
foreach ($evt in $batch) {
    if ($evt.Message -match 'successfully found (\d+) updates') {
        $count += [int]$Matches[1]
        $anyMatched = $true
    }
}
if (-not $anyMatched) { $count = $null }

[pscustomobject]@{
    PendingCount = $count
    LastScanTime = $latestTime.ToString('o')
} | ConvertTo-Json -Compress
```
**Sum, not max or first-match** — matches what the real Windows Settings UI shows (the total pending across every registered scan category), and generalizes correctly to more than two categories or a genuinely larger real count (e.g. a Patch Tuesday cumulative rollup bundling several KBs at once) without any code change, since it's not hardcoded to exactly two events. Backward-compatible with a single-category host: with only one Event 26 in the batch, the sum is just that one count, identical to the old behavior. `-MaxEvents 1` → `-MaxEvents 10` — cheap, bounded, plenty of headroom over the 2-per-batch pattern actually observed.

**A second, real, separate bug found only by testing the fix through the real production path, not an ad-hoc script:** deploying the new `.psm1` and invoking `Get-PendingUpdateStatus` directly (a fresh `WSMan`/`RunspacePool` per call, bypassing the proxy's own connection handling entirely) correctly returned `PendingCount: 1`. Going through the *real* proxy HTTP `/check` endpoint — the path the actual poller uses — kept returning `PendingCount: 0`, unchanged, even on repeated calls. Root cause: `alexh/librenms-bits:winrm-proxy/secrets/config.yml` has `session_pooling_enabled: true` on this deployment (`winrm_executor_pypsrp.py`'s opt-in connection-reuse feature, live since 2026-08-10). **JEA `.psm1`/`.psrc` hot-reload is real, but only for a *new* PSRP session — an already-open pooled session keeps running whatever module state was loaded when that session was created, and doesn't re-read the file mid-session.** The pool had a live session to this host from earlier the same evening, predating the file edit, so the real HTTP path kept serving the stale pre-fix behavior until the container was restarted (evicting all pooled sessions) — confirmed fixed immediately after: `docker compose restart`, then the same `/check` call correctly returned `PendingCount: 1`.

**Generalizes beyond this one fix — a standing operational note, not a one-off gotcha:** on any proxy deployment with `session_pooling_enabled: true`, a JEA function edit needs the proxy restarted (or the pool's `pooled_session_idle_timeout_seconds` to naturally elapse, 600s by default) before it takes effect for any host with an already-pooled session — checking only via a fresh ad-hoc script (bypassing the pool) will show the fix "working" while the real production path is still stale. Worth checking `session_pooling_enabled`'s value before trusting an ad-hoc verification as representative of the live path, for any future JEA change on a pooling-enabled deployment.

**Verified end-to-end, all four layers, real data throughout:**
- JEA function: direct fresh-session call → `{"PendingCount":1,...}`; real constrained-path call through the proxy's own executor → same, after the restart.
- Proxy HTTP `/check` API (the real poller-facing path): `{"ok":true,"value":{"pending_count":1,...}}`.
- DB: real `device:poll` against the live target populated `application_metrics` (`packages`: `0` → `1`).
- RRD/UI: real authenticated HTTP fetch of the `OS Updates` graph as PNG, visually inspected — the first fetch (same 5-minute step the fix landed in) showed a real, expected RRD-consolidation artifact (`0.74`, an AVERAGE blending the pre-fix `0` and post-fix `1` within one step), not a bug; waited for the step to fully close and re-fetched, confirming a clean settled step from `0` to `1`, `Now: 1`.

**Patch Tuesday context, not yet resolved as of this writing:** this investigation happened to fall on Patch Tuesday, raising two real opportunities not chased further here since they weren't what was asked: (1) whether the fixed check correctly sums a genuinely larger real count once the monthly cumulative rollup gets scanned (the sum-based design should handle this correctly by construction, but hasn't been observed against a real >1 multi-KB batch yet), and (2) whether `reboot-pending` correctly reflects a real post-install reboot-required state without any forcing, the first genuinely real (not synthetic) test of that check. As of the last check this session, the target's `LastScanTime` was unchanged since the original fix verification and `RebootRequired` was still `false` — the monthly rollup hadn't reached this target's own scan cadence yet. Not chased further, per explicit instruction not to force or wait around for it.

**Follow-up (2026-08-12 00:19 CEST), opportunity (1) above resolved for real: the count climbed 1 → 4, confirmed as genuine progressive discovery, not a bug.** Three possible explanations were weighed against real evidence rather than picking the likeliest-sounding one: a real 4th item, real progressive discovery (a later scan finding more), or the sum logic double-counting the same batch repeatedly. The distinguishing test was whether `LastScanTime` itself advanced alongside the count rise. It did, by a real, substantial margin — `04:45:42` → `15:14:39` local (~10.5 hours later) — ruling out double-counting outright (a repeat-count bug would show the *same* `LastScanTime` reused across polls, not a materially later one). The real current Event 26 batch at that new timestamp:
```
RecordId 258, 2026-08-11T15:14:39.5383964-07:00: "Windows Update successfully found 3 updates."
RecordId 259, 2026-08-11T15:14:39.5384071-07:00: "Windows Update successfully found 1 updates."
```
`3 + 1 = 4`, matching the reported count exactly — the "3" is the Windows Update category catching the real Patch Tuesday cumulative/security KBs (`KB890830`, `KB5121645`, `KB5120238`), the "1" is the still-persisting Microsoft Update/Defender category (`KB2267602`, unrelated to Patch Tuesday, pending since before it started). Confirmed via a real, settled RRD fetch and an authenticated HTTP fetch of the graph as PNG, visually inspected: a clean two-step climb, `0 → 1 → 4`, `Now: 4`. **This is the first real evidence of the v4 fix's sum-based design correctly handling a genuinely larger, evolving real-world batch** (more than the original two-event case it was built and fixed against) — validates the "generalizes to more than two categories or a genuinely larger real count without any code change" claim made when the fix shipped, not just a restated assumption.

**Follow-up (2026-08-12 00:46 CEST): count "stuck" at 4 after two of those four items became reboot-pending — confirmed as expected staleness, not a bug, and a real open question left genuinely open rather than assumed answered.** `application_metrics.last_scan_time` is `1786486479` (`22:14:39 UTC`), byte-identical to the value already confirmed above — no fresh scan has run since the `1→4` confirmation. Since `PendingCount` is bound by Windows' own scan cadence by design (the entire point of v2's event-log approach over v1's live COM search), an unchanged `LastScanTime` means the check has nothing new to report yet; "stuck at 4" is exactly what's expected here, not evidence of anything wrong. The `reboot-pending` sensor did flip naturally in the same window (`sensor_current: 0→1`, `lastupdate: 2026-08-12 00:35:02`) — a different, unrelated mechanism (the registry flag set once an update actually *installs*), not gated by or synchronized with Windows Update Agent's own scan cycle, so the two check's independent timing isn't itself surprising.

**Left genuinely open, not assumed:** whether Windows' Event 26 logic would stop counting the two now-installed/reboot-pending items once a *fresh* scan actually runs (i.e. whether `PendingCount` means "not yet installed" or the broader "not yet fully resolved, including awaiting-reboot") is still unanswered — this check happened to land before any new scan existed to test it against. Worth a quick real check next time a fresh scan naturally lands after a real install: compare the new batch's item count/category breakdown against which specific KBs are still actually outstanding vs. merely awaiting reboot, rather than assuming either interpretation.

**Practical guidance until that's resolved: treat `PendingCount` as zero-vs-nonzero, not as an exact actionable count.** Whether it includes items already installed and merely awaiting reboot is still unconfirmed, so the precise number can currently overstate how many updates genuinely still need attention. "Zero" reliably means nothing pending; any nonzero value means something needs a look, but the exact figure shouldn't be trusted as precise until the open question above is resolved. Relevant for anyone setting up alert thresholds against this field in the meantime.

<a id="v3-2026-08-11-migrated-winrmpollerphp-from-sensor-to-application-matching-osupdate"></a>

## v3 (2026-08-11) — migrated `WinrmPoller.php` from `Sensor` to `Application`, matching `osupdate`

**`winupdate-pending` was built (v1/v2, above) before "match the SNMP-based equivalent's shape" (`WINRM_DESIGN.md`'s standing development convention) was actually checked against a real precedent for this check.** It shipped on the generic `Sensor`/`checks()` pattern that every state/count check in this module uses by default — never verified against what a real SNMP-based device does for the same concept. It doesn't have one: the real precedent is **`osupdate`** (`includes/discovery/applications.inc.php`, `includes/polling/applications/os-updates.inc.php`) — same concept, a pending update/package count — and it's `App\Models\Application` + `application_metrics`-based, not `Sensor`, which is why it shows up under the device's **Apps** tab and `winupdate-pending` didn't.

**No JEA/proxy changes** — `Get-PendingUpdateStatus` and `app/checks/winupdate_pending.py` already return the right shape (`pending_count`, `last_scan_time`); this was purely a `WinrmPoller.php`-side data-model migration.

**A genuinely separate, third real data model — confirmed by reading the code, not assumed from the `ntp-sync-status` precedent.** `osupdate`'s real polling code calls `update_application()` (`includes/polling/functions.inc.php`), which turned out to be a parallel system to `LibreNMS\Component` (what `ntp-sync-status` uses), not a wrapper around it. `Application`+`application_metrics` is a genuinely older, separate storage model — `includes/discovery/applications.inc.php`'s discovery loop never touches `Component` at all.

**`app_type` is `'os-updates'`, not `'osupdate'`.** The SNMP-extend script name (`osupdate`, the discovery array's *key*) is not what's written to the DB — `includes/discovery/applications.inc.php`'s discovery loop resolves `$app = $applications[$extend]` and writes that resolved *value* (`'os-updates'`) to `app_type`, and the polling dispatcher (`includes/polling/applications.inc.php`) builds its include path from `app_type` directly. Reusing `'os-updates'` verbatim gets the real `includes/html/pages/device/apps/os-updates.inc.php` page and `application_os-updates_packages` graph for free — zero new UI code, same page/graph a Linux device's pending-package count would show, which is the right outcome (an admin doesn't care that the pending-update mechanism differs between Windows and a package manager).

**Two real semantic gaps, closed deliberately rather than dropped silently:**
- **`LastScanTime` has no home in `osupdate`'s own shape** (a single `packages` RRD dataset, no "last checked" concept at all). `application_metrics` isn't restricted to only what's graphed, so it's stored there as an additional, non-graphed metric — as a unix timestamp (`strtotime()`-parsed from the check's ISO datetime string), not the raw string, because `update_application()`'s own metrics-writing code does an unconditional `(float)` cast on every metric value (confirmed by reading `includes/polling/functions.inc.php` directly) — a datetime string wouldn't survive that cast, a timestamp does.
- **`osupdate` has no "unavailable, not yet scanned" state distinct from zero** — but `winupdate-pending` v2 deliberately distinguishes `PendingCount: null` (Windows hasn't logged Event ID 26 yet) from a confirmed `0`. Reused `update_application()`'s own real behavior for this rather than inventing a separate mechanism: `$app->app_state` defaults unconditionally to `'UNKNOWN'` at the top of the function, and only gets overwritten if `$response` matches one of a few SNMP-extend-specific sentinel patterns (a Python traceback, `Connection refused`, an `ERROR|LEGACY|UNSUPPORTED` prefix) that a WinRM check never produces. Passing an **empty** `$response` when `pending_count` is `null` leaves `app_state` at `'UNKNOWN'` — exactly the right semantic, confirmed by reading the function rather than assumed. A confirmed `0` passes `$response = '0'` (non-empty), so `app_state` becomes `'OK'` — the two states stay distinguishable end-to-end (`app_status` reads `'0'` vs. `'not yet scanned'`).

**Scoping `dataExists()`/`cleanup()`/`dump()` by `app_type` alone is safe** — same reasoning already established for `EntPhysical` and the `ntp` `Component` type. The real SNMP-extend Applications discovery (`LibreNMS\Modules\LegacyModule`, module name `applications`) requires `$connectivity->snmpIsAvailable()` (confirmed by reading `LegacyModule::shouldPoll()` directly), so it never runs against an `snmp_disable` WinRM device — any `app_type='os-updates'` row on one of these devices can only have come from this module.

**`application_metrics` needed explicit cleanup — no DB-level cascade.** Unlike `component_prefs` (a real `ON DELETE CASCADE` FK back to `component.id`, confirmed by reading the migration), `application_metrics.app_id` is a bare `unsignedInteger` with no foreign key at all (confirmed by reading `2018_07_03_091314_create_application_metrics_table.php`). The real `includes/discovery/applications.inc.php` has its own sweep for this (`ApplicationMetric::doesntHave('app')->delete()`), but that only runs as part of the generic SNMP-extend Applications module — gated the same way as above, it never runs against an `snmp_disable` device. `WinrmPoller::cleanup()` deletes the app's `ApplicationMetric` rows explicitly before the (soft) `Application` delete.

**Orphaned data from the old `Sensor`-based v1/v2 implementation cleaned up on the real test device.** Device 5 (`winrm-test01.vpp.local` on `lnms-test`) had a stale `sensor_type='winrm-winupdate-pending'` row (`sensor_id` 19) and its RRD file (`rrd/winrm-test01.vpp.local/sensor-count-winrm-winupdate-pending-winrm-winupdate-pending.rrd`) — both deleted manually (`cleanup()` only reaches what it's told to scope, and this row predates the migration so it isn't `app_type`-scoped). **Any other already-discovered device with the old check needs the same manual treatment** — same category of one-time cleanup as `network-traffic`'s earlier `Sensor`-pair removal.

**Real end-to-end verification on `lnms-test`/`winrm-test01.vpp.local` (2026-08-11), all three states, not just the happy path:**
- `php -l` + `vendor/bin/phpstan analyse` clean.
- Real `device:discover 5` created the `Application` row (`app_type='os-updates'`, `app_state='UNKNOWN'` — correct pre-poll default).
- Real `device:poll 5 -m winrm-poller` against the live target: a genuine scan-confirmed `pending_count=0` came back, correctly transitioned `app_state` `UNKNOWN` → `OK`, wrote `packages=0` + a real `last_scan_time` to both the RRD (`rrd/winrm-test01.vpp.local/app-os-updates-9.rrd`, single `packages` dataset, matching `os-updates.inc.php`'s exact naming) and `application_metrics`.
- The other two states (a nonzero pending count, and the not-yet-scanned/both-`null` state) can't currently be forced for real on this target — it's already scanned once (real Event ID 26 exists) and clearing that would mean mutating a shared test asset's real Windows Event Log, not something to do casually. Both were already proven for real at the proxy/JEA layer during v2's build (see the v2 section below). To confirm the **migration's own code** — not the underlying check — handles both shapes correctly, `pollWinupdatePending()` was invoked directly via reflection on `lnms-test` with the network boundary (`WinrmProxy::check()`) swapped for a canned-response subclass, exercising the exact shipped method end-to-end against the real DB/RRD:
  - `{pending_count: null, last_scan_time: null}` → `app_state` `OK` → `UNKNOWN`, `app_status='not yet scanned'`, metrics left at their prior values (no fabricated write).
  - `{pending_count: 5, last_scan_time: '2026-08-11T12:00:00Z'}` → `app_state` `UNKNOWN` → `OK`, `app_status='5'`, `packages=5` written.
  - `{pending_count: 0, ...}` re-applied afterward → `app_state='OK'`, `app_status='0'` — confirmed distinguishable from the `null` case's `'not yet scanned'`, not collapsed to the same thing.
  - Device re-polled for real afterward to restore its genuine live state (`packages=0`, real scan) before leaving it as a live fixture.

<a id="v2-2026-08-10-replaced-the-com-based-implementation-entirely"></a>

## v2 (2026-08-10) — replaced the COM-based implementation entirely

**The v1 design below (COM search, `CriticalCount`/`ImportantCount`) is historical record, not current behavior.** Once live and polled on a normal cadence, v1 was confirmed — real controlled test, not theorized or inferred from correlation — to keep `wuauserv` pinned continuously `Running`:

1. Baseline `wuauserv`: `Running` (its actual live state at the time).
2. LibreNMS polling paused on the device, zero WinRM calls of any kind against the target for 12 minutes.
3. Re-checked: **`Stopped`** — settled back down on its own with nothing calling `Get-PendingUpdateStatus`.
4. Immediately called `Get-PendingUpdateStatus` once more, re-checked: **`Running`**, immediately.

Reproducible and bidirectional, not a one-off. Mechanism: `CreateUpdateSearcher().Search(...)` is a genuine live WUA search every poll, and the COM API is backed by `wuauserv` regardless of the searcher's `Online` property — tested directly: forced a clean `Stopped` baseline, called `Search()` with `Online=$false` (cache-only, no network round-trip), and it *still* started the service, just faster (1.7s vs ~3s). There's no way to use `Microsoft.Update.Session` at all without invoking the service. Full incident and the `service-status-wuauserv` interaction it causes: `docs/WINRM_SERVICE_MONITORING_PATTERNS.md`'s "Pattern 2, revisited" section.

**v2 reads Windows' own last scan-completion event instead of triggering a new one.** `Microsoft-Windows-WindowsUpdateClient/Operational`'s Event ID 26 ("Windows Update successfully found N updates") is logged by Windows itself on every scan, whether triggered by us or by its own internal schedule. Confirmed via the same real-test discipline: forced `wuauserv` to `Stopped`, read the event log, `wuauserv` stayed `Stopped` — genuinely never touched, because `Get-WinEvent` talks to the Event Log service, not `wuauserv`. Also confirmed `Get-WinEvent` is reachable inside the actual constrained JEA session (`svc-winrmproxy`/Kerberos/`WinrmProbe`) — not guaranteed the way `Get-CimInstance`/`Get-NetAdapter` turned out *not* to be; `Microsoft.PowerShell.Diagnostics` (its home module) is apparently in JEA's always-loaded set.

```powershell
function Get-PendingUpdateStatus {
    [CmdletBinding()]
    param()

    try {
        $e = Get-WinEvent -LogName 'Microsoft-Windows-WindowsUpdateClient/Operational' -FilterXPath "*[System[EventID=26]]" -MaxEvents 1 -ErrorAction Stop
    } catch [Exception] {
        [pscustomobject]@{ PendingCount = $null; LastScanTime = $null } | ConvertTo-Json -Compress
        return
    }

    $count = $null
    if ($e.Message -match 'successfully found (\d+) updates') {
        $count = [int]$Matches[1]
    }

    [pscustomobject]@{ PendingCount = $count; LastScanTime = $e.TimeCreated.ToString('o') } | ConvertTo-Json -Compress
}
```

**Real tradeoff, not glossed over: severity breakdown is gone.** Checked the actual event log's full ID range present on the real target (250 events) — only ID 26 (scan summary, a flat count) and ID 41 (individual download) ever appear. No per-severity detail exists in this log at all. `CriticalCount`/`ImportantCount` are dropped from the output entirely, not stubbed with a fake `0` — that would misrepresent "not measured this way anymore" as "confirmed zero critical updates," the same kind of fabrication this project has avoided elsewhere (e.g. leaving `disk-space`/`network-traffic`'s `Port` fields like `ifOperStatus` unset rather than guessing). Nothing downstream actually used the severity fields anyway — `WinrmPoller.php` only ever read `pending_count`.

**Gained `LastScanTime` in their place** — directly answers the "has it actually run recently" question this doc's own "Not yet decided" section (and `WINRM_SERVICE_MONITORING_PATTERNS.md` Pattern 2, from the start) already flagged as the more meaningful signal than point-in-time state. Not yet wired to a sensor (available in the raw check value, same deferred-but-not-forgotten treatment the severity fields got in v1).

**Both `PendingCount` and `LastScanTime` are independently nullable.** A fresh host with no Event ID 26 yet (Windows hasn't scanned on its own schedule) returns both `null` — a real, valid "don't know yet" state, `ok: true`, not an error. If the event exists but its message doesn't match the expected format (a Windows version/locale difference, say), `LastScanTime` is set while `PendingCount` stays `null` — kept as two independent fields specifically so that distinction survives, rather than collapsing to one all-or-nothing flag.

**Deployed for real via `debugadmin`** (unconstrained local admin, bypasses JEA entirely — separate access set up specifically for faster debugging, see the `claude-adm-winrm-access`/`claude-adm-jea-autonomy` memory notes), per standing permission to deploy real JEA changes this way for this project's build/test work — then verified through the actual constrained `svc-winrmproxy`/Kerberos path, since that's the identity that actually matters for the real product.

Proxy-side (`app/checks/winupdate_pending.py`) and `WinrmPoller.php` updated to match — see below, updated in place rather than left describing v1.

<a id="status-v1-history"></a>

## Status (v1 history)
**Built, deployed, and confirmed working end-to-end against the real target** (2026-08-10) — not just the earlier COM-feasibility spike. Not yet wired into `WinrmPoller.php` (see the sensor-shape question below, still open).

Real test sequence, `winrm-test01.vpp.local` via the containerized proxy on `winrm-proxy`:
1. Rebuilt/redeployed the proxy image (`docker compose build && up -d`) to pick up the new check — sanity-checked `reboot-pending` still worked afterward (`{"reboot_pending":false}`, 0.69s), confirming the redeploy didn't break the existing three checks.
2. Called `POST /check {"host":"winrm-test01.vpp.local","check_name":"winupdate-pending"}` through the real HTTPS API three times: **`{"pending_count":1,"critical_count":0,"important_count":0}`, consistently, at 5.35s / 4.21s / 4.42s.** No errors, no warnings in the proxy's logs.
3. Went further than the API surface: `docker exec`'d into the proxy container (which already holds a live Kerberos ticket) and drove `pypsrp` directly against the JEA session, per §5 of `WINRM_JEA_WINDOWS.md` — `Get-PendingUpdateStatus` returned the same JSON directly, while `Get-Process` and `Get-ItemProperty` both correctly failed as "not recognized." That's the actual proof the JEA whitelist constraint holds for the new function, not just that the happy path works.

The one pending update on the target has no MSRC severity (0 critical, 0 important, 1 pending) — a live, real confirmation of the null-`MsrcSeverity` behavior below, not just a documented assumption.

The original open feasibility question — does COM interop work inside a JEA `RestrictedRemoteServer` session at all — was **resolved, confirmed working** earlier, tested through the real proxy/Kerberos/JEA path (`{"Success":true,"Detail":"System.__ComObject"}` against `svc-winrmproxy@VPP.LOCAL`). Original design holds as sketched — no PSWindowsUpdate fallback needed.

<a id="goal"></a>

## Goal
Analogous to LibreNMS's `osupdate` SNMP-extend app for Linux — count of outstanding/pending updates, not installing them.

<a id="function-sketch"></a>

## Function sketch

```powershell
function Get-PendingUpdateStatus {
    [CmdletBinding()]
    param()  # zero parameters, matches the established pattern

    $session = New-Object -ComObject Microsoft.Update.Session
    $searcher = $session.CreateUpdateSearcher()
    $result = $searcher.Search("IsInstalled=0 and IsHidden=0")

    $critical = ($result.Updates | Where-Object { $_.MsrcSeverity -eq 'Critical' }).Count
    $important = ($result.Updates | Where-Object { $_.MsrcSeverity -eq 'Important' }).Count

    [pscustomobject]@{
        PendingCount   = $result.Updates.Count
        CriticalCount  = $critical
        ImportantCount = $important
    } | ConvertTo-Json -Compress
}
```

Same whitelist-by-function-name pattern as the three existing checks — lives in `WinrmProbeJEA.psm1`, whitelisted in `WinrmProbe.psrc` (see `WINRM_JEA_WINDOWS.md` §1/§2c, `vpp/infra-bits`), no parameters, no external command surface. `New-Object -ComObject` calling out to the Windows Update Agent COM API is legitimate to call **from inside** an already-whitelisted function — same principle already established for `Get-ItemProperty` inside `Get-RebootPendingStatus` and (per the WSMan transport note) internal calls generally: JEA restricts what the *session* can invoke directly, not what a whitelisted function's own body calls internally.

**Reminder on enum/serialization correctness, given the real bug already hit on `service-status`:** checked against a real host with a real pending update (see Status above) — `PendingCount`/`CriticalCount`/`ImportantCount` all serialized as plain JSON integers, no `ServiceControllerStatus`-style enum-as-int surprise. `.Count` on a PowerShell collection is already a plain `[int]`, not an enum, so this particular risk didn't apply here the way it did for `.Status` — confirmed rather than assumed.

<a id="proxy-side-built-v1-description-see-v2-section-at-top-for-the-current-field-shape"></a>

## Proxy side — built (v1 description; see v2 section at top for the current field shape)

`app/checks/winupdate_pending.py` (`alexh/librenms-bits`) invokes `Get-PendingUpdateStatus`, parses the JSON, and returns `{"pending_count": ..., "critical_count": ..., "important_count": ...}`. Registered in `registry.py` as `"winupdate-pending"`. Deliberately **fails loud** (`ok=False`) on a missing or non-integer count field rather than defaulting to `0` — unlike `reboot_pending`'s "missing keys default to `False`", a missing count here means the check output doesn't match what `Get-PendingUpdateStatus` is supposed to return, which is worth surfacing rather than silently reading as "no updates pending". Covered by `tests/test_checks_winupdate_pending.py` (9 cases: normal parse, severity breakdown, `PendingCount` exceeding `CriticalCount + ImportantCount`, executor failure, unparseable/non-object/missing-key/non-integer output, whitelist-name sanity check) — all passing alongside the existing 22 tests for the other three checks (31/31, no regressions).

**Superseded 2026-08-10** — `winupdate_pending.py` now returns `{"pending_count": int|null, "last_scan_time": str|null}`, matching v2's event-log-based function. 13 tests, 56/56 passing with the other five checks.

<a id="one-open-item-resolved-by-the-v2-redesign-not-by-deciding-a-timeout"></a>

## One open item — RESOLVED by the v2 redesign, not by deciding a timeout

1. **Search latency / timeout handling.** Real observed latency on `winrm-test01.vpp.local` was **4.2s–5.4s across three real calls** — comfortably within any check's normal timeout, no different in practice from the other three checks so far. But this is one lightly-loaded pilot VM with (at most) a handful of pending updates and whatever WSUS/Windows-Update-reachability it has; it says nothing about a cold cache, a real WSUS round-trip under load, or a host with a large pending-updates backlog, which is the scenario the original latency concern was about. **`WinrmExecutor.invoke_function()` still has no per-call timeout parameter today** — that gap is unchanged by this test. Given the real number is small so far, this is lower urgency than originally framed, but still worth deciding an approach for before this check goes out to more than one pilot host.

**Superseded 2026-08-10, not by picking a timeout value — v2 doesn't call `Search()` at all**, so the whole latency concern this item was tracking doesn't apply to the new implementation. Reading one event from the log is near-instant. Still worth remembering as a category of risk (any *future* check that does invoke a genuinely slow live operation should think about timeout handling from the start) even though it's moot for this specific check now.

**`MsrcSeverity` can be null — confirmed as intended, not just documented (v1).** The real target's one pending update has `CriticalCount: 0, ImportantCount: 0` while `PendingCount: 1` — a live instance of an update with no MSRC severity rating counting toward the total but neither severity bucket, exactly as designed. **Moot in v2** — severity fields no longer exist in the output at all (see v2 section at top for why).

<a id="not-yet-decided-worth-raising-before-librenms-wiring-begins-resolved"></a>

## Not yet decided, worth raising before LibreNMS wiring begins — RESOLVED
Same category of question as `disk-space`'s `Storage` model discovery: does `winupdate-pending` fit the existing state-sensor pattern (`reboot-pending`, `service-status-*`) cleanly, or does it need its own sensor class given it's reporting counts rather than a discrete state? Worth checking against `WinrmPoller.php`'s `checks()` table shape (`states`/`map` are built around discrete state mapping) before assuming it drops in the same way.

**Resolved (before v2, still holds):** generalized `checks()` with an explicit `sensor_class` field (`state` vs `count`) instead of assuming every check is state-based — `winupdate-pending` is `sensor_class: 'count'`, wired in and live. Full detail in `WINRM_DESIGN.md`'s implementation history. v2 only changed which `value_key` gets read (`pending_count`, same key name, now nullable) and dropped the never-wired severity fields — no further `WinrmPoller.php` architecture change needed for this update, just the null-handling tweak described in the v2 section above.

<a id="deploying-this-check"></a>

## Deploying this check

Incremental add — assumes `WINRM_JEA_SETUP.md`'s first-time setup already ran, plus `reboot-pending` and the two `service-status-*` checks (`WINRM_REBOOT_PENDING_CHECK.md`, `WINRM_SERVICE_STATUS_CHECK.md`). Adds `Get-PendingUpdateStatus` (the v2, event-log-based implementation — see above for why v1's COM approach was replaced) and its `.psrc` whitelist entry. `.psrc`/`.psm1`-only — no `.pssc` change, no WinRM restart (see `WINRM_JEA_SETUP.md` §5).

```powershell
# Update-WinrmProbeJEA-WinupdatePending.ps1
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

Export-ModuleMember -Function Get-RebootPendingStatus, Get-WuauservStatus, Get-W32timeStatus, Get-PendingUpdateStatus
'@ | Set-Content -Path (Join-Path $moduleRoot 'WinrmProbeJEA.psm1') -Encoding UTF8

@'
@{
    Author = 'Your Name'
    CompanyName = 'VPP'
    Description = 'Constrained check functions exposed to the WinRM proxy service account.'

    VisibleFunctions = @('Get-RebootPendingStatus', 'Get-WuauservStatus', 'Get-W32timeStatus', 'Get-PendingUpdateStatus')
    VisibleCmdlets = @()
    VisibleAliases = @()
    VisibleExternalCommands = @()
    VisibleProviders = @()
}
'@ | Set-Content -Path (Join-Path $roleCapDir 'WinrmProbe.psrc') -Encoding UTF8

Write-Host "Done, no WinRM restart performed. Validate with a NEW session:"
Write-Host "  Enter-PSSession -ComputerName $env:COMPUTERNAME -ConfigurationName WinrmProbe"
Write-Host "  Get-PendingUpdateStatus   # expect JSON output ({PendingCount, LastScanTime}, both possibly null)"
```

Then run `WINRM_JEA_SETUP.md` §7's validation checklist to confirm the constraint still holds.
