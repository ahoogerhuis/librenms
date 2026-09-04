# Service Monitoring Patterns — Not Just "Is It Running"

`service-status-*` checks record raw `Status` (`Running`/`Stopped`/etc.) as a plain state sensor — no expected-state logic, no alerting built into the check itself. Thresholds are the operator's job via LibreNMS's existing sensor-limits UI, same as any other state sensor. That's the right tool for one kind of question, but not every service is best monitored that way. Worth documenting the distinction so future checks aren't forced into a mold that doesn't fit.

## Pattern 1 — "should always be running" (point-in-time state is meaningful)

**Example: `W32Time`.** An `Automatic`-start service expected to run continuously. Whether it's `Running` *right now* is a directly meaningful signal — if it's stopped, that's a real problem (Kerberos auth tolerance depends on clock sync). `service-status-w32time` fits this cleanly: raw state, operator sets a threshold expecting `Running`.

## Pattern 2 — "runs on-demand, point-in-time state is nearly meaningless" (needs a different signal entirely)

**Example: `wuauserv`.** Modern Windows (Server 2016+/Win10+) runs Windows Update on a **timer-driven trigger-start model, not as a continuously-running service** — the OS wakes `wuauserv` on its own schedule to check for/apply updates, then stops it again until the next scheduled cycle. This is the actual mechanical reason point-in-time state doesn't work here: there is no "should be running" moment to check against, because running is never the expected steady state at all — the service is *supposed* to spend most of its time stopped. Whether it happens to be `Running` at the exact moment LibreNMS polls is close to random noise; a snapshot of `Status` doesn't answer the question anyone actually cares about, which is closer to **"has this actually run and succeeded recently?"** — e.g. within the last 48 hours. That's precisely why a time-since-last-run signal is the right replacement for state-at-a-moment here, not just a stylistic preference.

`service-status-wuauserv` still has value (confirms the service isn't `Disabled` or stuck), but it cannot answer the "has it run recently" question — that data doesn't come from `Get-Service` at all. **This is a different check with a different data source, not a threshold tweak on the existing one.**

For Windows Update specifically, the meaningful signal is `LastSearchSuccessDate`/`LastInstallationSuccessDate`, available via the Windows Update Agent COM API — the same interface already scoped for the (not yet built) `winupdate-pending` check. Worth designing `winupdate-pending` to expose these timestamps alongside the pending-update count, rather than treating "has it run recently" as a fourth, separate check — the data lives in the same API call.

## Pattern 2, revisited — polling `winupdate-pending` actively perturbs `wuauserv`'s state (confirmed, 2026-08-10)

Once `winupdate-pending` went live on a device also running `service-status-wuauserv`, `wuauserv` stopped exhibiting its expected trigger-start pattern (mostly `Stopped`, brief `Running` windows) and instead read `Running` continuously for hours, at every regular ~5-minute poll. Confirmed as a real causal effect, not correlation, via a direct controlled test (SSH → proxy container → `pypsrp`, bypassing LibreNMS's own poll cadence entirely, LibreNMS polling paused on the device for the duration so the experiment stayed clean):

1. Baseline `wuauserv`: `Running` (matching the live device's actual state at the time).
2. Device polling disabled, zero WinRM calls of any kind made against the target for 12 minutes.
3. Re-checked `wuauserv`: **`Stopped`** — it settled back down on its own once nothing was calling `Get-PendingUpdateStatus`.
4. Immediately called `Get-PendingUpdateStatus` once, then re-checked `wuauserv`: **`Running`**, immediately.

Reproducible, bidirectional, and immediate (state flips within the same second `Get-PendingUpdateStatus` is called) — this isn't a slow drift or an unrelated coincidence. Mechanism: `Get-PendingUpdateStatus` calls `CreateUpdateSearcher().Search(...)`, a real Windows Update Agent search, every single poll. On a host where `winupdate-pending` is polled on the normal ~5-minute cadence, that's frequent enough to keep `wuauserv` continuously alive rather than letting it idle back down to its normal trigger-start resting state.

**This means Pattern 2's "point-in-time state is nearly meaningless" framing needed a real update, not just a footnote:** the original reasoning was "meaningless because it's close to random noise, timer-driven on its own schedule." On a host where `winupdate-pending` is *also* actively polled, it's no longer close to random — it's **actively pinned to `Running`by this project's own monitoring**, which is a different (and more directly misleading, if unrecognized) failure mode than the one originally documented. An operator setting a `service-status-wuauserv` threshold expecting `Running` on such a host would see a check that "passes" reliably — but for the wrong reason, not because Windows Update is healthy, but because the monitoring itself is holding the service awake.

**Practical implication:** `service-status-wuauserv` and `winupdate-pending` are **not actually independent checks when both are active on the same device** — polling one changes what the other measures. Doesn't invalidate either check individually, but worth knowing before reading anything into `wuauserv`'s state on a device where `winupdate-pending` is also polled.

**Fixed, not just documented (2026-08-10).** `winupdate-pending`'s `Get-PendingUpdateStatus` was rewritten to read Windows' own last scan-completion event from `Microsoft-Windows-WindowsUpdateClient/Operational` (Event ID 26) instead of calling `CreateUpdateSearcher().Search(...)` — confirmed via the same real-controlled-test discipline used to find the problem that this never touches `wuauserv` at all (Event Log is a separate, always-on service). Full detail: `docs/WINRM_WINUPDATE_PENDING_CHECK.md`'s v2 section and `WINRM_JEA_WINDOWS.md` §2f (`vpp/infra-bits`). The interaction described above was real and reproducible while v1 was live; it no longer applies now that v2 is deployed. Real tradeoff traded for it: no more `CriticalCount`/`ImportantCount` severity breakdown (this event log has no per-severity detail), gained a `LastScanTime` field instead — which happens to be exactly the "has it run recently" signal this doc's own Pattern 2 reasoning already called for.

## General principle for future checks

Before wiring a new service into `service-status`, ask which pattern actually applies:
- **Continuously-running service, state itself is the signal** → `service-status-*`, plain state sensor, done.
- **On-demand/trigger-start service, "did it do its job recently" is the real question** → point-in-time `Status` won't answer this; look for a "last run/success" data source specific to that service (event log, a service-specific API, a timestamp file) rather than defaulting to `Get-Service`.

Other services likely to hit Pattern 2 if added later: Windows Backup-related trigger-start services, scheduled-task-driven services generally (where "last run time" comes from the Task Scheduler API, not `Get-Service` at all), and any service Microsoft has moved to demand-start in recent Windows versions — worth checking a candidate service's actual `StartType` before assuming Pattern 1 applies.
