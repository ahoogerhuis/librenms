# Investigation — "x4 CPU 0" Processor Summary Label Is Not a WinRM Gap

## Status
**Investigated and closed, no code change** (2026-08-10). Confirmed the "x4 CPU 0"-style label is universal, generic LibreNMS behavior for any device with multiple `Processor` rows of the same `processor_type` — not something specific to, or worth diverging from, WinRM-collected data. Same category as the swap-graph negative-value non-issue this handoff explicitly named as a precedent to check against first.

## Where the label actually comes from
`includes/html/pages/device/overview/processors.inc.php` (the device-overview Processors panel, `cpu_details_overview === false` branch — the default summarized view):

```php
if (! isset($total_percent[$proc['processor_type']])) {
    $total_percent[$proc['processor_type']] = [
        'usage' => 0, 'warn' => 0,
        'descr' => $text_descr,   // first processor of this type's descr, kept as-is
        'count' => 0,
    ];
}
...
$total_percent[$proc['processor_type']]['count'] += 1;
...
'x' . $values['count'] . ' ' . $values['descr']   // "x4 CPU 0"
```

Every `Processor` row sharing the same `processor_type` string gets folded into one summary line: `x{count of rows} {first row's processor_descr}`. This file has **zero knowledge of SNMP vs. WinRM** — no `snmp_disable` check, no collection-method branching of any kind. It runs identically for a real SNMP multi-core device (e.g. any OS driver that discovers one `Processor` row per `HOST-RESOURCES-MIB::hrProcessorLoad` entry, all sharing one `processor_type`) and for `WinrmPoller`'s output. `WinrmPoller.php` sets `processor_type = self::PROCESSOR_TYPE` (one fixed namespace) on every core-row it creates and `processor_descr = 'CPU ' . $core['index']` — which is exactly why a 4-vCPU WinRM target shows `x4 CPU 0`: four rows, same type, first one's descr is `"CPU 0"`. A real SNMP device with four `hrProcessorLoad` entries named the same way would render identically.

**Conclusion: this is not a WinRM-specific display gap.** It's the same summary format every multi-core LibreNMS device gets, regardless of how its `Processor` rows were populated. Changing it to "X Sockets, Y Cores" for WinRM devices only would make WinRM devices display *differently* from every SNMP-monitored multi-core device on the same instance — a real inconsistency, not a fix.

## The data-gap analysis, for the record (not being built)
The handoff correctly anticipated that *if* this were worth changing, it would need new data `cpu-usage` doesn't currently collect — recorded here in case this is ever revisited with a different premise (e.g. a genuine feature request for socket/core-aware summaries as a LibreNMS-wide enhancement, not a WinRM patch):

- **Socket count** would come from the number of distinct `Win32_Processor` *instances* (`hardware-inventory`'s `Processors` list already collects this shape — one entry per socket — so the count is already available there without a new WinRM round-trip).
- **"Cores" is ambiguous and would need a decision before any implementation**: `Win32_Processor.NumberOfCores` (physical) vs. `NumberOfLogicalProcessors` (logical, what `cpu-usage`'s per-row-per-core-index model already represents) differ on hyperthreaded hardware. Neither is currently collected as a scalar — only implied by the row count `cpu-usage` produces.

Not building this: the premise it would fix (a WinRM-specific display gap) doesn't hold. If LibreNMS's processor-summary format is ever revisited, it should be a core, OS-agnostic change proposed on its own merits, not bundled into this project.

## Next step
None. Closed as "confirmed universal behavior, working as intended" — no code change.
