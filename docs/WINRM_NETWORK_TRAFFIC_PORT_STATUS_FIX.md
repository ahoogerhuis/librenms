# Fix — `network-traffic` Ports Now Report `ifOperStatus`/`ifAdminStatus`

**Contents**
- [Status](#status)
- [The fix: a real data source, not overriding the original caution](#the-fix-a-real-data-source-not-overriding-the-original-caution)
- [Correlation: tested on the real target, not assumed trivial](#correlation-tested-on-the-real-target-not-assumed-trivial)
- [Mapping: enum values found by real testing, not assumed](#mapping-enum-values-found-by-real-testing-not-assumed)
- [`WinrmPoller.php` side](#winrmpollerphp-side)
- [Explicitly out of scope (per the handoff's own scope check)](#explicitly-out-of-scope-per-the-handoffs-own-scope-check)

<a id="status"></a>

## Status
**Confirmed working end-to-end against the real target** (2026-08-10). Before: the Windows device's `Port` row had `ifAdminStatus`/`ifOperStatus` both `null` (deliberately left unset, see `WINRM_NETWORK_TRAFFIC_CHECK.md`), which is why the port-status summary widget showed `Total: 1, Up: 0, Down: 0, Disabled: 0` — the port wasn't counted in any bucket. After: `device:discover` and `device:poll` both correctly set `ifAdminStatus=up`/`ifOperStatus=up` on the real target's single connected NIC, verified by direct DB query (`ifAdminStatus=up ifOperStatus=up`), not just "no errors."

<a id="the-fix-a-real-data-source-not-overriding-the-original-caution"></a>

## The fix: a real data source, not overriding the original caution

`network-traffic` queries `Win32_PerfRawData_Tcpip_NetworkInterface` — a pure traffic-counter class with no link-status field at all. Leaving `ifOperStatus`/`ifAdminStatus` unset was the correct call at the time; fabricating "up" just because bytes were flowing would have been worse than leaving it null. The fix adds a second, genuinely different WMI class as the actual data source: `Win32_NetworkAdapter` (already confirmed JEA-reachable via `hardware-inventory`), which carries `NetConnectionStatus` (a real link-state enum) and `NetEnabled` (administrative enable/disable).

<a id="correlation-tested-on-the-real-target-not-assumed-trivial"></a>

## Correlation: tested on the real target, not assumed trivial

The real open question was whether `Win32_PerfRawData_Tcpip_NetworkInterface.Name` and `Win32_NetworkAdapter.Name` reliably identify the same interface — these are two different WMI classes with a well-known (if inconsistently documented) character-substitution quirk between perf-counter instance names and their source adapter names. Tested directly on the real target: both classes reported the exact same string (`"Red Hat VirtIO Ethernet Adapter"`), while `Win32_NetworkAdapter.NetConnectionID` (a third, different name field — "Ethernet 2") did **not** match, confirming `Name`-to-`Name` (not `Name`-to-`NetConnectionID`) is the right pairing, at least on this target.

**Correlation is exact-string-match only, no normalization.** With only one NIC available to test, there's no way to verify a normalization rule (e.g. stripping special characters) against the actual substitution behavior Windows applies for names containing them — guessing at that rule risks confidently mismatching two different interfaces on a multi-NIC/special-character-name host, which is worse than the current behavior. **Any interface where the exact-match lookup fails gets `null` for both fields** — same non-fabrication principle as the original design, just narrowed to apply per-interface instead of universally. Multi-NIC and disconnected/disabled-adapter correlation remain genuinely untested; only the single connected-adapter path has been exercised for real.

<a id="mapping-enum-values-found-by-real-testing-not-assumed"></a>

## Mapping: enum values found by real testing, not assumed

Two things checked directly, per the handoff's own instruction not to assume:

1. **`Port.ifAdminStatus`/`ifOperStatus` are not plain strings.** Checking real SNMP-populated `Port` rows on `lnms-poller.vpp.local` confirmed lowercase `'up'`/`'down'` as the *stored* values — but `phpstan` caught that the model's actual PHP property type is `LibreNMS\Enum\IfOperStatus|null`, a real backed enum (`Up = 'up'`, `Down = 'down'`, plus `Testing`/`Unknown`/`Dormant`/`NotPresent`/`LowerLayerDown`) with an Eloquent `Castable` implementation — not a bare string column the DB-value check alone would have revealed. `mapPortStatus()` returns `IfOperStatus` enum instances, not string literals.
2. **Only the "connected" path tested for real** — the test target's single NIC never exercises `NetConnectionStatus` values other than `2` (Connected). The `0`/`7` (Disconnected/Media disconnected) → `Down` and "anything else → unmapped" branches are implemented per the handoff's mapping sketch but not exercised against real hardware in that state.

```
NetEnabled = null                    → ifAdminStatus = null (no correlated adapter)
NetEnabled = true                    → ifAdminStatus = IfOperStatus::Up
NetEnabled = false                   → ifAdminStatus = IfOperStatus::Down
NetConnectionStatus = 2 (Connected)  → ifOperStatus = IfOperStatus::Up
NetConnectionStatus = 0 or 7         → ifOperStatus = IfOperStatus::Down
NetConnectionStatus = anything else  → ifOperStatus = null (ambiguous, not forced)
```

<a id="winrmpollerphp-side"></a>

## `WinrmPoller.php` side

`mapPortStatus(?bool $netEnabled, ?int $netConnectionStatus): array{ifAdminStatus: IfOperStatus|null, ifOperStatus: IfOperStatus|null}` — the mapping table above, nothing more.

**Set in two places, not one:**
- `discover()`'s existing `Port::query()->updateOrCreate(...)` call — gives a freshly-discovered port a real initial status rather than leaving it unknown until the next poll cycle happens to run.
- `pollNetworkTraffic()` — **new**, this method previously only read `$port` to get `port_id` for the RRD write, never updated the row itself. Status is genuinely dynamic (a cable can be unplugged between discovery cycles), so it's refreshed on every poll now, matching how real SNMP port polling re-reads status every cycle rather than only at discovery.

`fetchNetworkInterfaces()`'s return shape and docblock updated to include the two new optional fields — its stale docblock (still describing the pre-fix 3-key shape) was what caused `phpstan`'s array-offset errors on first pass, not a logic bug; fixing the annotation resolved those alongside the enum-type fix.

<a id="explicitly-out-of-scope-per-the-handoffs-own-scope-check"></a>

## Explicitly out of scope (per the handoff's own scope check)

`ifType`/`ifSpeed` remain unset — same "no signal from this WMI class" reasoning, not part of what was reported. Worth a follow-up decision, not folded into this fix.
