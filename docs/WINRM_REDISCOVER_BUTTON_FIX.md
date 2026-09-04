# Fix — "Rediscover" Button Hidden for `snmp_disable` Devices

**Contents**
- [Status](#status)
- [Root cause: a flag check with no backing requirement](#root-cause-a-flag-check-with-no-backing-requirement)
- [Fix: drop the condition, don't replace it](#fix-drop-the-condition-dont-replace-it)
- [Verified against the real target](#verified-against-the-real-target)

<a id="status"></a>

## Status
**Confirmed working end-to-end against the real target** (2026-08-12). `resources/views/device/edit/device.blade.php` renders the Rediscover button for device 5 (`snmp_disable=true`, `WinrmPoller`-discovered), where it was previously hidden.

<a id="root-cause-a-flag-check-with-no-backing-requirement"></a>

## Root cause: a flag check with no backing requirement
Same class of bug as `WINRM_HEADER_SPARKLINES_FIX.md` — a `snmp_disable` check gating UI, not a check of whether the underlying thing being gated actually needs SNMP. The button:
```php
@if(LibrenmsConfig::get('enable_clear_discovery') && ! $device->snmp_disable)
    <button ... name="rediscover" ...>{{ __('device.edit.rediscover') }}</button>
@endif
```
posts to `DeviceController::rediscover()` (`app/Http/Controllers/DeviceController.php:88-99`):
```php
public function rediscover(Device $device): JsonResponse
{
    $this->authorize('update', $device);
    $device->last_discovered = null;
    $saved = $device->save();
    ...
}
```
This action has **no SNMP dependency at all** — confirmed by reading it directly, not assumed. It nulls `last_discovered`, which is consumed identically by every module's own `discover()` on the next cycle (`app/Jobs/DiscoverDevice.php` iterates `ModuleList::modulesWithStatus()` with no branch on whether `last_discovered` was previously null — it's a plain "due for discovery" marker, the same mechanism `LibreNMS\Modules\Core.php`'s reboot-detection auto-rediscover uses). `enable_clear_discovery`'s own help text ("clear discovery date and time... force a rediscovery") describes exactly this and nothing more — no other code reads that config key. Neither mechanism has any purge/wipe/destructive side effect tied to it, checked directly across `DiscoverDevice.php`, `Device.php`, and `Core.php`.

So `! $device->snmp_disable` on the button was a pre-WinRM-module-era assumption ("SNMP disabled means nothing can discover this device") that's simply false now that a non-SNMP `Module` implementation (`WinrmPoller`) exists — it hid a genuinely safe, useful action from every WinRM-monitored device, with no real backing requirement anywhere in the code it triggers.

<a id="fix-drop-the-condition-dont-replace-it"></a>

## Fix: drop the condition, don't replace it
Unlike the header-sparklines fix (which needed a *data-driven replacement* condition, because there was a genuine difference in what should render for a true ping-only device vs. one with real usage data), this needed no replacement condition at all. The action is safe and inert for any device regardless of what (if anything) can actually discover it — worst case on a genuinely non-discoverable device, clicking it is a harmless no-op. There's also no cheap, generic "is any module applicable to this device" signal available without a heavier, unprecedented pattern (looping the module registry from a Blade view — confirmed no existing UI code does this anywhere in this codebase), and the obvious cheap proxy (`last_discovered !== null`) would perversely *hide* the button for the exact "never successfully discovered yet" case where a user most wants to click it.

`snmp_disable` itself was left completely untouched everywhere else — same reasoning as the header-sparklines fix, it's load-bearing for gating the legacy SNMP-based discovery/polling modules via `ConnectivityHelper::snmpIsAvailable()`. This fix only removes it from this one, confirmed-unnecessary condition:
```php
@if(LibrenmsConfig::get('enable_clear_discovery'))
    <button ... name="rediscover" ...>{{ __('device.edit.rediscover') }}</button>
@endif
```
A one-clause deletion, matching this project's "narrow changes over clean independence" ground rule — the smallest change that's actually correct, not a data-driven mechanism invented to look more thorough than the underlying reality (a fully SNMP-agnostic backend action) calls for.

<a id="verified-against-the-real-target"></a>

## Verified against the real target
Rendered `device.edit.device` for real (`view(...)->render()`) against device 5 on `lnms-test` (`snmp_disable=true`, `enable_clear_discovery=true`, both confirmed via `LibrenmsConfig::get()`/the real device row, not assumed): the button is now present, with its real title text and `data-device_id`. Non-WinRM devices are unaffected — the only condition removed never applied to them (it only ever hid the button, never showed one that shouldn't be there).
