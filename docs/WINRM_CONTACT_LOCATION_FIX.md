# Fix — Contact/Location for WinRM Devices, Registry-Sourced

**Contents**
- [Status](#status)
- [Step 1: confirmed before building anything](#step-1-confirmed-before-building-anything)
- [Step 4 (from the handoff): the "fill only if blank" premise didn't match real SNMP behavior](#step-4-from-the-handoff-the-fill-only-if-blank-premise-didnt-match-real-snmp-behavior)
- [Step 2: generic registry key, not org-specific](#step-2-generic-registry-key-not-org-specific)
- [Step 3: optional metadata, not required data](#step-3-optional-metadata-not-required-data)
- [Implementation](#implementation)
- [Next step](#next-step)

<a id="status"></a>

## Status
**Confirmed working end-to-end against the real target** (2026-08-10). Full behavior matrix verified live against device 5 (`winrm-test01.vpp.local`): no registry value → fields stay null; registry value set → populates on discover; registry value changes while a manual override is active → override protected, no clobber; override cleared → next discover repopulates from the current registry value. `phpstan`/`php -l` clean, 6/6 applicability tests, all 9 proxy checks green after container rebuild.

<a id="step-1-confirmed-before-building-anything"></a>

## Step 1: confirmed before building anything
Location/Contact were already fully editable for a `snmp_disable` device through the normal device-edit UI — no code gates on `snmp_disable` anywhere in `EditDeviceController`/`device.blade.php`. Confirmed both by reading the real code and live against device 5 (set `sysContact` directly, persisted, reset). This is the zero-code baseline; everything below only concerns *populating* the field automatically, not making it editable.

<a id="step-4-from-the-handoff-the-fill-only-if-blank-premise-didnt-match-real-snmp-behavior"></a>

## Step 4 (from the handoff): the "fill only if blank" premise didn't match real SNMP behavior
The handoff's own instruction was to confirm this against the real SNMP mechanism before calling it a match, not just assume it. Reading `LibreNMS\Modules\Os::sysContact()`/`updateLocation()` (the real, generic SNMP-based module — gated off for `snmp_disable` devices the same way `Storage`/`Mempool`/`Processor` are, which is why nothing has ever touched these fields for a WinRM device before now) showed it does **not** fill-if-blank. It unconditionally overwrites `sysContact`/location from the live SNMP value **every discover cycle**. The "don't clobber a manual edit" protection comes entirely from a separate, already-built override layer every device type already has: `override_sysContact` (a `DeviceAttrib`) and `override_sysLocation` (a real `devices` column), both already exposed by the edit UI as a checkbox.

Flagged this back before building (per this project's standing rule not to silently reverse an explicit decision) — confirmed with the user to match the real SNMP shape instead of the handoff's original fill-if-blank sketch: write the registry value only when the corresponding override is off, every discover cycle, exactly like `Os` does. This reuses the override mechanism admins already know from every other device type rather than inventing a second, WinRM-specific "protect my edit" mechanism.

<a id="step-2-generic-registry-key-not-org-specific"></a>

## Step 2: generic registry key, not org-specific
`HKLM:\SOFTWARE\LibreNMS\` with `Contact`/`Location` `REG_SZ` values — a generic vendor/product key, the same convention any real Windows software uses for its own custom registry data, not anything naming this specific deployment. This JEA function ships in the public-eligible `librenms-fork` module; every future adopter needs a path that means something to them, not one baked with this project's own org naming (per the newly-added generic-naming ground rule, `docs/claude-code/ground-rules.md`). Example values shown while building/testing this used the standard fictional placeholder (`it-support@vpp.local`, `VPP HQ - Server Room 2`), not real values.

<a id="step-3-optional-metadata-not-required-data"></a>

## Step 3: optional metadata, not required data
Most hosts will never have this registry key configured at all — that's the expected common case, not a degraded one, unlike every check built so far (which fail loud on missing/malformed data because the underlying data is always supposed to be there). `Get-HardwareInventoryStatus` reads both values with `-ErrorAction SilentlyContinue` plus an explicit null-check, returning `null` for whichever isn't present without that being an error. `hardware_inventory.py`'s validation treats absent-entirely and present-but-null identically (same pattern already established for `ProcessorIdentifier`) — neither is a failure.

<a id="implementation"></a>

## Implementation

**Proxy** (`app/checks/hardware_inventory.py`): two more root-level optional strings, `Contact`/`Location`, validated the same way as `ProcessorIdentifier`. 4 new tests, 26/26 for this check, 154/154 for the full proxy suite.

**JEA function** (`Get-HardwareInventoryStatus`): reads `HKLM:\SOFTWARE\LibreNMS`'s `Contact`/`Location` values, null-guarded. Same hot-reload `.psrc`/`.psm1`-only deployment path as every prior addition — no `.pssc` change, no WinRM restart.

**`WinrmPoller.php`** (`updateDeviceContactLocation()`, called from `discover()` right after `updateDeviceOsVersion()`/`updateDeviceHardware()`):
```php
if (! $device->getAttrib('override_sysContact_bool')) {
    $contact = $inventory['contact'] ?? null;
    $device->sysContact = is_string($contact) && $contact !== '' ? $contact : null;
}

$location = $inventory['location'] ?? null;
$device->setLocation(is_string($location) && $location !== '' ? $location : null, true);
$device->location?->save();
```
`setLocation()`'s own `$user_override` param is deliberately left at its default (`false`), same as `Os::updateLocation()`'s own call — that's what makes `setLocation()` respect `override_sysLocation` internally, so this method doesn't need a separate `override_sysLocation` check the way it does for `sysContact` (which has no equivalent built-in gate).

<a id="next-step"></a>

## Next step
None outstanding for this fix.
