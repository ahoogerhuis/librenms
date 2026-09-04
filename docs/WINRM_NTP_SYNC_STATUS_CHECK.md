# WinRM Check — NTP Sync Status (`ntp-sync-status`)

**Contents**
- [Status](#status)
- [Feasibility test: external command invocation inside a whitelisted function](#feasibility-test-external-command-invocation-inside-a-whitelisted-function)
- [A real incident along the way, and a real debugging-methodology lesson](#a-real-incident-along-the-way-and-a-real-debugging-methodology-lesson)
- [Data source and locale handling](#data-source-and-locale-handling)
- [A second JEA parameter-set surprise, also inside a whitelisted function](#a-second-jea-parameter-set-surprise-also-inside-a-whitelisted-function)
- [JEA function](#jea-function)
- [Proxy side](#proxy-side)
- [`WinrmPoller.php` side: `LibreNMS\Component`, not `Sensor`](#winrmpollerphp-side-librenmscomponent-not-sensor)
- [Two real bugs caught by testing against a drifted clock, before shipping](#two-real-bugs-caught-by-testing-against-a-drifted-clock-before-shipping)
- [`peerref` was always empty — the "Peer Reference" UI column fix](#peerref-was-always-empty-the-peer-reference-ui-column-fix)
- [The `?` icon and the "NTP" label — reopened and fixed for real](#the-icon-and-the-ntp-label-reopened-and-fixed-for-real)
- [The label is "NTP Client", via a distinct type and a widened shared filter, not a fork](#the-label-is-ntp-client-via-a-distinct-type-and-a-widened-shared-filter-not-a-fork)
- [Two real bugs found by actually clicking through the UI](#two-real-bugs-found-by-actually-clicking-through-the-ui)
- [Global "NTP Peers" apps page: missing graphs, another app_type-as-filename gap](#global-ntp-peers-apps-page-missing-graphs-another-app_type-as-filename-gap)
- [Investigation: does `w32tm /query /peers` expose real multi-peer data?](#investigation-does-w32tm-query-peers-expose-real-multi-peer-data)
- [Investigated: Reachability/ValidDataCounter](#investigated-reachability-validdatacounter)
- [Deploying this check](#deploying-this-check)

<a id="status"></a>

## Status

**Confirmed working end-to-end against the real target** (2026-08-11), including a full drift-test cycle: healthy → `W32Time` stopped (Component correctly shows `status=2`, a specific error) → resynced (back to `status=0`, error cleared, stratum restored). Two real bugs were caught and fixed by that drift test before this shipped — see below. `phpstan`/`php -l` clean, 10/10 applicability+overview tests, all 9 prior checks plus this one green in a final proxy sweep.

<a id="feasibility-test-external-command-invocation-inside-a-whitelisted-function"></a>

## Feasibility test: external command invocation inside a whitelisted function

**The real, current answer: don't use `VisibleExternalCommands` at all.** Confirmed via real testing against the actual constrained path (`svc-winrmproxy`/Kerberos/`WinrmProbe`, not just `debugadmin`):

```powershell
function Test-W32tmExternalCommand {
    [CmdletBinding()]
    param()
    & 'C:\Windows\System32\w32tm.exe' /query /status
}
```

Whitelisted via `VisibleFunctions` only — **`VisibleExternalCommands` left empty (`@()`)** — and it worked, returning real output:
```
Leap Indicator: 0(no warning)
Stratum: 4 (secondary reference - syncd by (S)NTP)
Precision: -23 (119.209ns per tick)
Root Delay: 0.0241078s
Root Dispersion: 0.0494470s
ReferenceId: 0xC0000201 (source IP:  192.0.2.10)
Last Successful Sync Time: 8/11/2026 7:37:11 AM
Source: dc01.vpp.local
Poll Interval: 10 (1024s)
```

This matches the exact same principle already established for `winupdate-pending`'s COM interop (`New-Object -ComObject Microsoft.Update.Session` called from inside `Get-PendingUpdateStatus`, see `WINRM_WINUPDATE_PENDING_CHECK.md`): **`VisibleFunctions`/`VisibleCmdlets`/`VisibleExternalCommands` gate what the *session* (the caller) can invoke directly — not what an already-whitelisted, zero-parameter function's own body calls internally.** The `&` call operator invoking `w32tm.exe` inside the function is invisible to the whitelist mechanism entirely, the same way the COM API call was. Confirmed the security boundary still holds with a negative control: `Get-Process` (never whitelisted) still correctly fails as `"not recognized"` in the same session where `Test-W32tmExternalCommand` succeeds — the whitelist-by-function-name model is intact, this doesn't reopen the "caller controls what runs" risk the project has been careful about, since the function itself takes zero parameters and the target executable path is hardcoded, not caller-supplied.

**`VisibleExternalCommands` itself was tested and found unreliable — worth recording, not silently avoided.** Populating `VisibleExternalCommands` with `w32tm.exe`'s path, whitelisting a function via `VisibleFunctions` in the *same* `.psrc` update, caused that new function (and a second, deliberately-unwhitelisted negative-control function) to fail with `"The term '...' is not recognized..."` when invoked the correct way (`add_cmdlet`, matching the real proxy's own invocation method — see below), while every *pre-existing* whitelisted function kept working fine. This was reproduced twice, and survived a `Restart-Service WinRM -Force`. Isolated cleanly: the exact same "add one new function" change, with `VisibleExternalCommands` left empty, worked immediately with no restart needed — `VisibleExternalCommands` populated is the one variable that changed between the working and failing case. Root cause not fully diagnosed (would need deeper JEA/WSMan internals investigation than was justified once the internal-call pattern above proved to be a complete, safe alternative) — but **don't use `VisibleExternalCommands` for this or future checks** unless a specific case genuinely can't be satisfied by calling the external command from inside a whitelisted function's body instead.

<a id="a-real-incident-along-the-way-and-a-real-debugging-methodology-lesson"></a>

## A real incident along the way, and a real debugging-methodology lesson

**The `VisibleExternalCommands` test appeared to break the entire `WinrmProbe` endpoint — it hadn't.** After populating `VisibleExternalCommands`, testing via a hand-written diagnostic script against `configuration_name="WinrmProbe"` failed with `"The syntax is not supported by this runspace. This can occur if the runspace is in no-language mode"` — for *every* function call, including long-established ones like `Get-RebootPendingStatus`. This persisted through reverting `.psrc`/`.psm1` to their exact original content, restarting WinRM, a full VM reboot, and a clean unregister/re-register of the session configuration — none of it helped, and one remediation attempt (`Register-PSSessionConfiguration -Force`) briefly left the registered config pointing at a `.pssc` file that had gone missing from disk, a real if self-inflicted regression, repaired directly.

**The actual cause: the diagnostic script used `ps.add_script(function_name)`, not `ps.add_command()`/`add_cmdlet()`.** `RestrictedRemoteServer` JEA sessions run in `NoLanguage` mode for the caller — `add_script()` sends the invocation through PowerShell's language parser, which `NoLanguage` mode rejects outright, regardless of whether the function is whitelisted. The real proxy (`winrm_executor_pypsrp.py`) has always used `ps.add_cmdlet(function_name)` — a structured PSRP command invocation that bypasses the script parser entirely, which is why the real proxy and every previous check's "verified via the real constrained path" testing was never affected by this at all. Every ad-hoc diagnostic script written *during this specific investigation* used the wrong method, producing a false "the endpoint is broken" signal — confirmed once `add_cmdlet()` was used instead: the endpoint had been fine the whole time.

**Generalizes:** any future ad-hoc/manual testing directly against a `RestrictedRemoteServer` JEA session (as opposed to calling it through the real proxy) needs `add_command()`/`add_cmdlet()`, never `add_script()`, or it will produce misleading "no-language mode" failures on completely healthy functions.

<a id="data-source-and-locale-handling"></a>

## Data source and locale handling

**`w32tm /query /status` gives Stratum/Root Delay/Root Dispersion/the reference source; offset needs a separate live query.** The default field dump has no offset value at all. `/stripchart` gives it, but only against a real peer — `/stripchart /computer:localhost` **times out** (confirmed by real testing, `error: 0x800705B4`), so the check queries the actual detected reference IP instead (which is also semantically correct: offset is "how far am I from my sync source," not "how far am I from myself").

**Locale risk is real and only partially mitigated — this fleet is English (`en-US`, confirmed via `Get-Culture`/`Get-UICulture`), but this project may go public, so "our fleet is English" isn't a safe assumption to build on.** Two different techniques ended up in the final design, deliberately not the same one everywhere:

- **`ReferenceIp`** is extracted by IP-address *shape* (`\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}`), not by matching the `"(source IP: ...)"` label around it — genuinely locale-independent, confirmed working the same way regardless of what the surrounding text says.
- **`Stratum`/`RootDelay`/`RootDispersion`** are parsed by fixed **line position** (line 1, 3, 4 of the 9-line `/query /status` output) rather than by matching English label text — the field *order* is not expected to change across locales even though the *labels* are known to be localized. **Not verified against a non-English target** (none available) — a real, documented residual risk, not a guarantee.

**A genuinely locale-independent alternative was investigated and set aside.** Windows exposes a `"Windows Time Service"` performance-counter category (`NTP Roundtrip Delay`, `Computed Time Offset`, etc.) queryable by **numeric object/counter index** via the always-present, language-neutral `HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Perflib\009` registry key (`009` = English, kept on every Windows install regardless of display language specifically to serve as this kind of neutral lookup table) — confirmed working for real (`Get-Counter -Counter "\4772\4774"` returned a live value with zero English strings anywhere in the call). **Not used for the final design**: a same-instant correlation test against `/stripchart`'s live offset didn't match (444 vs. ~1539 microseconds in one pairing, 0 vs. ~1471 in another, taken right after a forced `/resync`) — `Computed Time Offset` only updates on `W32Time`'s own internal correction cycle (here, `~1024s`/17min), not on demand, so it measures a materially different, staler thing than a live `/stripchart` query against the current moment. Worth revisiting if `Stratum` itself is ever found to have a genuinely locale-independent counter-based source (it doesn't appear to — no stratum counter exists in this counter set), but not pursued further once the semantic mismatch was confirmed.

<a id="a-second-jea-parameter-set-surprise-also-inside-a-whitelisted-function"></a>

## A second JEA parameter-set surprise, also inside a whitelisted function

**`Select-Object -Last 1` is not reachable inside a whitelisted function's body, even though the internal-call principle above says JEA shouldn't care.** First version of the offset-parsing logic used `$stripchartLines | Where-Object {...} | Select-Object -Last 1` — worked perfectly via `debugadmin`'s unconstrained session, and failed via the real constrained proxy path with `"A parameter cannot be found that matches parameter name 'Last'"`. Confirmed for real (not assumed) that this is JEA-specific: identical code, same function, only the session type differed. This means the "internal calls are invisible to the whitelist" principle has a real, narrower exception: at least some core cmdlets appear to load with a **reduced parameter set** inside a `RestrictedRemoteServer` session's module-autoloading-disabled environment, not just a restricted top-level command surface. Fixed by avoiding the cmdlet parameter entirely — plain array indexing (`$matchingLines[$matchingLines.Count - 1]`) is a language feature, not a cmdlet parameter, and isn't affected.

**Generalizes:** don't assume a cmdlet's *full, normal* parameter set is available just because the cmdlet itself is reachable inside a whitelisted function body — confirmed for `Select-Object -Last` specifically; worth testing any other less-common cmdlet parameter the same way before relying on it inside a JEA function, rather than assuming parity with an unconstrained session.

<a id="jea-function"></a>

## JEA function

```powershell
function Get-NtpSyncStatus {
    [CmdletBinding()]
    param()

    $statusLines = @(& 'C:\Windows\System32\w32tm.exe' /query /status 2>$null)

    if ($statusLines.Count -lt 9) {
        [pscustomobject]@{
            Stratum        = $null
            RootDelay      = $null
            RootDispersion = $null
            ReferenceIp    = $null
            Offset         = $null
            Source         = $null
        } | ConvertTo-Json -Compress
        return
    }

    $stratum = $null
    if ($statusLines[1] -match '(\d+)') {
        $stratum = [int]$Matches[1]
    }

    $rootDelay = $null
    if ($statusLines[3] -match '([\d.]+)s') {
        $rootDelay = [double]$Matches[1]
    }

    $rootDispersion = $null
    if ($statusLines[4] -match '([\d.]+)s') {
        $rootDispersion = [double]$Matches[1]
    }

    $referenceIp = $null
    if ($statusLines[5] -match '(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})') {
        $referenceIp = $Matches[1]
    }

    # Line position (not label text), matching the same locale
    # discipline as Stratum/RootDelay/RootDispersion above -- everything
    # after the first colon on the line, trimmed, not a match against
    # the English word "Source".
    $source = $null
    if ($statusLines[7] -match ':\s*(.+?)\s*$') {
        $source = $Matches[1]
    }

    $offset = $null
    if ($referenceIp) {
        $stripchartLines = @(& 'C:\Windows\System32\w32tm.exe' /stripchart "/computer:$referenceIp" /period:1 /samples:1 /dataonly 2>$null)

        $matchingLines = @($stripchartLines | Where-Object { $_ -match '([+-][\d.]+)s' })
        $lastLine = if ($matchingLines.Count -gt 0) { $matchingLines[$matchingLines.Count - 1] } else { $null }

        if ($lastLine -match '([+-][\d.]+)s') {
            $offset = [double]$Matches[1]
        }
    }

    [pscustomobject]@{
        Stratum        = $stratum
        RootDelay      = $rootDelay
        RootDispersion = $rootDispersion
        ReferenceIp    = $referenceIp
        Offset         = $offset
        Source         = $source
    } | ConvertTo-Json -Compress
}
```

Three independently-confirmed real states, all returning valid (not error) output:
- **Fully synced**: all six fields populated.
- **Running, not yet synced** (e.g. just after service start): `Stratum`/`RootDelay`/`RootDispersion` come back as real zero values (`w32tm`'s own `"unspecified"` state — `Stratum: 0 (unspecified)`, `ReferenceId: 0x00000000 (unspecified)`), `ReferenceIp`/`Offset`/`Source` come back `null`.
- **`W32Time` not running at all**: `/query /status` prints a single error line instead of the normal 9-line dump (`"The following error occurred: The service has not been started."`) — the `Count -lt 9` guard catches this, returns all six as `null`.

**`Source` added 2026-08-11 — see "`peerref` was always empty" below for the full investigation.**

<a id="proxy-side"></a>

## Proxy side

`app/checks/ntp_sync_status.py` (`alexh/librenms-bits`) validates the six-field shape (five originally, `Source` added 2026-08-11 — see "`peerref` was always empty" below), all independently nullable — no field is required to be present-and-non-null for `ok: true`. Registered in `registry.py` as `"ntp-sync-status"`. 12 new tests (fully-synced, not-yet-synced, service-stopped, offset-null-while-otherwise-synced, and the usual malformed-output/executor-failure cases), 162/162 for the full proxy suite at original ship time; 164/164 after the `Source` addition.

<a id="winrmpollerphp-side-librenmscomponent-not-sensor"></a>

## `WinrmPoller.php` side: `LibreNMS\Component`, not `Sensor`

**Real research before assuming `Sensor` fit, per the standing "match the SNMP shape" convention** — and it didn't. The real SNMP-equivalent (`includes/discovery/ntp/cisco.inc.php`, `includes/polling/ntp/cisco.inc.php`) uses `LibreNMS\Component` (`type='ntp'`) plus a direct `stratum`/`offset`/`delay`/`dispersion` RRD write, with its own dedicated device-apps page (`includes/html/pages/device/apps/ntp.inc.php`) and four graphs (`device_ntp_stratum`/`offset`/`delay`/`dispersion`) that key directly off that shape — not `Sensor` at all, unlike every other check in this project. Matching it gets that page and those graphs working with **zero new UI code**. (Also confirmed while researching this: `NtpProbe` doesn't exist anywhere in this codebase — the same "assumed real, wasn't" mistake `WINRM_DESIGN.md` already flagged once for this exact name, now flagged a second time from a different handoff, worth remembering as a name that keeps getting assumed real.)

**Real semantic gaps, closed rather than assumed away:**
- **One component, not several.** The native-MIB-based module's NTP-MIB implementation can report multiple *configured* peers (a real, plural discovery). `w32tm` only ever reports one *current* sync source — exactly one synthetic `Component` per device, keyed by the reference IP, not a discovered list.
- **Windows' "not synced" sentinel is `Stratum 0`, not the classic NTP-protocol sentinel of `Stratum 16`.** Confirmed by real testing (stopped/restarted `W32Time`, read `/query /status` before the first resync completed: `Stratum: 0 (unspecified)`). Blindly reusing the native-MIB-based module's `stratum === 16` check would have silently never flagged a genuinely-unsynced Windows host as bad — this is exactly the class of mistake "match the SNMP shape" exists to catch, not something the convention excuses skipping the check for.
- **`RootDelay`/`RootDispersion` are mapped to the `delay`/`dispersion` RRD datasets as the closest available analog, not an exact semantic match** — the native-MIB-based module's per-peer delay/dispersion come from a live NTP protocol exchange with that specific peer; Windows' Root Delay/Dispersion are the *cumulative* values through the whole reference chain back to the stratum-1 source. Documented as such rather than implied identical just because the dataset names match.

`discover()` creates the `Component` (and its `applications` row) only if a reference IP is available yet — skipped gracefully, not an error, if not. `poll()` refreshes `stratum`/`status`/`error` and writes the RRD every cycle, matching `cisco.inc.php`'s own re-derive-every-poll pattern (not a one-time discover() decision). `dataExists()`/`cleanup()`/`dump()` extended; `cleanup()`'s component deletion was confirmed (by reading `2018_07_03_091322_add_foreign_keys_to_component_prefs_table.php` directly) to rely on a real DB-level `ON DELETE CASCADE` from `component_prefs` back to `component.id`, not assumed.

<a id="two-real-bugs-caught-by-testing-against-a-drifted-clock-before-shipping"></a>

## Two real bugs caught by testing against a drifted clock, before shipping

The handoff's explicit instruction to test against a deliberately-drifted/desynced clock, not just the happy path, caught two real bugs that a synced-only test would have missed entirely:

1. **RRD values were being written into the wrong dataset slots.** `Datastore::put()`'s own docblock says the fields array is positional — `"the order must be consistent with rrd_def"` — but the first version conditionally omitted a field's key when its value was `null`, which silently shifted every *later* value into the wrong slot the moment any one field was missing. Caught by reading a real written RRD file directly (`ds[offset].last_ds` held what was actually the delay value), not assumed correct from the code alone. Fixed: always write all four keys, in `RrdDefinition`'s exact declared order, using `null` (RRD's native "unknown") for anything not available that cycle, rather than omitting the key. The corrupted RRD file was deleted and confirmed rewritten correctly afterward.
2. **A `null` `Stratum` (service not running at all) wasn't flagged as a bad/unsynced state.** `is_int(null)` is `false`, so the original bad-stratum check silently evaluated to "not bad" for the one state that's arguably worse than a known `Stratum 0`. Caught by literally doing what the handoff asked — stopping `W32Time` and polling for real — and finding the `Component` still reporting `status=0` ("Ok"). Fixed with an explicit `null` case and a more specific error message (`"NTP status unavailable (W32Time not running?)"`) than the generic "NTP is not in sync".

Also fixed in the same pass, found by inspection rather than a failing test: `offset`'s `RrdDefinition` had `min=0`, copied verbatim from `cisco.inc.php` — but that file's own offset is decoded from a signed 16-bit field, and `w32tm`'s is explicitly signed too (its own `+/-D.DDDDDDDs` format). `min=0` would silently record any negative-offset datapoint as unknown. Looks like a real, apparently-unnoticed edge case in the upstream precedent being matched — fixed here rather than knowingly reproduced.

<a id="peerref-was-always-empty-the-peer-reference-ui-column-fix"></a>

## `peerref` was always empty — the "Peer Reference" UI column fix (2026-08-11)

`WinrmPoller.php` shipped with `'peerref' => ''` hardcoded in both `discoverNtpComponent()` and `pollNtpSyncStatus()`, leaving `ntp.inc.php`'s real "Peer Reference" table column permanently blank. Investigated as a possible transient "not yet discovered" state, a possible label/naming mismatch (`'ntp'` vs. some other `Component` `type` string), and finally as a genuine missing-data problem — the last one held up.

**Ruled out first, both for real:**
- **Not transient.** Three more `device:discover`/`device:poll` cycles against the live target left the `Component`'s underlying `Application` row's `app_state`/`timestamp` completely unchanged — nothing in this check's own code path ever revisits this value after the first poll, at any cycle count.
- **Not a naming mismatch.** `cisco.inc.php` hardcodes `$module = 'ntp';` (read directly) — the real precedent genuinely displays as `"NTP"` in the Apps menu (`LibreNMS/Util/StringHelpers.php`'s `'ntp' => 'NTP'` entry), not `"NTP Client"` (a separate, unrelated, `Application`/SNMP-extend-based app on other devices, confirmed via `includes/polling/applications/ntp-client.inc.php`'s `json_app_get()` call — a genuinely different real app that happened to surface during an earlier, adjacent investigation).

**The real gap: `Get-NtpSyncStatus` only ever parsed 4 of `w32tm /query /status`'s 9 lines** (`Stratum`/`RootDelay`/`RootDispersion`/`ReferenceId`) — line 7, `Source:`, was never read at all. `Peer Reference` maps to the native-MIB-based module's real `peerref` component pref, populated in `cisco.inc.php` from a distinct `CISCO-NTP-MIB` column (`IP::fromHexString(...)`) — a real, separate value from `peer`, not a duplicate.

**Checked directly on the real target (not assumed) whether `Source` and the already-captured `ReferenceIp` describe the same host or genuinely different ones**, since that determined whether `Source` should *replace* `peer` or merely fill the empty `peerref` column. Via `debugadmin` (bypassing JEA for a read-only diagnostic, same as always):
```
ReferenceId: 0xC0000264 (source IP:  192.0.2.100)
Source: dc1.vpp.local
```
`dc1.vpp.local` resolves (confirmed via DNS) to `192.0.2.100` — the same host, on this real target. **Deliberately did not swap `peer`/`$effectivePeer` (and therefore the RRD filename and the `/stripchart` offset-query target) over to `Source`** despite `Source` being, per Microsoft's own docs, the more conventionally-named "who I sync with" field — there's no real evidence either way (on this one target) whether `Source` and `ReferenceIp` can genuinely diverge in a deeper hierarchy, and `peer` already drives two already-shipped, already-tested things (the RRD filename, the offset query) that a value swap would put at risk of the same orphaned-data problem this project has hit twice before (`network-traffic`'s `Sensor` pair, `winupdate-pending`'s old `Sensor` row) — not worth it to fix a purely cosmetic column. `Source` was added purely alongside the existing five fields, feeding only `peerref`.

**Deployed and verified for real, full stack:**
- `Get-NtpSyncStatus` updated on `winrm-test01.vpp.local` via `debugadmin` (`.psm1`-only, hot-reloaded, no `-Force`/restart) — verified through the real constrained path (`svc-winrmproxy`/Kerberos/`WinrmProbe`, `add_cmdlet()` not `add_script()`): `{"Stratum":4,...,"Source":"dc1.vpp.local"}`.
- `app/checks/ntp_sync_status.py` (`alexh/librenms-bits`) updated to validate/pass through the new `Source`/`source` field; 2 new tests, 164/164 proxy suite passing; proxy container rebuilt and redeployed on `lnms-dev-winrm`.
- `WinrmPoller.php` updated: `peerref` now reads `$ntpStatus['source'] ?? ''` in both `discoverNtpComponent()` and `pollNtpSyncStatus()`. `phpstan`/`php -l` clean.
- Real end-to-end poll against device 5 confirmed the "Peer Reference" column populated with `dc1.vpp.local`.

<a id="the-icon-and-the-ntp-label-reopened-and-fixed-for-real"></a>

## The `?` icon and the "NTP" label — reopened and fixed for real (2026-08-11)

Both were originally closed as "matches the real existing precedent, not a bug." Both closures were wrong, on two different grounds — one a real design-standard inconsistency, one a factual error corrected by a primary source.

**The `?` icon.** `cisco.inc.php`'s own real polling (`includes/polling/ntp/cisco.inc.php`, confirmed by reading it directly) genuinely never touches the placeholder `applications` row's `app_state` either — the earlier "matches the precedent" claim was factually accurate. But this check already broke from that exact precedent once for a real reason: `offset`'s `RrdDefinition` had `min=0`, copied verbatim from `cisco.inc.php`, and was fixed rather than reproduced once found wrong (see above). "The precedent has this edge case too" was never the actual standard being applied here — a permanently `?`/"Unknown State" icon on a check that's confirming real, correct sync data every cycle is wrong on its own terms, independent of what the existing module does. Fixed by reusing `update_application()` (the same real function `winupdate-pending`'s `Application`-based `app_state` promotion already relies on) in both `discoverNtpComponent()` and `pollNtpSyncStatus()`, via a new small shared helper (`ntpApplicationResponse()`) that derives `update_application()`'s `$response`/`$status` from the exact same `$isBad`/`$errorMessage` already computed for the `Component`'s own status/error fields — so the Apps-tab nav icon and the `ntp.inc.php` page's own in-table status can't drift into two different readings of the same poll cycle.

Verified via the same reflection-based technique used for `winupdate-pending`'s hard-to-force states (real shipped method, only the `WinrmProxy` network boundary mocked, since forcing a real `Stratum 16`/service-stopped state on the shared live target again wasn't necessary — the underlying `$isBad`/Component-status logic was already drift-tested for real at original ship time, this only needed to confirm the *new* `update_application()` wiring):
- `Stratum: 16` → `app_state='ERROR'`, `fa-close` icon (previously stuck at `?`).
- Service stopped (`stratum: null`) → `app_state='ERROR'`.
- Healthy (`Stratum: 4`) → `app_state='OK'`, no icon.
Real device re-polled afterward to restore its genuine live state (`OK`, `Stratum 4`) before leaving it as a live fixture.

**The label.** The earlier "keep `'ntp'` labeled as `NTP`, the existing module might monitor something broader than client-sync" reasoning doesn't survive contact with the module's own documented scope. `cisco.inc.php` was proposed as `librenms/librenms#3999`; the author's own words: *"This is a new Discovery/Poller module to collect NTP statistics from devices which support the CISCO-NTP-MIB. Discovered peers are stored using components... A critical alarm is raised when a stratum of 16 is reported."* "Discovered peers" + "alarm on stratum 16" is unambiguous client-side sync monitoring — the same real concept `ntp-client` (a separate, SNMP-extend-based app) names directly, just via a different mechanism (native MIB vs. extend script), not a different scope. First attempt (superseded, see below): changed `StringHelpers.php`'s shared `'ntp' => 'NTP'` entry to `'ntp' => 'NTP Client Sync'`. Reverted.

<a id="the-label-is-ntp-client-via-a-distinct-type-and-a-widened-shared-filter-not-a-fork"></a>

## The label is "NTP Client", via a distinct type and a widened shared filter, not a fork (2026-08-12)

**Directive, final: the label is `"NTP Client"` verbatim, and `StringHelpers.php`'s `'ntp'`/`'ntp-client'` entries stay untouched.** Satisfying that for real requires this check's own distinct `Component.type`/`Application.app_type` (`NTP_TYPE = 'ntp-client-winrm'` in `WinrmPoller.php`, replacing every `'ntp'` literal used for the type/RRD-name-prefix), since reusing `'ntp'` was what made this check's original design get `ntp.inc.php`'s Apps page and four graphs "for free" — a distinct label needs a distinct DB value, since `StringHelpers.php`'s lookup is keyed off it. `StringHelpers.php` gets a genuinely third, separate entry: `'ntp-client-winrm' => 'NTP Client'`.

**First implementation of this (commit `f8c0cd780`) forked the Apps page and all four graphs into five new WinRM-specific files — reverted (commit `8db845bf1`).** A bigger footprint in shared/core LibreNMS UI territory than this project has touched anywhere else in its history, and not the intent. **The actual fix: widen the five existing shared files' hardcoded `type` filter to accept both `'ntp'` and `'ntp-client-winrm'`, in place, rather than duplicating them.**

`Component::getComponents()`'s own `$options['filter']` mechanism forwards its operator directly to a single-value Eloquent `where($field, $op, $value)` call (confirmed by reading `LibreNMS/Component.php` directly) — no `IN`/array-value support. So the widening is a plain **post-fetch PHP filter**, not a query-level `IN` clause: each of the five files now fetches all of a device's components (no `type` filter at the query level) and keeps only `in_array($c['type'] ?? null, ['ntp', 'ntp-client-winrm'], true)`. The four graph files also needed their RRD-filename construction changed from the hardcoded `['ntp', $peer]` to `[$array['type'], $peer]` — using each component's own real `type` value as the RRD prefix, not a fixed string — since `WinrmPoller.php` writes its RRD file under the `ntp-client-winrm-<peer>.rrd` name, which the old hardcoded-`'ntp'`-prefix graph code would never have found.

**Verified end-to-end for real, both directions:**
- Real device 5 (WinRM): `device:poll` succeeded; the widened `ntp.inc.php` (rendered directly, not just "the filter compiles") shows the real peer (`192.0.2.100`) and `peerref` (`dc1.vpp.local`); all four widened graphs (`device_ntp_stratum`/`offset`/`delay`/`dispersion` — the original, shared graph type names, not new ones) resolve via `Graph::getRrdOptions()` to the real `ntp-client-winrm-192.0.2.100.rrd` file.
- **Real native-MIB-monitored devices unaffected — checked directly, not assumed**, since none exist on this instance: a temporary `type='ntp'` `Component` + matching RRD file were created on a different device (device 1), confirmed the widened `ntp.inc.php` renders that peer/peerref correctly with **no cross-contamination** (device 5's WinRM peer does not leak into device 1's page, and vice versa), and all four widened graphs correctly resolve to the real native-MIB-style `ntp-<peer>.rrd` filename via the same `$array['type']`-driven logic. Test data deleted afterward.
- No orphaned-data cleanup was needed for this specific revert-and-redo — `NTP_TYPE`'s value (`'ntp-client-winrm'`) never changed between the forked-files attempt and this one, only how it's *read*, so the already-existing `Component`/`Application`/RRD rows from the previous verification pass were already correct and reused as-is.
- 6/6 `WinrmPollerTest.php` PHPUnit tests pass; `phpstan`/`php -l` clean on every changed file.

<a id="two-real-bugs-found-by-actually-clicking-through-the-ui"></a>

## Two real bugs found by actually clicking through the UI (2026-08-12)

The previous section's "verified end-to-end" wasn't quite that — both of these were found the next round by actually loading the pages in a browser, not by anything CLI-level.

**Bug 1: the Apps page rendered completely blank.** All of the "verified end-to-end" checks above were real, but every one of them either called `view()->render()`/`Graph::getRrdOptions()` directly or read the file on disk — none of them went through an actual authenticated HTTP request against the live site the way a browser does. That gap mattered: `includes/html/pages/device/apps.inc.php` (the dispatcher) resolves which file to include from `Application.app_type` directly — `Clean::fileName($app->app_type) . '.inc.php'` — completely independent of `Component.type`. `Application.app_type` is `NTP_TYPE` (`'ntp-client-winrm'`, needed for `Application::displayName()`'s `StringHelpers.php` lookup to produce the distinct "NTP Client" label), so the dispatcher looked for `includes/html/pages/device/apps/ntp-client-winrm.inc.php` — a file that stopped existing the moment the forked-files version was reverted. `is_file()` returning false there isn't an error, just a silent no-op (`apps.inc.php`'s `if (is_file($include_file)) { include $include_file; }` has no `else`) — so the page returned `200`, the tab nav rendered correctly (it doesn't depend on the file existing), and everything below the panel heading was empty, with nothing in any log to point at.

**The real, previously-unrecognized tension**: `Application.app_type` is used for two different things at once — the page-routing filename *and* the label (via `displayName()`) — and this check's two real requirements (route to the shared, already-widened `ntp.inc.php`; have its own distinct label) turned out to need two different values of the same field. Resolved with a one-line routing shim, not a full fork:
```php
<?php
require 'includes/html/pages/device/apps/ntp.inc.php';
```
as `includes/html/pages/device/apps/ntp-client-winrm.inc.php` — satisfies the dispatcher's filename requirement, delegates 100% of the actual rendering to the real, shared, already-widened `ntp.inc.php`. Not a duplicate in the sense the earlier forked-files revert was about — a few lines, no logic, the smallest possible thing that can be named `ntp-client-winrm.inc.php`. The four graphs needed no equivalent shim: they're referenced by hardcoded `device_ntp_*` type strings *inside* `ntp.inc.php` itself, not looked up via `app_type` at all, so they were never affected by this.

**Verified via a real, authenticated HTTP request this time, not CLI/script-level rendering** — the same class of CLI-vs-real-serving gap already seen once in this project (`base_url`, served correctly from every CLI check while the live site kept serving something else), now confirmed to also apply to file-existence-driven routing, not just config caching. A temporary local admin user (`user:add`, deleted immediately after) logged in for real via `curl` (CSRF token + session cookie, matching how a browser actually authenticates), then:
- `GET /device/5/apps/app=ntp-client-winrm/` — before the fix: `200`, 89825 bytes, tab nav present, content area empty. After: `200`, 102645 bytes, real table row (`192.0.2.100:123 | 4 | dc1.vpp.local`).
- `GET /graph.php?device=5&type=device_ntp_stratum&...` — real `200`, `image/svg+xml`, a genuine rendered graph, not an error placeholder.
- `GET /device/5/apps/app=os-updates/` — unaffected (`200`, unchanged content), confirming no regression on the unrelated check.

**Going forward for this project: any "verified end-to-end" claim for a change touching the served web UI needs a real authenticated HTTP request, not just CLI/script-level rendering** — this is the second time the CLI-vs-real-serving gap has produced a false "verified working" result.

**Bug 2: two Apps-menu entries both read "NTP Client".** `includes/html/pages/apps.inc.php` (the *global*, cross-device Apps menu — distinct from the per-device Apps tab) lists every distinct `app_type`'s group side by side, regardless of which devices back them — `ntp-client` (real SNMP-extend devices) and `ntp-client-winrm` (this check) sat next to each other, both displaying the literal text "NTP Client", correctly scoped to the right devices underneath but visually indistinguishable. The earlier "these two can never appear on the same device page" reasoning that justified reusing `ntp-client`'s exact label doesn't cover this: it was scoped to one device's own page, not a global listing where both groups are always visible together regardless of any single device's data.

Resolved by making the WinRM entry's own label distinct: `StringHelpers.php`'s `'ntp-client-winrm'` entry is `'NTP Client (WinRM)'`. `'ntp-client'`'s own real, shared entry stays untouched. Verified live (real HTTP, same discipline as Bug 1): `GET /apps/` shows `NTP Client` and `NTP Client (WinRM)` as visibly distinct entries; the per-device Apps tab (`/device/5/apps/app=ntp-client-winrm/`) shows the same disambiguated label, consistent everywhere `displayName()` is used, since it's the same one lookup.

<a id="global-ntp-peers-apps-page-missing-graphs-another-app_type-as-filename-gap"></a>

## Global "NTP Peers" apps page: missing graphs, another `app_type`-as-filename gap (2026-08-12)

**A third real, distinct occurrence of the same `Application.app_type`-as-filename dispatch pattern** (`http://.../apps/app=ntp-client-winrm` — the *global* apps page, `includes/html/pages/apps.inc.php`, not the per-device tab from Bug 1 above, and not the Apps-menu-*label* from Bug 2 — three genuinely different pages, three genuinely different files, all keyed the same way). Same root shape, same root cause: no `includes/html/pages/apps/ntp-client-winrm.inc.php` existed, so this dispatcher's own `is_file()` check silently fell through to `includes/html/pages/apps/default.inc.php`. Unlike Bug 1, that fallback isn't blank — `default.inc.php` renders the device panel/heading fine, but looks up `$graphs[$app->app_type]` (a big static array in `apps.inc.php`, no `'ntp-client-winrm'` entry) to decide which graphs to embed; with that undefined, its `foreach` over the missing key is a silent no-op — panel renders, zero graphs inside it. Confirmed via a real HTTP request, not assumed from the code: `GET /apps/app=ntp-client-winrm` showed the `winrm-test01.vpp.local` panel with no stratum/offset/delay/dispersion graphs anywhere in its body.

**The real existing precedent (`includes/html/pages/apps/ntp.inc.php`) doesn't use `default.inc.php`/`$graphs` at all** — it's a dedicated page rendering a `bootgrid` AJAX table (`ajax_table.php` → `includes/html/table/app_ntp.inc.php`, dispatched by a POST `id` parameter the same `basename()`-as-filename way), listing every NTP peer across every device with an optional embedded graph column. `app_ntp.inc.php` had its own real, single-value `$options['type'] = 'ntp';` Component filter (line 6) — same widening treatment as `ntp.inc.php`'s device-page version: fetch without a query-level type filter, then `array_filter()` per device for `type IN ('ntp', 'ntp-client-winrm')`, since `Component::getComponents()`'s filter mechanism has no `IN`/array support (confirmed by reading it directly, same finding as before). Also caught, in the same file: `generate_device_link($device, null, ['tab' => 'apps', 'app' => 'ntp'])` (line 53) hardcoded `'ntp'` as the target `app_type` for every peer row's device link — harmless before this project (every real `Component` row genuinely was type `'ntp'`), a real bug now that a second type exists (would have linked a WinRM peer row to an `app_type` its own device doesn't have). Fixed to use `$array['type']`, the row's own real type, not a hardcoded literal.

The graph-type strings in `app_ntp.inc.php` (`device_ntp_stratum`/`offset`/`delay`/`dispersion`) needed no change — they route to the same `includes/html/graphs/device/ntp_*.inc.php` files already widened for Bug 1, which resolve their own RRD filename from each component's own `type` field, not a hardcoded string.

First fix attempt: `includes/html/pages/apps/ntp-client-winrm.inc.php` as a one-line routing shim delegating to the real, shared, now-widened `ntp.inc.php` bootgrid. Verified working via real HTTP including the AJAX call (`POST /ajax_table.php` returned a real `winrm-test01.vpp.local` row, correct `app=ntp-client-winrm` device link, real embedded graph) — but **superseded, not the right precedent.** `'ntp'` (the native-MIB-based module) is the *odd one out* here, not the template: it's the only real app type with its own bespoke dedicated page (the bootgrid). Every other real app type on this global listing — including `ntp-client` and `os-updates`, confirmed by checking directly (no `includes/html/pages/apps/{ntp-client,os-updates}.inc.php` file exists for either) — goes through the generic `default.inc.php` + `$graphs[$app->app_type]` mechanism instead. This check's own `app_type` is a generic entry conceptually much closer to `ntp-client`/`os-updates` than to the native-MIB-based module's bespoke `'ntp'` — matching the bootgrid was matching the wrong real precedent.

**Corrected fix**: the routing shim removed; `$graphs['ntp-client-winrm'] = ['stratum'];` added to `apps.inc.php`, one representative graph — matching `os-updates`' own real `['packages']` convention (a single clearest metric) rather than `ntp-client`'s two-entry one. `stratum` chosen deliberately, not the first-available default: the one value that answers "is this device's NTP sync currently healthy" at a glance — the same field `WinrmPoller::ntpStratumIsBad()` already treats as the health signal — offset/delay/dispersion are supporting detail already on the device's own Apps tab.

`default.inc.php`'s graph-type construction (`'application_' . $app->app_type . '_' . $graph_type`) needed a real, new graph file — `includes/html/graphs/application/ntp-client-winrm_stratum.inc.php`, modeled on `os-updates_packages.inc.php`'s real structure but genuinely different in one respect: this check's RRD data lives in a `Component`-scoped file (`WinrmPoller::NTP_TYPE`, `[NTP_TYPE, $peer]`, written by `pollNtpSyncStatus()`), not the standard `application_metrics`/`['app', $name, $app->app_id]` convention every other `application/*.inc.php` graph file assumes — this file looks up the device's own `ntp-client-winrm` `Component` (via `$app->device_id`, bound by `includes/html/graphs/application/auth.inc.php`) to find its real `peer` and build the matching RRD filename, mirroring `includes/html/graphs/device/ntp_stratum.inc.php`'s own lookup logic scoped to one device instead of looping all of them. Also fixed a real, pre-existing bug found while writing it: `os-updates_packages.inc.php` (and this project's own first draft, copied from it) sets `$scale_min`/`$scale_max` *after* `require`ing `common.inc.php` — but `common.inc.php` only honors those variables if they're already set at require-time, so the assignment has no effect; this file sets them before the require instead, rather than reproducing the same no-op.

`app_ntp.inc.php`'s widening (Component `type` filter, the `$array['type']` device-link fix) is kept even though it's no longer this URL's entry point — it's a real, independent, already-verified improvement to the native-MIB-based module's own bootgrid table itself: if a real `CISCO-NTP-MIB` device is ever discovered on this instance, its `/apps/app=ntp` peer list will correctly include WinRM peers alongside it too, a genuinely more complete "NTP Peers" view, not dead code.

**Verified for real — the actual rendered graph image, not text extraction**, per the explicit correction to how Bug 1 was originally (and insufficiently) verified: fetched the exact `<img>` URL generated by the live page (`graph_type=png` to get a raster image), downloaded it, and visually inspected the real PNG — a genuine rendered graph (axis 0–20, a visible data point around the real `stratum=4` value), not an error placeholder or blank image. `php -l` clean; `phpstan` warnings on the new graph file are the same class of pre-existing `$app`/`$device`-undefined warnings `os-updates_packages.inc.php` already has (confirmed by running `phpstan` against that real file directly) — an inherent property of this whole legacy `extract()`-driven graph-file family, not something introduced here. 6/6 `WinrmPollerTest.php` PHPUnit tests still pass.

<a id="investigation-does-w32tm-query-peers-expose-real-multi-peer-data"></a>

## Investigation: does `w32tm /query /peers` expose real multi-peer data?

**Question, and why it needed a real answer rather than an assumption.** The "one synthetic `Component` per device" decision above (see "One component, not several") was reasoned entirely from `/query /status`'s own output — the only `w32tm` subcommand this project had ever actually run. `/query /peers` is a real, distinct subcommand that (per general knowledge of `w32tm`, never itself checked here) can list every *configured* time source, not just the one currently active — a materially different, and previously untested, claim. Investigated for real (2026-08-12) against the same live target (`winrm-test01.vpp.local`), via the unconstrained `debugadmin` debug path (not the JEA whitelist — this is pure research, no product-path change), rather than assumed either way.

**Real output, `/query /peers /verbose`:**
```
#Peers: 1

Peer: dc1.vpp.local
State: Active
Time Remaining: 148.1996192s
Mode: 3 (Client)
Stratum: 3 (secondary reference - syncd by (S)NTP)
PeerPoll Interval: 9 (512s)
HostPoll Interval: 9 (512s)
Last Successful Sync Time: 8/11/2026 1:58:20 PM
LastSyncError: 0x00000000 (Succeeded)
LastSyncErrorMsgId: 0x00000000 (Succeeded)
AuthTypeMsgId: 0x0000009B (NtSignature )
Resolve Attempts: 0
ValidDataCounter: 8
Reachability: 255
```

**Answering the three questions from the investigation brief:**
1. **Does it list more than one source?** No — `#Peers: 1` on this real target. Structurally one, not an artifact of the command only showing "the current one": see the root-cause finding below.
2. **What real fields does it expose per peer?** Same well-structured `Key: value` text as `/query /status` (not messier) — `State`, `Mode`, `Stratum`, `PeerPoll`/`HostPoll Interval`, plus (verbose only) `Last Successful Sync Time`, `LastSyncError(MsgId)`, `AuthTypeMsgId`, `Resolve Attempts`, `ValidDataCounter`, `Reachability`. Genuinely richer per-peer detail than `/query /status` exposes today (`Reachability`/`ValidDataCounter` in particular have no current equivalent), but on this target there is only the one peer to show it for.
3. **Is the current peer identifiably distinct from others?** `State: Active` is the marker field — but with only one peer present on this target, this couldn't be verified against a real second, non-current entry. Not tested further once the root cause below made a multi-peer target unlikely to exist anywhere in scope (see "What would actually produce more than one peer" below).

**Root cause found, not just observed: this target's real `/query /configuration` output explains *why* there's only one peer, structurally — not by chance.**
```
NtpClient (Local)
...
Type: NT5DS (Local)
```
`NT5DS` is the domain-hierarchy sync mode — the default and expected mode for a domain-joined Windows box (this one included), where the client follows the AD domain hierarchy to a parent DC rather than a manually configured NTP server list. `/query /source` confirms the same single value (`dc1.vpp.local`) three different ways (`/query /status`'s `Source`, `/query /peers`'s sole entry, `/query /source`'s direct answer) — genuinely one source, not three commands disagreeing.

**What would actually produce more than one peer**: a box configured with `Type: NTP` (or `AllSync`) and a manually specified, space-separated `NtpServer` list (`w32tm /config /manualpeerlist:"ntp1.example.com ntp2.example.com" /syncfromflags:manual`) — a genuinely different configuration mode from `NT5DS`, not a variant of it. This project's real target, and by extension every domain-joined Server 2019+ fleet host this check is actually built for (see `WINRM_DESIGN.md`'s version-floor/fleet-standardization notes), runs in `NT5DS` mode by default — following the domain hierarchy is the normal, expected Windows configuration for a domain member, not an edge case. A manually-peered standalone NTP client is a real Windows configuration `w32tm` supports, but not the shape of host this check's fleet actually consists of.

**Conclusion: real data confirmed to exist, genuinely richer than what's captured today, but not worth the redesign cost — a "found real, decided not to build on it" outcome, not a dead end reached by assumption.** `/query /peers` does expose more fields than are captured today (`Reachability`, `ValidDataCounter` in particular), and on a differently-configured host it could genuinely list multiple sources — the mechanism itself is real. But the redesign this would justify (multiple `Component`s per device, closer to the native-MIB-based module's own per-peer model) is warranted only if real fleet targets actually have more than one peer to show, and the fleet this check is built for (domain-joined, `NT5DS`-mode Windows Server 2019+) structurally doesn't. **Not building the multi-`Component` redesign.** If `Reachability`/`ValidDataCounter` are ever wanted as additional single-peer detail (not a multi-peer redesign), they're available on the existing one-`Component`-per-device shape already in place — a much smaller, separate addition, not investigated further here since it wasn't what was asked.

<a id="investigated-reachability-validdatacounter"></a>

## Investigated: Reachability/ValidDataCounter

**The follow-up the investigation above deliberately deferred.** Both fields are present in `/query /peers /verbose`, on the *existing* single peer — no multi-peer redesign question attached, unlike the investigation above. Investigated for real (2026-08-13) rather than assumed from general `ntpq`-style conventions or the field names alone.

**`Reachability`: confirmed live, via a real forced state change, to be the classic NTP 8-bit "reach" shift-register.** Ran `w32tm /resync /rediscover` against the real target (`winrm-test01.vpp.local`, via the `debugadmin` unconstrained debug path — pure investigation, no product-path change) to reset the register, then sampled repeatedly through several fast (64s, ramped down from the steady-state 512s by `/rediscover` itself) poll cycles. Real, live values observed, each sample a genuine successful poll apart:
```
Reachability: 3  (0b00000011)
Reachability: 7  (0b00000111)
Reachability: 15 (0b00001111)
Reachability: 31 (0b00011111)
Reachability: 63 (0b00111111)
Reachability: 127(0b01111111)
Reachability: 255(0b11111111)  -- then holds at 255 through further successful polls
```
Each step is exactly `(previous << 1) | 1` — textbook 8-bit shift-register behavior: each successful poll shifts the register left and ORs in a 1 bit, `255` meaning the last 8 polls all succeeded. Not assumed from the name; watched it happen bit by bit in real output.

**Attempted the reverse (forcing a real degradation) — found a real, separate infrastructure issue instead.** Added a temporary outbound-UDP/123 firewall block on the target (`New-NetFirewallRule ... -Action Block`, confirmed created and enabled via `Get-NetFirewallRule`), expecting sync attempts to start failing. They didn't — `LastSyncError` stayed `0x00000000 (Succeeded)` and `Last Successful Sync Time` kept advancing every poll regardless of the block. Checked why rather than assuming the block was simply ineffective: `Get-NetFirewallProfile` shows **Windows Firewall is currently disabled across all three profiles (Domain/Private/Public) on this target** — `Enabled: False` for all of them. This is a real, pre-existing state on the target, unrelated to this investigation (the block rule was removed again immediately; the disabled-firewall state was not changed, since fixing it wasn't in scope for this task and touches the target's actual security posture — flagged here for whoever owns that target next, not silently worked around). The climb direction alone is unambiguous and was sufficient to confirm the semantics without needing the reverse direction.

**`ValidDataCounter`: confirmed, from the same real samples, to be capped at 8 and largely redundant with `Reachability`'s own bit-population count.** Tracked alongside the `Reachability` samples above:
```
Reachability=3   (2 bits) -> ValidDataCounter=1
Reachability=7   (3 bits) -> ValidDataCounter=2
Reachability=15  (4 bits) -> ValidDataCounter=3
Reachability=31  (5 bits) -> ValidDataCounter=4
Reachability=63  (6 bits) -> ValidDataCounter=5
Reachability=127 (7 bits) -> ValidDataCounter=6
Reachability=255 (8 bits) -> ValidDataCounter=7, then 8 on the NEXT successful poll -- then STOPS incrementing on all further successful polls, staying at 8 while Reachability holds at 255.
```
It's an uncapped-looking counter during the ramp-up (incrementing by exactly 1 per success, consistently one behind the register's bit-population count) but genuinely caps at 8 once the register fills — confirmed by watching two further successful polls land after the cap with `ValidDataCounter` unchanged. Once in steady state it's numerically redundant with `Reachability`'s own popcount; during the brief ramp-up transient it differs by exactly one, not a meaningfully different signal.

**Decision: add `Reachability`, do not add `ValidDataCounter` — a real, partial "yes" rather than an all-or-nothing call.**
- **`Reachability`: added, as a `Component` attribute (like `peerref`), not an RRD dataset.** A raw 0–255 bitmask isn't a naturally graphable continuous value the way `stratum`/`offset`/`frequency_ppb` are — a sawtooth/step line between 0 and 255 wouldn't tell an operator meaningfully more than the existing `stratum`-based bad/good detection already does. As a point-in-time attribute (same shape as `peerref`), it's cheap, architecturally consistent with the existing `Component`/`ComponentPref` mechanism, and gives a real, literal, Windows-native signal an operator debugging sync issues may already recognize from `ntpq`-style tooling elsewhere — without inventing a derived metric (a 0–8 count, a percentage) that the raw value doesn't need.
- **`ValidDataCounter`: not added.** Confirmed empirically redundant with `Reachability`'s own bit-population count once both reach steady state — storing both would be pure duplication with no independent signal, the kind of thing [[feedback_narrow_changes_no_standing_to_spend]] argues against adding.

**Built and verified end-to-end, real infrastructure throughout, same standard as every other field in this check:**
- `infra-bits:WINRM_JEA_WINDOWS.md`'s `Get-NtpSyncStatus` now also calls `w32tm /query /peers /verbose` (a separate invocation from the `/query /status /verbose` call already there) and parses `Reachability` from the peer block's last line — position-from-the-end, not a fixed absolute index or an English-label match, since `Reachability` is structurally the terminal field of a peer's verbose block regardless of which optional preceding fields a given peer state includes. Guarded on the real peer count being 1 (read from line 0's own number) before trusting the last line at all, matching this check's already-confirmed "one synthetic peer" fleet shape. Deployed and verified through the real constrained JEA path (not just `debugadmin`): `{"Stratum":4,...,"Reachability":255}`.
- `alexh/librenms-bits:winrm-proxy/app/checks/ntp_sync_status.py` validates/passes through `Reachability` (nullable int). 3 new tests (zero-after-rediscover, null-while-otherwise-synced, non-integer rejection), 171/171 for the full proxy suite. Proxy container rebuilt and redeployed.
- `LibreNMS/Modules/WinrmPoller.php`'s `discoverNtpComponent()`/`pollNtpSyncStatus()` both set `reachability` in `setComponentPrefs()`, same pattern as `peerref`. `php -l`/`phpstan` clean, 6/6 `WinrmPollerTest.php` tests pass.
- `includes/html/pages/device/apps/ntp.inc.php` gained a new "Reachability" column in the existing shared peer table, populated only when the Component has the attribute (`$peer['reachability'] ?? ''`) — blank for the native-MIB-based module's own `ntp` rows, which never set this `ComponentPref`, same graceful-absence pattern as `peerref` rather than a WinRM-only conditional gate (this is a single optional table cell, not a whole panel needing its own RRD/graph infrastructure the way Frequency/Phase Offset did).
- Verified for real, not just phpstan/phpunit clean: real `device:poll` against the live target populated a genuine `component_prefs` row (`{"component":2,"attribute":"reachability","value":"255"}`); real authenticated HTTP fetch (temp admin user, 2-step login, deleted after) of the live device Apps page confirmed the rendered HTML actually contains the new "Reachability" column header and a `255` value in the real row.

This closes out the last open thread from the `w32tm /query /peers` investigation — both of its follow-on fields have now been resolved with a real decision, not left open.

<a id="deploying-this-check"></a>

## Deploying this check

Incremental add — assumes `WINRM_JEA_SETUP.md`'s first-time setup already ran, plus all eight prior checks (see their own docs). Adds `Get-NtpSyncStatus` (shown in full above) and its `.psrc` whitelist entry. `VisibleExternalCommands` stays empty — see "Feasibility test" above for why. `.psrc`/`.psm1`-only — no `.pssc` change, no WinRM restart required for the function/whitelist itself (though see the real incident above: testing `VisibleExternalCommands` specifically did require a full unregister/re-register to fully reset afterward — not a concern for this final design, which never sets it).

```powershell
# Update-WinrmProbeJEA-NtpSyncStatus.ps1
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

function Get-HardwareInventoryStatus {
    [CmdletBinding()]
    param()

    $bios = Get-WmiObject -Class Win32_BIOS
    $baseBoard = Get-WmiObject -Class Win32_BaseBoard
    $chassis = Get-WmiObject -Class Win32_SystemEnclosure
    $operatingSystem = Get-WmiObject -Class Win32_OperatingSystem
    $computerSystem = Get-WmiObject -Class Win32_ComputerSystem
    $verKey = Get-ItemProperty -Path "HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion" -ErrorAction SilentlyContinue

    $releaseId = $null
    if ($verKey) {
        if ($verKey.DisplayVersion) {
            $releaseId = $verKey.DisplayVersion
        } elseif ($verKey.ReleaseId) {
            $releaseId = $verKey.ReleaseId
        }
    }

    $cpuKey = Get-ItemProperty -Path "HKLM:\HARDWARE\DESCRIPTION\System\CentralProcessor\0" -ErrorAction SilentlyContinue
    $processorIdentifier = $null
    if ($cpuKey -and $cpuKey.Identifier) {
        $processorIdentifier = $cpuKey.Identifier
    }

    $lnmsKey = Get-ItemProperty -Path "HKLM:\SOFTWARE\LibreNMS" -ErrorAction SilentlyContinue
    $contact = $null
    if ($lnmsKey -and $lnmsKey.Contact) {
        $contact = $lnmsKey.Contact
    }
    $location = $null
    if ($lnmsKey -and $lnmsKey.Location) {
        $location = $lnmsKey.Location
    }

    $processors = @(
        Get-WmiObject -Class Win32_Processor | ForEach-Object {
            [pscustomobject]@{
                DeviceId     = $_.DeviceID
                Name         = $_.Name
                Manufacturer = $_.Manufacturer
            }
        }
    )

    $memory = @(
        Get-WmiObject -Class Win32_PhysicalMemory | ForEach-Object {
            [pscustomobject]@{
                DeviceLocator = $_.DeviceLocator
                CapacityBytes = [int64]$_.Capacity
                Manufacturer  = $_.Manufacturer
                PartNumber    = $_.PartNumber
                SerialNumber  = $_.SerialNumber
            }
        }
    )

    $disks = @(
        Get-WmiObject -Class Win32_DiskDrive | ForEach-Object {
            [pscustomobject]@{
                DeviceId         = $_.DeviceID
                Model            = $_.Model
                SerialNumber     = $_.SerialNumber
                InterfaceType    = $_.InterfaceType
                FirmwareRevision = $_.FirmwareRevision
            }
        }
    )

    $networkAdapters = @(
        Get-WmiObject -Class Win32_NetworkAdapter -Filter "PhysicalAdapter=True" | Where-Object { $_.MACAddress } | ForEach-Object {
            [pscustomobject]@{
                MacAddress   = $_.MACAddress
                Name         = $_.Name
                Manufacturer = $_.Manufacturer
            }
        }
    )

    [pscustomobject]@{
        Bios = [pscustomobject]@{
            SerialNumber = $bios.SerialNumber
            Version      = $bios.SMBIOSBIOSVersion
            Manufacturer = $bios.Manufacturer
        }
        BaseBoard = [pscustomobject]@{
            Manufacturer = $baseBoard.Manufacturer
            Product      = $baseBoard.Product
            SerialNumber = $baseBoard.SerialNumber
        }
        Chassis = [pscustomobject]@{
            Manufacturer = $chassis.Manufacturer
            SerialNumber = $chassis.SerialNumber
            AssetTag     = $chassis.SMBIOSAssetTag
        }
        OperatingSystem = [pscustomobject]@{
            Caption                   = $operatingSystem.Caption
            Version                   = $operatingSystem.Version
            ReleaseId                 = $releaseId
            NumberOfLogicalProcessors = $computerSystem.NumberOfLogicalProcessors
        }
        Processors      = $processors
        Memory          = $memory
        Disks           = $disks
        NetworkAdapters = $networkAdapters
        ProcessorIdentifier = $processorIdentifier
        Contact = $contact
        Location = $location
    } | ConvertTo-Json -Compress -Depth 4
}

function Get-NtpSyncStatus {
    [CmdletBinding()]
    param()

    $statusLines = @(& 'C:\Windows\System32\w32tm.exe' /query /status 2>$null)

    if ($statusLines.Count -lt 9) {
        [pscustomobject]@{
            Stratum        = $null
            RootDelay      = $null
            RootDispersion = $null
            ReferenceIp    = $null
            Offset         = $null
        } | ConvertTo-Json -Compress
        return
    }

    $stratum = $null
    if ($statusLines[1] -match '(\d+)') {
        $stratum = [int]$Matches[1]
    }

    $rootDelay = $null
    if ($statusLines[3] -match '([\d.]+)s') {
        $rootDelay = [double]$Matches[1]
    }

    $rootDispersion = $null
    if ($statusLines[4] -match '([\d.]+)s') {
        $rootDispersion = [double]$Matches[1]
    }

    $referenceIp = $null
    if ($statusLines[5] -match '(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})') {
        $referenceIp = $Matches[1]
    }

    $offset = $null
    if ($referenceIp) {
        $stripchartLines = @(& 'C:\Windows\System32\w32tm.exe' /stripchart "/computer:$referenceIp" /period:1 /samples:1 /dataonly 2>$null)

        $matchingLines = @($stripchartLines | Where-Object { $_ -match '([+-][\d.]+)s' })
        $lastLine = if ($matchingLines.Count -gt 0) { $matchingLines[$matchingLines.Count - 1] } else { $null }

        if ($lastLine -match '([+-][\d.]+)s') {
            $offset = [double]$Matches[1]
        }
    }

    [pscustomobject]@{
        Stratum        = $stratum
        RootDelay      = $rootDelay
        RootDispersion = $rootDispersion
        ReferenceIp    = $referenceIp
        Offset         = $offset
    } | ConvertTo-Json -Compress
}

Export-ModuleMember -Function Get-RebootPendingStatus, Get-WuauservStatus, Get-W32timeStatus, Get-PendingUpdateStatus, Get-LocalDiskSpace, Get-NetworkInterfaceStats, Get-MemoryUsageStatus, Get-CpuUsageStatus, Get-HardwareInventoryStatus, Get-NtpSyncStatus
'@ | Set-Content -Path (Join-Path $moduleRoot 'WinrmProbeJEA.psm1') -Encoding UTF8

@'
@{
    Author = 'Your Name'
    CompanyName = 'VPP'
    Description = 'Constrained check functions exposed to the WinRM proxy service account.'

    VisibleFunctions = @('Get-RebootPendingStatus', 'Get-WuauservStatus', 'Get-W32timeStatus', 'Get-PendingUpdateStatus', 'Get-LocalDiskSpace', 'Get-NetworkInterfaceStats', 'Get-MemoryUsageStatus', 'Get-CpuUsageStatus', 'Get-HardwareInventoryStatus', 'Get-NtpSyncStatus')
    VisibleCmdlets = @()
    VisibleAliases = @()
    VisibleExternalCommands = @()
    VisibleProviders = @()
}
'@ | Set-Content -Path (Join-Path $roleCapDir 'WinrmProbe.psrc') -Encoding UTF8

Write-Host "Done, no WinRM restart performed. Validate with a NEW session:"
Write-Host "  Enter-PSSession -ComputerName $env:COMPUTERNAME -ConfigurationName WinrmProbe"
Write-Host "  Get-NtpSyncStatus   # expect JSON output; ReferenceIp/Offset null if not currently synced"
```

Then run `WINRM_JEA_SETUP.md` §7's validation checklist to confirm the constraint still holds. This is the ninth and last check documented so far — `WinrmProbeJEA.psm1` above is the project's complete, current module.
