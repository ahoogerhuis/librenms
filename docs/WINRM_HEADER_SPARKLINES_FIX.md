# Fix — Header Sparklines Showed "ICMP Response" Instead of Processor/Memory/Storage

**Contents**
- [Status](#status)
- [Root cause: a flag check, not a data check](#root-cause-a-flag-check-not-a-data-check)
- [Fix: make the fallback condition data-driven, not flag-driven](#fix-make-the-fallback-condition-data-driven-not-flag-driven)
- [Verified against the real target](#verified-against-the-real-target)
- [Tests](#tests)
- [Next step](#next-step)

<a id="status"></a>

## Status
**Confirmed working end-to-end against the real target** (2026-08-10). `Graph::getOverviewGraphsForDevice()` for `winrm-test01.vpp.local` (device 5, `snmp_disable=true`, real `Processor`/`Mempool`/`Storage` rows from `WinrmPoller`) now returns `device_processor`/`device_mempool`/`device_storage`, not `device_ping_perf`. `phpstan` clean, 4/4 new tests (`DBTEST=1`).

<a id="root-cause-a-flag-check-not-a-data-check"></a>

## Root cause: a flag check, not a data check
`LibreNMS\Util\Graph::getOverviewGraphsForDevice()` (used by the device page header, the device popup, and the map device-link tooltip — every place the small overview sparklines render) unconditionally returned the `os.ping.over` graph list (`device_ping_perf`, labeled "ICMP Response") whenever `$device->snmp_disable` was true, **before ever consulting the device's actual OS or data**:

```php
if ($device->snmp_disable) {
    return Arr::wrap(LibrenmsConfig::getOsSetting('ping', 'over'));
}
```

`snmp_disable` is true for every WinRM device by design (see `WINRM_DESIGN.md`'s development convention section and `ConnectivityHelper::snmpIsAvailable()` gating). The `windows` OS definition (`resources/definitions/os_detection/windows.yaml`) already has the *correct* `over:` list — `device_processor`/`device_mempool`/`device_storage` — but the `snmp_disable` check short-circuited before that list was ever consulted, regardless of whether the device actually had that data.

<a id="fix-make-the-fallback-condition-data-driven-not-flag-driven"></a>

## Fix: make the fallback condition data-driven, not flag-driven
Per this project's standing "match the SNMP-equivalent's shape" convention — and per this being a genuinely universal LibreNMS gap, not WinRM-specific — `snmp_disable` itself was **not** touched. It's load-bearing elsewhere in this project: it's what keeps LibreNMS's legacy SNMP-based `Processor`/`Mempool`/`Storage`/`Ports`/`Os` modules from running and fighting `WinrmPoller`'s own writes to those same models (gated via `ConnectivityHelper::snmpIsAvailable()`). Changing what `snmp_disable` *means* would have risked reintroducing that conflict.

Instead, the header's own fallback condition changed to check for real data:

```php
if ($device->snmp_disable && ! self::deviceHasUsageData($device)) {
    return Arr::wrap(LibrenmsConfig::getOsSetting('ping', 'over'));
}
```

```php
private static function deviceHasUsageData(Device $device): bool
{
    return $device->processors()->exists() || $device->mempools()->exists() || $device->storage()->exists();
}
```

A `snmp_disable` device with no `Processor`/`Mempool`/`Storage` rows at all (a genuine ping-only device — the flag's traditional, still-valid use case) is completely unaffected: it still falls back to `device_ping_perf`. Only a `snmp_disable` device that actually has usage data — regardless of what populated it, WinRM or anything else — now gets its real OS's overview graphs. This is a core-file fix (`LibreNMS/Util/Graph.php`), not confined to the WinRM module, and benefits any future non-SNMP poller the same way.

<a id="verified-against-the-real-target"></a>

## Verified against the real target
```
snmp_disable: true
processors: 4
mempools: 3
storage: 1
```
returns:
```
device_processor / Processor Usage
device_mempool / Memory Usage
device_storage / Storage Usage
```
— confirmed via `php artisan tinker` against `lnms-poller.vpp.local`'s real DB, device 5 (`winrm-test01.vpp.local`), not just the unit tests.

<a id="tests"></a>

## Tests
`tests/Feature/GraphOverviewGraphsForDeviceTest.php` (new, DB-backed via `RequiresDatabase`/`DatabaseTransactions` — the fix itself now queries real relations, so a stubbed-Device unit test like `WinrmPollerTest`'s applicability tests wouldn't exercise the actual behavior):
1. `snmp_disable` + no usage data → ping-only (unchanged behavior, the flag's original case).
2. `snmp_disable` + `Processor` data → real OS graphs, not ping.
3. `snmp_disable` + only `Mempool`-or-only-`Storage` data (not `Processor`) → still real OS graphs — confirms the fix checks all three models, not just the one WinRM happens to populate first at discovery.
4. `snmp_disable=false` (normal SNMP device) → unaffected, always uses OS graphs, confirming no behavior change on the non-WinRM path.

<a id="next-step"></a>

## Next step
None outstanding for this fix. Part 2 of the handoff this came from (processor summary label / sockets-cores) is a separate, independent investigation — tracked and documented on its own, not here.
