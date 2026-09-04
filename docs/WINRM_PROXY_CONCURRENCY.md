# WinRM Proxy — Concurrency Limiting and Session Pooling

## The problem, stated concretely

Every check invocation (`WinrmExecutorPypsrp.invoke_function()`) opened a brand-new `WSMan` connection, `RunspacePool`, and `PowerShell` session from scratch — full Kerberos handshake, full PSRP session negotiation — every single time, for every check, against every host. No reuse anywhere.

At single-test-target scale this is invisible. At real fleet scale it's two separate problems:

1. **Overhead** — constant session teardown/setup load on the proxy, the target's WinRM listener, and the DC (a fresh Kerberos exchange every call, not amortized across the many checks run against the same host every poll cycle).
2. **Cascading failure risk (the more urgent one).** The `/check` endpoint is a synchronous FastAPI handler, so each in-flight request ties up a worker thread for its full duration, including however long a hung connection attempt takes. If several hosts become simultaneously unreachable (a firewall change, a DC outage affecting Kerberos for many hosts at once), every affected request hangs concurrently, and with enough of them, the proxy's available worker threads run out — making it **unresponsive even to hosts that are perfectly healthy**, not just the broken ones.

## Decision: concurrency limiting + explicit timeouts now, session pooling deferred

Two complementary mitigations, deliberately sequenced rather than bundled:

### 1. Concurrency limiting — built (2026-08-10)

A global `threading.Semaphore` in `WinrmExecutorPypsrp` bounds how many outbound WinRM connection attempts can be in flight simultaneously, regardless of how many requests the proxy is trying to serve. If 50 hosts go dark at once, only `max_concurrent_connections` (default 10) connection attempts are ever hung at a time — the rest queue behind the limiter rather than each grabbing and holding a worker thread. This directly prevents total thread-pool exhaustion without needing to solve session reuse first.

**Global, not per-host, deliberately.** A per-host limiter would protect against hammering one host but wouldn't, by itself, prevent the actual failure mode described above: many *different* hosts going dark simultaneously, each grabbing its own slot, still exhausts every worker thread. The semaphore lives on the single `WinrmExecutorPypsrp` instance `main.py` constructs once and reuses across every request — genuinely global across the whole proxy process.

**Paired with an explicit, deliberate timeout, not left as `pypsrp`'s implicit default.** A concurrency limit doesn't help much if each held slot can hang indefinitely — bounding *how many* can hang and bounding *how long* each one can hang are two halves of the same fix. `pypsrp`'s `WSMan` timeout defaults (`operation_timeout=20`, `connection_timeout=30`, `read_timeout=30`) were discovered by reading its actual `__init__` signature, not documentation — previously completely invisible anywhere in this project's own code. Now threaded through explicitly from `Settings`, with defaults matching `pypsrp`'s own so this change doesn't silently alter behavior, only makes the values visible and tunable.

**Also bounded: how long a request waits for a free slot.** A semaphore alone still lets requests queue indefinitely for a slot under sustained overload — `connection_queue_timeout_seconds` (default 30) makes `invoke_function()` fail loud with a clear "proxy at maximum concurrent connections" `ExecutorError` rather than hang waiting for a slot that may never free up.

Tested with real threading + real timing assertions (not just mocked call counts) — see `tests/test_winrm_executor_pypsrp_concurrency.py`: a second call genuinely blocks while a slot is held, releases correctly even after an exception, and a queue-timeout fires within roughly the configured window rather than hanging.

### 2. Session pooling/reuse — built (2026-08-10), opt-in

Originally deferred (see history below) pending a real multi-host fleet to design against. Built anyway on 2026-08-10 at the user's request, ahead of that — deliberately with a design that sidesteps the riskiest open questions named below rather than solving each one in full generality:

- **Staleness detection → optimistic reuse, not health-checking.** No separate "is this session still alive" probe. `_invoke_pooled()` just tries the pooled session; on *any* failure it evicts that session and retries once with a freshly-built one. This can't distinguish "the pooled session was actually dead" from "the target genuinely failed this specific call" — a real remote failure gets retried once too, harmlessly (the retry fails the same way and that failure propagates as a normal `ExecutorError`). Simple, and correct in the failure mode that actually matters (a session going bad silently doesn't cause a permanently-broken host — the next call self-heals).
- **Thread-safety → per-host lock, not trusting `pypsrp` internals.** `pypsrp.RunspacePool`'s own thread-safety under concurrent pipelines is still unverified against its source — rather than resolve that, each pooled session got its own `threading.Lock`, held for the duration of a call to that host. Two requests for the *same* host serialize; different hosts still run fully concurrently (bounded only by the existing global connection semaphore). Tested with a real two-thread rendezvous proving no overlap (`test_per_host_lock_serializes_concurrent_calls_to_same_host`), not just that the lock object exists.
- **Sizing/eviction → LRU cap + lazy idle sweep, no background thread.** `max_pooled_sessions` (default 50) bounds total pooled sessions via LRU eviction on insert; `pooled_session_idle_timeout_seconds` (default 600) evicts sessions unused longer than that, checked at the start of each `invoke_function()` call rather than on a timer thread. Actual socket teardown for any evicted session runs on a short-lived daemon thread (`_close_sessions_async`), not inline — so whichever request happens to trigger an eviction or sweep never blocks on tearing down someone else's connection, including a genuinely-dead one (which could otherwise hang up to the configured timeout values).
- **Server-side session lifetime → not separately solved, covered by the same retry.** Still unconfirmed whether the JEA/WinRM shell has its own server-side idle timeout that could expire a pooled session out from under the proxy. Deliberately not chased down further: if it does, that shows up as exactly the same "pooled session dead" case the optimistic-reuse-and-retry logic already handles — no separate mechanism needed.

Tested with real threading + real timing/call-count assertions (`tests/test_winrm_executor_pypsrp_pooling.py`, 8 tests): reuse across repeated calls to the same host, separate sessions per distinct host, stale-session eviction-and-retry (and that a genuine, non-stale failure still propagates after the one retry), idle-timeout eviction, LRU eviction under a low cap, and the per-host-lock non-overlap proof above.

**Default: `session_pooling_enabled: false`.** Opt-in, not on-by-default — same "new setting preserves prior behavior exactly until explicitly turned on" approach as the concurrency-limiting settings. Enable per-proxy in `config.yml` once validated against that proxy's real target(s).

**What this still doesn't solve, by design:** this is a single-process in-memory pool — sessions aren't shared across multiple proxy replicas (irrelevant at today's single-instance-per-domain scale, see `WINRM_DESIGN.md`'s "no per-site VM sprawl" topology), and there's still no separate mechanism confirming a pooled session's liveness independent of an actual check call failing against it. Both are acceptable at current scale; revisit if either the topology or the failure patterns observed against a real fleet change that calculus.

## What changed, concretely

- `app/config.py` (`Settings`): `max_concurrent_connections`, `connection_queue_timeout_seconds`, `winrm_operation_timeout_seconds`, `winrm_connection_timeout_seconds`, `winrm_read_timeout_seconds`, `session_pooling_enabled`, `max_pooled_sessions`, `pooled_session_idle_timeout_seconds` — all optional, defaulting to values that preserve prior behavior exactly.
- `app/winrm_executor_pypsrp.py`: `threading.Semaphore` acquired (with a timeout) before every connection attempt, released in a `finally` so it comes back whether the call succeeded, failed, or errored. Timeout kwargs passed explicitly to `WSMan(...)`. `invoke_function()` now dispatches to `_invoke_fresh()` (original per-call behavior) or `_invoke_pooled()` (per-host `RunspacePool` reuse via an `OrderedDict`-backed LRU pool, guarded by a pool-bookkeeping lock plus per-session locks) depending on `session_pooling_enabled`.
- `app/main.py`: threads the new settings through to `WinrmExecutorPypsrp`'s constructor.
- `config.example.yml`: documents the new (all-optional) settings.

## History: why session pooling was deferred initially, before being built

Kept for context on the original sequencing decision. Caching a live `RunspacePool` per host and reusing it across check calls (instead of tearing down and rebuilding every time) would reduce both per-call latency (skip session negotiation on warm hosts) and load on the target/DC. Real value — but introduces real correctness problems worth naming up front rather than discovering mid-implementation, which is exactly why this was originally sequenced *after* the simpler, more urgent concurrency-limiting fix rather than bundled with it. Those named problems (staleness detection, thread-safety, pool sizing/eviction, server-side session lifetime) are the same four addressed in "Session pooling/reuse — built" above; the original reasoning for deferring was "better designed against observed real-world timing/failure patterns from an actual fleet than built blind against a single test target" — overridden by the user's explicit go-ahead to build it now instead, using the simple/conservative choices described above in place of waiting for that real-fleet data.
