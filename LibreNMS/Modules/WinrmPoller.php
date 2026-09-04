<?php

/**
 * WinrmPoller.php
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2026 LibreNMS
 */

namespace LibreNMS\Modules;

use App\ApiClients\WinrmProxy;
use App\Facades\LibrenmsConfig;
use App\Facades\Rrd;
use App\Models\Application;
use App\Models\ApplicationMetric;
use App\Models\Device;
use App\Models\EntPhysical;
use App\Models\Eventlog;
use App\Models\Mempool;
use App\Models\Port;
use App\Models\Processor;
use App\Models\Sensor;
use App\Models\Storage;
use App\Observers\DeviceObserver;
use App\Observers\ModuleModelObserver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use LibreNMS\DB\SyncsModels;
use LibreNMS\Enum\IfOperStatus;
use LibreNMS\Enum\Severity;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\Interfaces\Module;
use LibreNMS\OS;
use LibreNMS\Polling\ConnectivityHelper;
use LibreNMS\Polling\ModuleStatus;
use LibreNMS\RRD\RrdDefinition;
use LibreNMS\Util\Time;
use LibreNMS\Util\Url;

/**
 * Runs constrained PowerShell/WinRM checks against Windows servers via
 * an authenticated relay proxy (see docs/WINRM_DESIGN.md). Deliberately
 * ignores ConnectivityHelper entirely -- WinRM reachability has nothing
 * to do with this device's SNMP/ICMP/IPMI availability, applicability
 * is purely "is this a Windows device with a proxy configured for it".
 */
class WinrmPoller implements Module
{
    use SyncsModels;

    private const POLLER_TYPE = 'winrm';

    // disk-space populates App\Models\Storage directly (same model
    // SNMP-based storage monitoring uses, for native Storage UI/alerting
    // integration -- see docs/WINRM_DISK_SPACE_CHECK.md), not the
    // Sensor-based checks() pattern every other check uses. It's a
    // different shape (a variable-length list of disks per device, not
    // one scalar value) and Storage has its own established
    // discover/sync/poll machinery (LibreNMS\DB\SyncsModels) that
    // reimplementing on top of Sensor would just be duplicating.
    private const DISK_SPACE_CHECK_NAME = 'disk-space';

    // Storage.type namespace for WinRM-sourced disks -- distinct from
    // SNMP-based types (e.g. 'hrstorage') so the two never collide on
    // Storage's composite key ("$type-$storage_index") if a device ever
    // somehow had both, even though today's WinRM-only devices are
    // snmp_disable.
    private const STORAGE_TYPE = 'winrm';

    // network-traffic populates the real Port table only (INOCTETS/
    // OUTOCTETS RRD in pollNetworkTraffic() below) -- no companion
    // Sensor pair. Originally built with one (sensor_class='count',
    // 'winrm-network-traffic-bytes-sent'/'-received'), removed
    // 2026-08-10 after real investigation (see
    // docs/WINRM_NETWORK_TRAFFIC_CHECK.md's "Sensor pair removed"
    // section) found it duplicated data the Port row already provides
    // correctly, matched no real SNMP-device pattern (no SNMP interface
    // gets a parallel Sensor entry for its byte counters), and its
    // stated justification -- Sensor-limits alerting -- doesn't hold:
    // confirmed by reading resources/definitions/alert_rules.json that
    // LibreNMS ships real, default, Port-table-native alert rules
    // ("Port status up/down", "Port utilisation over threshold") needing
    // no Sensor row at all.
    private const NETWORK_TRAFFIC_CHECK_NAME = 'network-traffic';

    // network-traffic ALSO populates the real `ports` table, in addition
    // to the Sensor pair above -- deliberately both, not a replacement:
    // the Sensor pair keeps working (Health/Sensors tab, sensor-limits
    // alerting), and populating `ports` is what makes the device
    // overview's native combined bytes-in/out graph (device_bits) render
    // for this data, which nothing about the Sensor pattern can produce
    // (see docs/WINRM_NETWORK_TRAFFIC_CHECK.md's "TODO: option 2" note
    // for the lower-invasiveness alternative that was deliberately not
    // chosen here).
    //
    // `ports.ifIndex` is a real bigint column with no DB-level
    // uniqueness constraint (dropped in a 2025-01 migration) -- this
    // module owns its own dedup via (device_id, ifIndex) lookups, and
    // needs a stable NUMERIC identity per interface since there's no
    // real SNMP ifIndex to use. A deterministic hash of the interface
    // name, offset into a clearly-synthetic high range, both avoids
    // colliding with any real SNMP ifIndex (which are essentially never
    // this large) and stays stable across polls regardless of WMI's
    // enumeration order -- the offset makes "this ifIndex came from
    // WinRM, not SNMP" recognizable by range alone, since ports has no
    // dedicated source/type column the way Storage/Sensor do.
    private const NETWORK_TRAFFIC_SYNTHETIC_IFINDEX_BASE = 1_000_000_000;

    private function syntheticIfIndex(string $interfaceName): int
    {
        return self::NETWORK_TRAFFIC_SYNTHETIC_IFINDEX_BASE + (crc32($interfaceName) % 1_000_000_000);
    }

    // memory-usage populates App\Models\Mempool directly (same model
    // SNMP-based memory-pool monitoring uses), exactly the same reasoning
    // as disk-space/Storage above -- a variable-length list (0+ page
    // files, plus the two fixed physical/virtual entries) with its own
    // established discover/sync/poll machinery via LibreNMS\DB\SyncsModels,
    // not a fit for the fixed Sensor checks() table. The built-in
    // LibreNMS\Modules\Mempools module never runs for these devices --
    // its shouldDiscover()/shouldPoll() both require
    // ConnectivityHelper::snmpIsAvailable(), which is false for our
    // snmp_disable WinRM-only devices -- so there's no risk of the two
    // modules colliding over the same rows.
    private const MEMORY_USAGE_CHECK_NAME = 'memory-usage';

    // Mempool.mempool_type namespace for WinRM-sourced pools -- same
    // reasoning as STORAGE_TYPE above (distinct from SNMP-based types so
    // the two never collide on Mempool's composite key
    // ("$mempool_type-$mempool_index"), even though today's WinRM-only
    // devices are snmp_disable).
    private const MEMPOOL_TYPE = 'winrm';

    // Mempool.mempool_index is a bare 16-char DB column (see
    // 2018_07_03_091314_create_mempools_table.php) -- fine for the fixed
    // 'physical'/'virtual' indices below, but a real page file path
    // (e.g. "C:\Program Files\pagefile.sys") can easily exceed that.
    // Page files are keyed by ordinal position instead, with the real
    // path kept in mempool_descr (64 chars, the same "short stable key +
    // separate human-readable descr" split disk-space uses for
    // storage_index/storage_descr). Same caveat as drive letters/
    // interface names: a page-file reconfiguration (rare) reads as
    // "removed, new one added" on the next discovery cycle, not worth
    // solving unless it's ever actually a problem.
    private function pageFileMempoolIndex(int $ordinal): string
    {
        return "pagefile-{$ordinal}";
    }

    // cpu-usage populates App\Models\Processor directly -- same
    // variable-count reasoning as disk-space/Storage and memory-usage/
    // Mempool, but a genuinely different code path from either: unlike
    // Storage/Mempool, Processor has no modern class-based
    // LibreNMS\Modules\* counterpart to mirror -- the real discover/poll
    // logic for SNMP-based processors lives in the legacy
    // LibreNMS\Device\Processor static class (LibreNMS\Model-based, not
    // Eloquent/Keyable/SyncsModels), driven from includes/discovery/
    // polling/processors.inc.php. App\Models\Processor itself is a bare
    // Eloquent model with no $fillable override, so (confirmed by
    // reading vendor GuardsAttributes.php) it inherits Eloquent's
    // default $guarded = ['*'] -- mass assignment (`new Processor([...])`,
    // updateOrCreate()) would silently discard every attribute rather
    // than throw, a real silent-data-loss risk. Every write below uses
    // direct property assignment instead (`$processor->device_id = ...`),
    // which always works regardless of guarding -- the same technique
    // LibreNMS\Device\Processor::discover() itself uses for the same
    // reason, confirmed by reading that class's actual source rather
    // than assumed.
    private const CPU_USAGE_CHECK_NAME = 'cpu-usage';

    // Processor.processor_type namespace for WinRM-sourced cores -- same
    // reasoning as STORAGE_TYPE/MEMPOOL_TYPE above.
    private const PROCESSOR_TYPE = 'winrm';

    // hardware-inventory populates App\Models\EntPhysical directly.
    // Real research done before assuming this fits (per the handoff's
    // explicit "don't assume entPhysical fits" instruction, given how
    // wrong the original network-traffic/Ports guess turned out to be):
    // read LibreNMS\Modules\EntityPhysical (a modern class-based Module,
    // same lineage as Mempools/Storage -- unlike Processor's legacy
    // path) and App\Models\EntPhysical directly. Findings:
    //   - EntPhysical implements Keyable, but its composite key is a
    //     BARE int (entPhysicalIndex alone -- not a type+index pair like
    //     Storage/Mempool/Port). There's no type/namespace column on
    //     this table at all to separate WinRM-sourced rows from
    //     SNMP-sourced ones. Not a real collision risk here: the real
    //     LibreNMS\Modules\EntityPhysical's shouldDiscover()/
    //     shouldPoll() both require ConnectivityHelper::snmpIsAvailable()
    //     -- same gate as Mempools/Storage -- so it never runs for our
    //     snmp_disable devices, meaning $device->entityPhysical will
    //     only ever contain rows this module itself created.
    //   - LibreNMS\Modules\EntityPhysical::poll() is a real, deliberate
    //     no-op ("no polling") in the actual shipped module -- direct
    //     evidence this handoff's own suggested "discover-only" design
    //     is exactly how LibreNMS's native inventory concept already
    //     works, not a deviation invented for this check. No
    //     pollHardwareInventory() exists in this module; discover() is
    //     the only place this data is ever fetched or written.
    private const HARDWARE_INVENTORY_CHECK_NAME = 'hardware-inventory';

    // Same crc32-hash-of-a-stable-identity technique as
    // syntheticIfIndex() above, reused here for the same reason: WMI's
    // enumeration order for multi-instance classes (RAM sticks, disks,
    // NICs) isn't guaranteed stable across calls, so entPhysicalIndex
    // values need to come from a stable per-item identity string
    // (device ID / slot locator / MAC address), not array position.
    //
    // UNLIKE Port.ifIndex (a real bigInteger column), entPhysicalIndex
    // is a plain signed 32-bit `integer` column (confirmed by reading
    // 2018_07_03_091314_create_entPhysical_table.php) -- caught for
    // real, not just in review: an earlier version of this constant
    // used the same 1e9-wide-range pattern as
    // NETWORK_TRAFFIC_SYNTHETIC_IFINDEX_BASE with a 2_000_000_000 base,
    // which overflowed signed INT32's ~2.147B max
    // ("Numeric value out of range... 2902394847") on the very first
    // real device:discover run. Base+range kept safely under that
    // ceiling here, with room to spare.
    private const HARDWARE_INVENTORY_SYNTHETIC_INDEX_BASE = 500_000_000;

    private function syntheticEntPhysicalIndex(string $identity): int
    {
        return self::HARDWARE_INVENTORY_SYNTHETIC_INDEX_BASE + (crc32($identity) % 400_000_000);
    }

    /**
     * Deliberately NOT a Sensor-based check, unlike every other check
     * in this module -- the real SNMP-equivalent (includes/discovery/
     * ntp/cisco.inc.php, includes/polling/ntp/cisco.inc.php) uses
     * LibreNMS\Component plus a direct RRD write, not Sensor at all,
     * with its own dedicated device-apps page and four graphs
     * (device_ntp_stratum/offset/delay/dispersion) that key off
     * exactly this shape. See docs/WINRM_NTP_SYNC_STATUS_CHECK.md for
     * the full research (alexh/librenms-fork).
     *
     * Only ever ONE component per device here, unlike the native-MIB-
     * based module's model (which discovers one component per
     * *configured* NTP peer, genuinely plural) -- Windows' w32tm only
     * ever reports a single current sync source, so there's no real
     * list to discover, just one synthetic peer keyed by the
     * reference IP.
     *
     * Component.type/Application.app_type (2026-08-12): NTP_TYPE
     * (below), NOT the native-MIB-based module's shared 'ntp' key --
     * a distinct label ("NTP Client", not "NTP") is genuinely needed
     * (see docs/WINRM_NTP_SYNC_STATUS_CHECK.md's label section), which
     * means a distinct DB value, since StringHelpers.php's label
     * lookup is keyed off this exact string. The real ntp.inc.php
     * Apps page and four graphs stay the shared files, though --
     * only their Component `type` filter is widened (a one-line,
     * additive change per file, real native-MIB-monitored devices
     * completely unaffected) to also match NTP_TYPE, rather than
     * forking them.
     * An earlier version of this change *did* fork those five files
     * into WinRM-specific copies -- reverted (2026-08-12) as a bigger
     * footprint in shared/core UI territory than this project has
     * touched anywhere else, in favor of this narrower filter-widening
     * approach.
     *
     * Scoping dataExists()/cleanup()/dump() by NTP_TYPE alone is
     * additionally safe for the same reason already established for
     * every other check in this file: the real SNMP-based ntp module
     * is gated by the same connectivity check every other SNMP module
     * here is (see the HARDWARE_INVENTORY_CHECK_NAME constant
     * comment), so it never runs against a snmp_disable device in the
     * first place.
     */
    private const NTP_TYPE = 'ntp-client-winrm';

    private const NTP_SYNC_STATUS_CHECK_NAME = 'ntp-sync-status';

    /**
     * Runs the ntp-sync-status check and returns the raw validated
     * value array, or null on failure (already logged).
     *
     * @return array<string, mixed>|null
     */
    private function fetchNtpSyncStatus(WinrmProxy $proxy, Device $device): ?array
    {
        $result = $proxy->check($device->hostname, self::NTP_SYNC_STATUS_CHECK_NAME);

        if (! $result->ok) {
            Log::warning('winrm-poller: ' . $device->hostname . ': ' . self::NTP_SYNC_STATUS_CHECK_NAME . ' check failed: ' . $result->error);

            return null;
        }

        return $result->value;
    }

    /**
     * Windows' own "not synced" sentinel is Stratum 0 ("unspecified")
     * -- NOT the classic NTP-protocol/CISCO-NTP-MIB sentinel of
     * Stratum 16 ("unreachable"), confirmed by real testing (stopped
     * W32Time, restarted it, and read /query /status before the first
     * resync completed). Checked both explicitly rather than assuming
     * Windows follows the same convention the native-MIB-based
     * module's real code checks for -- assuming shape-identical
     * semantics without checking is exactly the mistake the "match
     * the SNMP shape" convention exists to catch, not something it
     * excuses.
     */
    private function ntpStratumIsBad(mixed $stratum): bool
    {
        return is_int($stratum) && ($stratum === 0 || $stratum === 16);
    }

    /**
     * Derives the `update_application()` $response/$status pair from
     * this check's own bad/error computation -- shared by
     * discoverNtpComponent() and pollNtpSyncStatus() so the
     * Application's app_state (and therefore the Apps-tab nav icon,
     * see the 2026-08-11 fix note on pollNtpSyncStatus() below) tracks
     * the same real state the Component's own status/error already
     * does, instead of two independently-derived judgements of the
     * same data drifting apart.
     *
     * update_application()'s own state machine (confirmed by reading
     * it directly): a non-empty $response not matching a handful of
     * SNMP-extend-specific sentinel patterns becomes app_state='OK'
     * unconditionally; a $response *starting with* the literal word
     * 'ERROR' becomes app_state='ERROR' with $response itself as the
     * stored app_status. Reused verbatim rather than inventing a
     * parallel mechanism -- the same real behavior winupdate-pending's
     * migration already leaned on for its own UNKNOWN-vs-OK split.
     *
     * @return array{0: string, 1: string} [$response, $status]
     */
    private function ntpApplicationResponse(bool $isBad, string $errorMessage, ?int $stratum): array
    {
        if ($isBad) {
            return ['ERROR: ' . $errorMessage, $errorMessage];
        }

        return [(string) $stratum, 'Stratum ' . $stratum];
    }

    /**
     * Creates the device's single ntp Component + its `applications`
     * row if neither exists yet -- discover()'s usual "creates
     * topology, poll() updates values" role, matching every other
     * check in this module. Skips entirely (not an error) if no
     * reference IP is available yet -- a genuine "hasn't synced since
     * the proxy started watching it" state, same as any other
     * optional-until-populated field elsewhere in this module. Does
     * NOT touch an already-existing component's peer/label --
     * pollNtpSyncStatus() owns keeping stratum/status/error current;
     * this only ever runs once per device in the normal case.
     */
    private function discoverNtpComponent(Device $device, array $ntpStatus): void
    {
        $referenceIp = $ntpStatus['reference_ip'] ?? null;
        if (! is_string($referenceIp) || $referenceIp === '') {
            return;
        }

        $component = new \LibreNMS\Component();
        $existing = $component->getComponents($device->device_id, ['type' => self::NTP_TYPE])[$device->device_id] ?? [];

        if (! empty($existing)) {
            return;
        }

        $created = $component->createComponent($device->device_id, self::NTP_TYPE);
        $componentId = array_key_first($created);

        $stratum = $ntpStatus['stratum'] ?? null;
        // Same null-is-also-bad fix as pollNtpSyncStatus() -- see that
        // method's comment. Reaching this branch already implies
        // $referenceIp was populated (guarded above), so $stratum is
        // almost always non-null here too in practice, but this stays
        // consistent with the poll-time logic rather than assuming
        // that correlation always holds.
        $isBad = $stratum === null || $this->ntpStratumIsBad($stratum);
        $errorMessage = match (true) {
            $stratum === null => 'NTP status unavailable (W32Time not running?)',
            $isBad => 'NTP is not in sync',
            default => '',
        };

        $source = $ntpStatus['source'] ?? null;
        $reachability = $ntpStatus['reachability'] ?? null;

        $component->setComponentPrefs($device->device_id, [
            $componentId => [
                'label' => $referenceIp . ':123',
                'status' => $isBad ? 2 : 0,
                'error' => $errorMessage,
                'peer' => $referenceIp,
                'port' => 123,
                'stratum' => $stratum,
                'peerref' => is_string($source) ? $source : '',
                'reachability' => is_int($reachability) ? $reachability : null,
            ],
        ]);

        // Matches includes/discovery/ntp/cisco.inc.php's own "insert
        // an applications row if one doesn't exist yet" step -- the
        // device-apps page tab is driven off this table, not the
        // component rows directly.
        $app = Application::firstOrCreate(
            ['device_id' => $device->device_id, 'app_type' => self::NTP_TYPE],
            ['app_status' => '', 'app_instance' => '']
        );

        [$response, $status] = $this->ntpApplicationResponse($isBad, $errorMessage, $stratum);
        update_application($app, $response, [], $status);
    }

    /**
     * Refreshes the already-discovered ntp Component's stratum/status/
     * error every poll cycle (same re-derive-on-every-poll pattern
     * includes/polling/ntp/cisco.inc.php itself uses), and writes the
     * stratum/offset/delay/dispersion RRD -- same dataset names as the
     * real SNMP-based module, `[NTP_TYPE, $peer]` filename convention
     * (NTP_TYPE constant comment above) instead of the native-MIB-
     * based module's `['ntp', $peer]` -- the existing device_ntp_*
     * graphs render this data via a small widened-filter change (real
     * native-MIB-monitored devices unaffected), not a copy of those
     * files. See docs/WINRM_NTP_SYNC_STATUS_CHECK.md.
     *
     * RootDelay/RootDispersion map to the `delay`/`dispersion`
     * datasets as the closest available Windows analog, not an exact
     * semantic match -- the native-MIB-based module's per-peer
     * delay/dispersion come from a live NTP protocol exchange with
     * that specific peer, while
     * Windows' Root Delay/Dispersion are the *cumulative* values
     * through the whole reference chain back to the stratum-1 source.
     * Worth being explicit about rather than implying these are
     * measuring identical things just because they share dataset
     * names.
     *
     * `peerref` (2026-08-11): shipped hardcoded to '', leaving the real
     * `ntp.inc.php` Apps page's "Peer Reference" column permanently
     * blank -- confirmed not transient (re-polled several more cycles,
     * the Component's app_state/timestamp never moved) and not a
     * labeling mismatch (cisco.inc.php really does display "NTP", not
     * "NTP Client" -- a different, unrelated real app). Now populated
     * from `source`, w32tm's own "Source:" line (previously parsed by
     * nothing at all). Deliberately NOT used to replace `peer`/
     * `$effectivePeer` above, which stay driven by `reference_ip` --
     * on the one real target this was checked against, Source and the
     * ReferenceId-decoded IP resolved to the same host (confirmed via
     * DNS), so there was no real evidence either way on whether they
     * can genuinely diverge, and `peer` already drives the RRD
     * filename and the proxy's own /stripchart offset-query target --
     * both already shipped and tested. Swapping that live, working
     * value to satisfy an empty cosmetic column would risk the same
     * orphaned-RRD problem this project has hit (and cleaned up)
     * twice already, for no confirmed real benefit.
     *
     * Apps-tab nav icon (2026-08-11): the placeholder `Application`
     * row's `app_state` shipped never updated past its DB default of
     * 'UNKNOWN' -- cisco.inc.php's own real polling
     * (includes/polling/ntp/cisco.inc.php) never touches it either,
     * confirmed by reading that file directly, so this genuinely
     * matched the real precedent. Fixed anyway: this check already
     * broke from the precedent once for a real reason (offset's
     * min=0 bug, see below) rather than reproducing a known-wrong
     * value just because the existing module has it too, and a
     * permanently "Unknown State" `?` icon on a check that's actively
     * confirming real, correct sync data is wrong on its own terms, independent
     * of what the precedent does. Reuses `update_application()` (the
     * same real function winupdate-pending's Application-based
     * app_state promotion already relies on) rather than inventing a
     * parallel mechanism -- `ntpApplicationResponse()` above derives
     * its $response/$status from the exact same $isBad/$errorMessage
     * already computed for the Component's own status/error fields,
     * so the nav icon and the Component's in-page status can't drift
     * apart into two different readings of the same poll cycle.
     */
    private function pollNtpSyncStatus(WinrmProxy $proxy, OS $os, DataStorageInterface $datastore): void
    {
        $device = $os->getDevice();

        $ntpStatus = $this->fetchNtpSyncStatus($proxy, $device);
        if ($ntpStatus === null) {
            return;
        }

        $component = new \LibreNMS\Component();
        $existing = $component->getComponents($device->device_id, ['type' => self::NTP_TYPE])[$device->device_id] ?? [];

        if (empty($existing)) {
            // Nothing discovered yet (e.g. this is the first poll
            // cycle after a discover that found no reference IP) --
            // discover() is what creates the component, not poll().
            return;
        }

        $componentId = array_key_first($existing);
        $peer = $existing[$componentId]['peer'] ?? null;

        $referenceIp = $ntpStatus['reference_ip'] ?? null;
        $stratum = $ntpStatus['stratum'] ?? null;

        // A real bug caught by testing before this shipped: a null
        // stratum (W32Time not running at all -- the JEA function's
        // own "fewer than 9 lines" guard) is a genuinely worse state
        // than a known Stratum 0 ("unspecified"), but
        // ntpStratumIsBad(null) returns false (is_int(null) is
        // false), so the first version of this silently reported "Ok"
        // for a stopped service. Checked explicitly for real by
        // stopping W32Time and polling, not assumed correct because
        // the null-stratum branch looked like it should already be
        // covered.
        $isBad = $stratum === null || $this->ntpStratumIsBad($stratum);
        $errorMessage = match (true) {
            $stratum === null => 'NTP status unavailable (W32Time not running?)',
            $isBad => 'NTP is not in sync',
            default => '',
        };

        // A changed reference IP (e.g. the device failed over to a
        // different upstream NTP source) updates the component's own
        // peer/label -- same "current data wins" precedent as every
        // other WinrmPoller update method (updateDeviceOsVersion(),
        // etc.), not left pinned to whatever was true at first
        // discovery. This does mean the RRD filename (keyed by peer)
        // changes too on a source change -- the same real limitation
        // the native-MIB-based module's own per-peer model has (a
        // peer whose address changes becomes, in effect, a new peer),
        // not a gap introduced here.
        $effectivePeer = is_string($referenceIp) && $referenceIp !== '' ? $referenceIp : $peer;
        $source = $ntpStatus['source'] ?? null;
        // reachability (2026-08-13): the classic NTP 8-bit "reach"
        // shift-register (0-255), confirmed via real live testing
        // (forced /resync, watched it climb 3->7->15->31->63->127->255
        // in lockstep with each successful poll -- see
        // docs/WINRM_NTP_SYNC_STATUS_CHECK.md's "Investigated:
        // Reachability/ValidDataCounter"). Stored as a plain Component
        // attribute (same as peerref), not an RRD dataset -- a raw
        // 0-255 bitmask isn't a naturally graphable continuous value
        // the way stratum/offset/frequency_ppb are. ValidDataCounter,
        // the sibling field from the same w32tm command, was
        // investigated too and deliberately NOT captured -- confirmed
        // empirically redundant with this value's own bit-population
        // count once both reach steady state.
        $reachability = $ntpStatus['reachability'] ?? null;

        $component->setComponentPrefs($device->device_id, [
            $componentId => [
                'label' => $effectivePeer . ':123',
                'status' => $isBad ? 2 : 0,
                'error' => $errorMessage,
                'peer' => $effectivePeer,
                'port' => 123,
                'stratum' => $stratum,
                'peerref' => is_string($source) ? $source : '',
                'reachability' => is_int($reachability) ? $reachability : null,
            ],
        ]);

        $app = Application::where('device_id', $device->device_id)->where('app_type', self::NTP_TYPE)->first();
        if ($app !== null) {
            [$response, $status] = $this->ntpApplicationResponse($isBad, $errorMessage, $stratum);
            update_application($app, $response, [], $status);
        }

        if (! is_string($effectivePeer) || $effectivePeer === '') {
            return;
        }

        $rootDelay = $ntpStatus['root_delay'] ?? null;
        $rootDispersion = $ntpStatus['root_dispersion'] ?? null;
        $offset = $ntpStatus['offset'] ?? null;
        // phase_offset/frequency_ppb (2026-08-12): genuine clock-
        // DISCIPLINE data -- how well the correction process itself is
        // working, not more sync-status detail -- added alongside the
        // four fields above, on the same Component/RrdDefinition, per
        // docs/WINRM_CLOCK_DISCIPLINE_RESEARCH.md's model research
        // (confirmed against the real chronyd precedent: one unified
        // app, not a split one, and chronyd's own comparable fields
        // are its top-level single-valued tracking record, not its
        // per-source one -- matches this Component's existing "one
        // synthetic peer, not several" shape exactly).
        //
        // frequency_ppb is deliberately NOT named/scaled to match
        // chronyd's own `frequency` dataset -- chronyd's is in PPM,
        // this one is native PPB (`\Windows Time Service\Clock
        // Frequency Adjustment (PPB)`, confirmed via Get-Counter, the
        // mechanism Microsoft's own real event-log text recommends for
        // this). A 1000x unit mismatch under the same bare name would
        // be a real, silent bug -- kept as Windows actually reports it
        // instead, under its own unit-suffixed name.
        $phaseOffset = $ntpStatus['phase_offset'] ?? null;
        $frequencyPpb = $ntpStatus['frequency_ppb'] ?? null;

        // Datastore::put()'s own docblock is explicit: "$fields ...
        // the order must be consistent with rrd_def" -- this is a
        // POSITIONAL contract, not a keyed lookup (confirmed by
        // reading Datastore::put()/write() directly, not assumed).
        // A first version of this conditionally omitted individual
        // keys when a field was null, which silently shifted every
        // later value into the wrong dataset slot once any one field
        // was missing -- caught by inspecting a real written RRD file
        // (ds[offset] held what was actually the delay value) before
        // this shipped. All six keys are always present, in the
        // exact order addDataset() below declares them, with null for
        // any field this cycle didn't have -- RRDtool's own "U"/
        // unknown handling for a null datapoint, not a fabricated 0.
        if (
            ! is_int($stratum)
            && ! is_float($rootDelay) && ! is_int($rootDelay)
            && ! is_float($rootDispersion) && ! is_int($rootDispersion)
            && ! is_float($offset) && ! is_int($offset)
            && ! is_float($phaseOffset) && ! is_int($phaseOffset)
            && ! is_float($frequencyPpb) && ! is_int($frequencyPpb)
        ) {
            // Nothing numeric to write this cycle (e.g. service just
            // stopped, everything null) -- same "skip the RRD write
            // rather than writing an all-unknown row" discipline as
            // every other check here.
            return;
        }

        $rrd = [
            'stratum' => $stratum,
            'offset' => $offset,
            'delay' => $rootDelay,
            'dispersion' => $rootDispersion,
            'phase_offset' => $phaseOffset,
            'frequency_ppb' => $frequencyPpb,
        ];

        $datastore->put($os->getDeviceArray(), self::NTP_TYPE, [
            'rrd_name' => [self::NTP_TYPE, $effectivePeer],
            // offset's min is deliberately null (unbounded), not 0 --
            // includes/polling/ntp/cisco.inc.php's own RrdDefinition
            // uses min=0 for offset too, despite its own offset value
            // being decoded from a SIGNED 16-bit field
            // (Number::constrainInteger(..., IntegerType::Int16)) and
            // therefore genuinely capable of going negative -- a real,
            // apparently-unnoticed edge case in the precedent being
            // matched here, not something to knowingly reproduce:
            // RRDtool records any datapoint below a dataset's declared
            // min as unknown, so a negative offset (clock briefly
            // ahead of its reference, not just behind) would silently
            // vanish from the graph under min=0. w32tm's own offset is
            // equally capable of being negative (confirmed by its
            // ±D.DDDDDDDs sign format), so this is fixed here rather
            // than copied. phase_offset/frequency_ppb are both
            // similarly signed (PhaseOffset carries a sign in its own
            // ±D.DDDDDDDs format; FrequencyPpb represents a correction
            // *direction*, not just magnitude) -- unbounded (null min)
            // for both from the start, not copied-then-fixed.
            'rrd_def' => RrdDefinition::make()
                ->addDataset('stratum', 'GAUGE', 0)
                ->addDataset('offset', 'GAUGE', null)
                ->addDataset('delay', 'GAUGE', 0)
                ->addDataset('dispersion', 'GAUGE', 0)
                ->addDataset('phase_offset', 'GAUGE', null)
                ->addDataset('frequency_ppb', 'GAUGE', null),
        ], $rrd);
    }

    /**
     * Migrated off the generic Sensor/checks() pattern (2026-08-11) --
     * built very early in this project, before "match the SNMP shape"
     * was a standing rule, and never actually checked against a real
     * precedent until now. The real one is `osupdate`
     * (includes/polling/applications/os-updates.inc.php,
     * includes/discovery/applications.inc.php) -- same concept
     * (pending update/package count), and it's Application-based
     * (App\Models\Application + application_metrics), not Sensor and
     * NOT LibreNMS\Component either -- a genuinely different, third
     * real data model from the one ntp-sync-status uses, confirmed by
     * reading includes/discovery/applications.inc.php directly rather
     * than assuming the NTP precedent's mechanism would transfer.
     *
     * Reuses osupdate's real app_type value verbatim: 'os-updates' --
     * NOT the SNMP-extend script name 'osupdate' that a first read of
     * the discovery file's own $applications array might suggest.
     * Confirmed by reading the discovery loop itself: $app =
     * $applications[$extend] resolves 'osupdate' (the walked SNMP
     * name) to 'os-updates' (the value actually written to
     * app_type, and what the polling dispatcher -- includes/polling/
     * applications.inc.php -- builds its include filename from). Using
     * the exact same value gets the real os-updates.inc.php UI page
     * and os-updates_packages graph for free, zero new UI code -- the
     * same "pending OS updates" tab and graph a Linux device would
     * show, which is the correct outcome here: a Windows host's
     * pending-update count and a package-manager's pending-package
     * count are the same real concept from an admin's point of view.
     *
     * Confirmed safe to scope by app_type alone for dataExists()/
     * cleanup()/dump() the same way NTP_SYNC_STATUS's Component
     * scoping is: the real SNMP-extend-based Applications discovery
     * (LibreNMS\Modules\LegacyModule, name='applications') requires
     * $connectivity->snmpIsAvailable() (confirmed by reading
     * LegacyModule::shouldPoll() directly), so it never runs against
     * an snmp_disable device -- any app_type='os-updates' row on one
     * of these devices can only have come from this module.
     *
     * Deliberately NOT calling update_application() with osupdate's
     * exact $response/$metrics values, despite reusing its app_type --
     * update_application()'s $response parameter is sniffed for
     * SNMP-extend-script-specific sentinel patterns (a Python
     * traceback, "Connection refused", an ERROR|LEGACY|UNSUPPORTED
     * prefix) that have no WinRM equivalent at all (a failed check
     * here is already handled the same way every other check in this
     * module handles a failed proxy call -- log and skip, never
     * reaching this method). What IS reused deliberately: passing an
     * EMPTY $response leaves $app->app_state at its 'UNKNOWN' default
     * (update_application()'s own real behavior, confirmed by reading
     * it directly, not assumed) -- exactly the right semantic for
     * "hasn't scanned yet", reusing the function's real state machine
     * rather than inventing a separate mechanism for the same
     * distinction winupdate-pending v2 already deliberately built
     * (PendingCount: null vs. a confirmed 0 -- see
     * WINRM_WINUPDATE_PENDING_CHECK.md's v2 section).
     *
     * LastScanTime has no home in osupdate's own single-dataset RRD
     * shape (just 'packages') -- application_metrics isn't restricted
     * to only what's graphed, so it's stored there as an additional,
     * non-graphed, unix-timestamp metric (update_application()'s own
     * metrics-writing code does `(float) $value` on every metric,
     * confirmed by reading it -- a datetime string wouldn't survive
     * that cast, a unix timestamp does) rather than dropped silently
     * or awkwardly folded into the short status text.
     */
    private const WINUPDATE_PENDING_CHECK_NAME = 'winupdate-pending';

    private const WINUPDATE_PENDING_APP_TYPE = 'os-updates';

    /**
     * Runs the winupdate-pending check and returns the raw validated
     * value array, or null on failure (already logged).
     *
     * @return array<string, mixed>|null
     */
    private function fetchWinupdatePending(WinrmProxy $proxy, Device $device): ?array
    {
        $result = $proxy->check($device->hostname, self::WINUPDATE_PENDING_CHECK_NAME);

        if (! $result->ok) {
            Log::warning('winrm-poller: ' . $device->hostname . ': ' . self::WINUPDATE_PENDING_CHECK_NAME . ' check failed: ' . $result->error);

            return null;
        }

        return $result->value;
    }

    /**
     * discover()'s usual "creates topology" role -- just the
     * Application row here (no Component, unlike ntp-sync-status; see
     * this section's own doc comment for why), matching
     * includes/discovery/applications.inc.php's own
     * firstOrNew()+discovered=1 pattern. Safe to call every discover
     * cycle -- firstOrNew() is a no-op against an already-existing row.
     */
    private function discoverWinupdateApplication(Device $device): void
    {
        $app = Application::withTrashed()->firstOrNew([
            'device_id' => $device->device_id,
            'app_type' => self::WINUPDATE_PENDING_APP_TYPE,
        ]);

        if ($app->trashed()) {
            $app->restore();
        }

        $app->discovered = 1;
        $app->save();
    }

    /**
     * Refreshes the app's status/RRD/metrics every poll cycle, same
     * "poll refreshes values" role as pollNtpSyncStatus() and every
     * other poll*() method here.
     */
    private function pollWinupdatePending(WinrmProxy $proxy, OS $os, DataStorageInterface $datastore): void
    {
        $device = $os->getDevice();

        $winupdate = $this->fetchWinupdatePending($proxy, $device);
        if ($winupdate === null) {
            return;
        }

        $app = Application::where('device_id', $device->device_id)
            ->where('app_type', self::WINUPDATE_PENDING_APP_TYPE)
            ->first();

        if ($app === null) {
            // Nothing discovered yet -- discover() is what creates
            // the Application row, not poll().
            return;
        }

        $pendingCount = $winupdate['pending_count'] ?? null;
        $lastScanTime = $winupdate['last_scan_time'] ?? null;

        if (is_int($pendingCount)) {
            $datastore->put($os->getDeviceArray(), 'app', [
                'name' => self::WINUPDATE_PENDING_APP_TYPE,
                'app_id' => $app->app_id,
                'rrd_name' => ['app', self::WINUPDATE_PENDING_APP_TYPE, $app->app_id],
                'rrd_def' => RrdDefinition::make()->addDataset('packages', 'GAUGE', 0),
            ], ['packages' => $pendingCount]);
        }

        $metrics = [];
        if (is_int($pendingCount)) {
            $metrics['packages'] = $pendingCount;
        }
        if (is_string($lastScanTime) && $lastScanTime !== '') {
            $scanTimestamp = strtotime($lastScanTime);
            if ($scanTimestamp !== false) {
                $metrics['last_scan_time'] = $scanTimestamp;
            }
        }

        // Empty $response leaves $app->app_state at update_application()'s
        // own 'UNKNOWN' default (confirmed by reading the function
        // directly) -- the right state for "hasn't scanned yet",
        // distinct from 'OK' (a confirmed count, even zero).
        $response = is_int($pendingCount) ? (string) $pendingCount : '';
        $status = is_int($pendingCount) ? (string) $pendingCount : 'not yet scanned';

        update_application($app, $response, $metrics, $status);
    }

    /**
     * Runs the cpu-usage check and returns validated per-core readings,
     * or null on failure (already logged). No Keyable/SyncsModels
     * machinery to lean on for Processor (see the constant comment
     * above), so this returns raw validated data rather than built
     * model instances -- discover()/pollCpuUsage() each do their own
     * manual upsert-by-hand, same shape as fetchNetworkInterfaces()'s
     * Port handling (which has the same no-Keyable situation).
     *
     * @return array<int, array{index: string, busy_percent: int|float}>|null
     */
    private function fetchCpuCores(WinrmProxy $proxy, Device $device): ?array
    {
        $result = $proxy->check($device->hostname, self::CPU_USAGE_CHECK_NAME);

        if (! $result->ok) {
            Log::warning('winrm-poller: ' . $device->hostname . ': ' . self::CPU_USAGE_CHECK_NAME . ' check failed: ' . $result->error);

            return null;
        }

        $cores = $result->value['cores'] ?? null;
        if (! is_array($cores)) {
            Log::warning('winrm-poller: ' . $device->hostname . ': ' . self::CPU_USAGE_CHECK_NAME . ' returned no cores array: ' . var_export($result->value, true));

            return null;
        }

        $valid = [];
        foreach ($cores as $core) {
            // Defensive even though the proxy already validates this
            // shape (app/checks/cpu_usage.py) -- same "don't trust the
            // wire format blindly" stance as every other check.
            $index = $core['core_index'] ?? null;
            $busyPercent = $core['busy_percent'] ?? null;

            if (! is_string($index) || $index === ''
                || (! is_int($busyPercent) && ! is_float($busyPercent))) {
                Log::warning('winrm-poller: malformed cpu core entry, skipping: ' . var_export($core, true));

                continue;
            }

            $valid[] = ['index' => $index, 'busy_percent' => $busyPercent];
        }

        return $valid;
    }

    // Windows' System.ServiceProcess.ServiceControllerStatus enum values,
    // used as-is for the service-status checks' sensor state values --
    // keeps the DB value meaningful outside this module too, not an
    // arbitrary renumbering.
    private const SERVICE_STATUS_NAME_TO_VALUE = [
        'Stopped' => 1,
        'StartPending' => 2,
        'StopPending' => 3,
        'Running' => 4,
        'ContinuePending' => 5,
        'PausePending' => 6,
        'Paused' => 7,
    ];

    /**
     * Every check this module knows about, keyed by check_name (the
     * exact string sent to the proxy). discover()/poll()/dataExists()/
     * cleanup()/dump() all loop over this uniformly -- adding a check
     * means adding an entry here, not a new code path. Each entry:
     *   sensor_type  - sensor_type/create_state_index() name, unique per check
     *   sensor_class - 'state' (discrete states, needs 'states'+'map') or
     *                  'count' (raw numeric value, no 'states'/'map')
     *   sensor_oid   - stable unique string, not a real OID
     *   sensor_descr - human label
     *   states       - only for sensor_class='state'. create_state_index()'s
     *                  states list. generic=0 (neutral) throughout -- these
     *                  checks report raw state only, no expected-state/
     *                  alerting logic baked in (see
     *                  docs/WINRM_SERVICE_MONITORING_PATTERNS.md);
     *                  thresholds are the operator's job via the existing
     *                  sensor-limits UI, same as any other state sensor.
     *   value_key    - which key to read out of the check result's value dict
     *   map          - only for sensor_class='state'. raw value (bool|string)
     *                  -> state index (int|null; null means "couldn't map
     *                  this, log and skip"). sensor_class='count' checks use
     *                  the raw numeric value directly, no mapping step.
     *
     * @return array<string, array{sensor_type: string, sensor_class: string, sensor_oid: string, sensor_descr: string, states?: array, value_key: string, map?: callable}>
     */
    private static function checks(): array
    {
        $serviceStatusStates = array_map(
            fn (string $name, int $value) => ['value' => $value, 'generic' => 0, 'graph' => 1, 'descr' => $name],
            array_keys(self::SERVICE_STATUS_NAME_TO_VALUE),
            array_values(self::SERVICE_STATUS_NAME_TO_VALUE),
        );
        $mapServiceStatus = fn ($raw) => self::SERVICE_STATUS_NAME_TO_VALUE[$raw] ?? null;

        return [
            'reboot-pending' => [
                'sensor_type' => 'winrm-reboot-pending',
                'sensor_class' => 'state',
                'sensor_oid' => 'winrm.reboot_pending',
                'sensor_descr' => 'Reboot pending',
                'states' => [
                    ['value' => 0, 'generic' => 0, 'graph' => 0, 'descr' => 'No reboot pending'],
                    ['value' => 1, 'generic' => 1, 'graph' => 1, 'descr' => 'Reboot pending'],
                ],
                'value_key' => 'reboot_pending',
                'map' => fn ($raw) => $raw ? 1 : 0,
            ],
            'service-status-wuauserv' => [
                'sensor_type' => 'winrm-service-status-wuauserv',
                'sensor_class' => 'state',
                'sensor_oid' => 'winrm.service_status_wuauserv',
                'sensor_descr' => 'wuauserv service status',
                'states' => $serviceStatusStates,
                'value_key' => 'status',
                'map' => $mapServiceStatus,
            ],
            'service-status-w32time' => [
                'sensor_type' => 'winrm-service-status-w32time',
                'sensor_class' => 'state',
                'sensor_oid' => 'winrm.service_status_w32time',
                'sensor_descr' => 'W32Time service status',
                'states' => $serviceStatusStates,
                'value_key' => 'status',
                'map' => $mapServiceStatus,
            ],
        ];
    }

    private static function sensorTypes(): array
    {
        return array_column(self::checks(), 'sensor_type');
    }

    /**
     * Runs the network-traffic check and returns the validated interface
     * list, or null on failure (already logged). Shared by discover()
     * and poll() -- the check itself is identical either way, same
     * split as fetchDiskStorageModels().
     *
     * @return array<int, array{name: string, bytes_sent: int, bytes_received: int, net_enabled: bool|null, net_connection_status: int|null}>|null
     */
    private function fetchNetworkInterfaces(WinrmProxy $proxy, Device $device): ?array
    {
        $result = $proxy->check($device->hostname, self::NETWORK_TRAFFIC_CHECK_NAME);

        if (! $result->ok) {
            Log::warning('winrm-poller: ' . $device->hostname . ': ' . self::NETWORK_TRAFFIC_CHECK_NAME . ' check failed: ' . $result->error);

            return null;
        }

        $interfaces = $result->value['interfaces'] ?? null;
        if (! is_array($interfaces)) {
            Log::warning('winrm-poller: ' . $device->hostname . ': ' . self::NETWORK_TRAFFIC_CHECK_NAME . ' returned no interfaces array: ' . var_export($result->value, true));

            return null;
        }

        $valid = [];
        foreach ($interfaces as $interface) {
            // Defensive even though the proxy already validates this
            // shape (app/checks/network_traffic.py) -- same "don't
            // trust the wire format just because the producer also
            // validates" stance as fetchDiskStorageModels().
            $name = $interface['interface_name'] ?? null;
            $bytesSent = $interface['bytes_sent'] ?? null;
            $bytesReceived = $interface['bytes_received'] ?? null;

            if (! is_string($name) || $name === ''
                || (! is_int($bytesSent) && ! is_float($bytesSent))
                || (! is_int($bytesReceived) && ! is_float($bytesReceived))) {
                Log::warning('winrm-poller: malformed interface entry, skipping: ' . var_export($interface, true));

                continue;
            }

            // net_enabled/net_connection_status (2026-08-10 fix): both
            // legitimately null (the check's own explicit "no
            // Win32_NetworkAdapter correlated" result, or absent
            // entirely from an older cached response) -- validated
            // loosely, unlike the required fields above, since null is
            // a real expected state here, not a malformed one.
            $netEnabled = $interface['net_enabled'] ?? null;
            if ($netEnabled !== null && ! is_bool($netEnabled)) {
                Log::warning('winrm-poller: malformed interface entry (net_enabled), skipping: ' . var_export($interface, true));

                continue;
            }

            $netConnectionStatus = $interface['net_connection_status'] ?? null;
            if ($netConnectionStatus !== null && ! is_int($netConnectionStatus)) {
                Log::warning('winrm-poller: malformed interface entry (net_connection_status), skipping: ' . var_export($interface, true));

                continue;
            }

            $valid[] = [
                'name' => $name,
                'bytes_sent' => $bytesSent,
                'bytes_received' => $bytesReceived,
                'net_enabled' => $netEnabled,
                'net_connection_status' => $netConnectionStatus,
            ];
        }

        return $valid;
    }

    /**
     * Maps Win32_NetworkAdapter's NetEnabled/NetConnectionStatus to
     * LibreNMS's Port.ifAdminStatus/ifOperStatus. Both are typed
     * LibreNMS\Enum\IfOperStatus|null on the model (a real backed enum
     * with an Eloquent Castable, confirmed by phpstan flagging a plain-
     * string assignment as a type error -- not the bare 'up'/'down'
     * strings this might look like from the DB column's actual stored
     * values alone, which is all that checking real SNMP-populated Port
     * rows on lnms-poller.vpp.local could confirm). Either or both null whenever the
     * underlying WMI value is null or doesn't map to a clear up/down
     * state (NetConnectionStatus has several states -- hardware not
     * present/disabled/malfunctioning/authenticating -- deliberately
     * left unmapped rather than forced into Up or Down) -- same
     * non-fabrication principle fetchNetworkInterfaces() already applies
     * to a missing WMI correlation, just applied here to an ambiguous
     * value instead of a missing one.
     *
     * @return array{ifAdminStatus: IfOperStatus|null, ifOperStatus: IfOperStatus|null}
     */
    private function mapPortStatus(?bool $netEnabled, ?int $netConnectionStatus): array
    {
        $ifAdminStatus = $netEnabled === null ? null : ($netEnabled ? IfOperStatus::Up : IfOperStatus::Down);

        $ifOperStatus = match ($netConnectionStatus) {
            2 => IfOperStatus::Up,
            0, 7 => IfOperStatus::Down,
            default => null,
        };

        return ['ifAdminStatus' => $ifAdminStatus, 'ifOperStatus' => $ifOperStatus];
    }

    /**
     * Runs the disk-space check and builds (but does not save or sync)
     * one Storage instance per disk in the result. Shared by discover()
     * (which syncs the built models) and poll() (which matches them
     * against already-discovered rows by composite key) -- the check
     * itself is identical either way, only what happens with the result
     * differs.
     *
     * @return Collection<int, Storage>|null null means the check failed
     *                                       or returned unusable data;
     *                                       caller should skip and log,
     *                                       already logged here.
     */
    private function fetchDiskStorageModels(WinrmProxy $proxy, Device $device): ?Collection
    {
        $result = $proxy->check($device->hostname, self::DISK_SPACE_CHECK_NAME);

        if (! $result->ok) {
            Log::warning('winrm-poller: ' . $device->hostname . ': ' . self::DISK_SPACE_CHECK_NAME . ' check failed: ' . $result->error);

            return null;
        }

        $disks = $result->value['disks'] ?? null;
        if (! is_array($disks)) {
            Log::warning('winrm-poller: ' . $device->hostname . ': ' . self::DISK_SPACE_CHECK_NAME . ' returned no disks array: ' . var_export($result->value, true));

            return null;
        }

        return collect($disks)->map(function ($disk) {
            // Defensive even though the proxy already validates this
            // shape (app/checks/disk_space.py) -- this module shouldn't
            // trust the wire format blindly just because the check that
            // produced it happens to validate on its own side too.
            $driveLetter = $disk['drive_letter'] ?? null;
            $sizeBytes = $disk['size_bytes'] ?? null;
            $freeBytes = $disk['free_bytes'] ?? null;

            if (! is_string($driveLetter) || $driveLetter === ''
                || (! is_int($sizeBytes) && ! is_float($sizeBytes))
                || (! is_int($freeBytes) && ! is_float($freeBytes))) {
                Log::warning('winrm-poller: malformed disk entry, skipping: ' . var_export($disk, true));

                return null;
            }

            $storage = new Storage([
                'type' => self::STORAGE_TYPE,
                'storage_index' => $driveLetter,
                'storage_type' => 'Fixed Disk',
                'storage_descr' => $driveLetter,
                'storage_units' => 1,
            ]);
            // fillUsage(used, total, free, percent) -- used/percent left
            // null, derived from total+free (Number::fillMissingRatio),
            // matching exactly what the check provides (size+free, not
            // used directly).
            $storage->fillUsage(null, $sizeBytes, $freeBytes, null);

            return $storage;
        })->filter()->values();
    }

    /**
     * Runs the memory-usage check and returns the raw validated value
     * array, or null on failure (already logged). Split out from the
     * old fetchMemoryMempoolModels() (2026-08-10) so pollMemoryUsage()
     * can fetch the check's raw data ONCE per poll and use it for both
     * mempool values AND uptime (last_boot_up_time), rather than two
     * separate WinRM round-trips to the same check every poll cycle --
     * a real inefficiency this project has been deliberately careful
     * about elsewhere (see the proxy concurrency-limiting work).
     *
     * @return array<string, mixed>|null
     */
    private function fetchMemoryUsageRaw(WinrmProxy $proxy, Device $device): ?array
    {
        $result = $proxy->check($device->hostname, self::MEMORY_USAGE_CHECK_NAME);

        if (! $result->ok) {
            Log::warning('winrm-poller: ' . $device->hostname . ': ' . self::MEMORY_USAGE_CHECK_NAME . ' check failed: ' . $result->error);

            return null;
        }

        $physical = $result->value['physical'] ?? null;
        $virtual = $result->value['virtual'] ?? null;
        $pageFiles = $result->value['page_files'] ?? null;

        if (! is_array($physical) || ! is_array($virtual) || ! is_array($pageFiles)) {
            Log::warning('winrm-poller: ' . $device->hostname . ': ' . self::MEMORY_USAGE_CHECK_NAME . ' returned an unexpected shape: ' . var_export($result->value, true));

            return null;
        }

        return $result->value;
    }

    /**
     * Runs the memory-usage check and builds (but does not save or sync)
     * one Mempool instance per reading -- physical, virtual, and one per
     * page file. Shared by discover() and poll(), same split as
     * fetchDiskStorageModels().
     *
     * @return Collection<int, Mempool>|null null means the check failed
     *                                       or returned unusable data;
     *                                       caller should skip and log,
     *                                       already logged here.
     */
    private function fetchMemoryMempoolModels(WinrmProxy $proxy, Device $device): ?Collection
    {
        $raw = $this->fetchMemoryUsageRaw($proxy, $device);
        if ($raw === null) {
            return null;
        }

        return $this->buildMemoryMempoolModels($raw);
    }

    /**
     * @param  array<string, mixed>  $raw  a value already validated by
     *                                     fetchMemoryUsageRaw()
     * @return Collection<int, Mempool>
     */
    private function buildMemoryMempoolModels(array $raw): Collection
    {
        $physical = $raw['physical'];
        $virtual = $raw['virtual'];
        $pageFiles = $raw['page_files'];

        $mempools = collect();

        // Defensive even though the proxy already validates this shape
        // (app/checks/memory_usage.py) -- same "don't trust the wire
        // format blindly" stance as fetchDiskStorageModels()/
        // fetchNetworkInterfaces().
        $isValidPair = fn ($pair) => (is_int($pair['total_bytes'] ?? null) || is_float($pair['total_bytes'] ?? null))
            && (is_int($pair['free_bytes'] ?? null) || is_float($pair['free_bytes'] ?? null));

        if ($isValidPair($physical)) {
            $mempool = new Mempool([
                'mempool_type' => self::MEMPOOL_TYPE,
                'mempool_index' => 'physical',
                'mempool_class' => 'system',
                'mempool_descr' => 'Physical Memory',
            ]);
            $mempool->fillUsage(null, $physical['total_bytes'], $physical['free_bytes'], null);
            $mempools->push($mempool);
        } else {
            Log::warning('winrm-poller: malformed physical memory entry, skipping: ' . var_export($physical, true));
        }

        if ($isValidPair($virtual)) {
            $mempool = new Mempool([
                'mempool_type' => self::MEMPOOL_TYPE,
                'mempool_index' => 'virtual',
                'mempool_class' => 'virtual',
                'mempool_descr' => 'Virtual Memory',
            ]);
            $mempool->fillUsage(null, $virtual['total_bytes'], $virtual['free_bytes'], null);
            $mempools->push($mempool);
        } else {
            Log::warning('winrm-poller: malformed virtual memory entry, skipping: ' . var_export($virtual, true));
        }

        foreach ($pageFiles as $ordinal => $pageFile) {
            $name = $pageFile['name'] ?? null;
            $allocatedBytes = $pageFile['allocated_bytes'] ?? null;
            $usedBytes = $pageFile['used_bytes'] ?? null;

            if (! is_string($name) || $name === ''
                || (! is_int($allocatedBytes) && ! is_float($allocatedBytes))
                || (! is_int($usedBytes) && ! is_float($usedBytes))) {
                Log::warning('winrm-poller: malformed page file entry, skipping: ' . var_export($pageFile, true));

                continue;
            }

            $mempool = new Mempool([
                'mempool_type' => self::MEMPOOL_TYPE,
                'mempool_index' => $this->pageFileMempoolIndex($ordinal),
                'mempool_class' => 'swap',
                'mempool_descr' => substr($name, 0, 64),
            ]);
            // fillUsage(used, total, free, percent) -- given used+total
            // (allocated_bytes IS the page file's total size), free/
            // percent derived. Different pairing than physical/virtual
            // above (which give total+free), because that's what each
            // WMI class actually reports -- Win32_PageFileUsage has no
            // "free" field, only allocated size and current usage.
            $mempool->fillUsage($usedBytes, $allocatedBytes, null, null);
            $mempools->push($mempool);
        }

        return $mempools;
    }

    /**
     * Runs the hardware-inventory check and returns the raw validated
     * value array, or null on failure (already logged). Unlike other
     * fetch*() helpers, this doesn't build model instances itself --
     * the aggregate shape (3 singleton sections + 4 list sections) is
     * built into full EntPhysical rows by buildEntPhysicalModels()
     * instead, kept as a separate step for readability given how much
     * bigger this shape is than any prior check's.
     *
     * @return array<string, mixed>|null
     */
    private function fetchHardwareInventory(WinrmProxy $proxy, Device $device): ?array
    {
        $result = $proxy->check($device->hostname, self::HARDWARE_INVENTORY_CHECK_NAME);

        if (! $result->ok) {
            Log::warning('winrm-poller: ' . $device->hostname . ': ' . self::HARDWARE_INVENTORY_CHECK_NAME . ' check failed: ' . $result->error);

            return null;
        }

        $inventory = $result->value;

        // Defensive even though the proxy already validates this shape
        // (app/checks/hardware_inventory.py) -- same "don't trust the
        // wire format blindly" stance as every other check. Only checks
        // top-level shape here; per-entry validation happens in
        // buildEntPhysicalModels() where each section is actually
        // consumed, matching fetchNetworkInterfaces()/
        // fetchDiskStorageModels()'s per-entry-validation style.
        $requiredKeys = ['bios', 'base_board', 'chassis', 'processors', 'memory', 'disks', 'network_adapters'];
        foreach ($requiredKeys as $key) {
            if (! is_array($inventory[$key] ?? null)) {
                Log::warning('winrm-poller: ' . $device->hostname . ': ' . self::HARDWARE_INVENTORY_CHECK_NAME . ' returned an unexpected shape: ' . var_export($result->value, true));

                return null;
            }
        }

        return $inventory;
    }

    /**
     * Builds (unsaved) EntPhysical rows from a validated hardware
     * inventory array -- a chassis root plus BIOS/base board/each CPU/
     * each RAM stick/each disk/each network adapter nested directly
     * under it (a flat two-level tree, not attempting a deeper
     * chassis->baseboard->component hierarchy WMI has no natural basis
     * for). entPhysicalClass='chassis' on the root gets a real icon in
     * includes/html/pages/device/entphysical.inc.php's tree view;
     * everything else uses 'other' (a real, if generic, ENTITY-MIB
     * PhysicalClass value) rather than reaching for a more specific
     * class (e.g. 'module') this data has no real basis to justify.
     *
     * @return Collection<int, EntPhysical>
     */
    private function buildEntPhysicalModels(array $inventory): Collection
    {
        $rows = collect();
        $chassisIndex = $this->syntheticEntPhysicalIndex('chassis');

        $chassis = $inventory['chassis'];
        $rows->push(new EntPhysical([
            'entPhysicalIndex' => $chassisIndex,
            'entPhysicalContainedIn' => 0,
            'entPhysicalParentRelPos' => -1,
            'entPhysicalClass' => 'chassis',
            'entPhysicalName' => 'Chassis',
            'entPhysicalDescr' => 'System Enclosure',
            'entPhysicalModelName' => '',
            'entPhysicalSerialNum' => $chassis['serial_number'] ?? '',
            'entPhysicalMfgName' => $chassis['manufacturer'] ?? '',
            'entPhysicalAssetID' => $chassis['asset_tag'] ?: null,
        ]));

        $bios = $inventory['bios'];
        $rows->push(new EntPhysical([
            'entPhysicalIndex' => $this->syntheticEntPhysicalIndex('bios'),
            'entPhysicalContainedIn' => $chassisIndex,
            'entPhysicalParentRelPos' => -1,
            'entPhysicalClass' => 'other',
            'entPhysicalName' => 'BIOS',
            'entPhysicalDescr' => 'System BIOS',
            'entPhysicalModelName' => '',
            'entPhysicalSerialNum' => $bios['serial_number'] ?? '',
            'entPhysicalMfgName' => $bios['manufacturer'] ?? '',
            // BIOS version stored as "software revision" -- a genuine
            // fit for that field, not a stretch.
            'entPhysicalSoftwareRev' => $bios['version'] ?: null,
        ]));

        $baseBoard = $inventory['base_board'];
        $rows->push(new EntPhysical([
            'entPhysicalIndex' => $this->syntheticEntPhysicalIndex('baseboard'),
            'entPhysicalContainedIn' => $chassisIndex,
            'entPhysicalParentRelPos' => -1,
            'entPhysicalClass' => 'other',
            'entPhysicalName' => 'Base Board',
            'entPhysicalDescr' => 'Motherboard',
            'entPhysicalModelName' => $baseBoard['product'] ?? '',
            'entPhysicalSerialNum' => $baseBoard['serial_number'] ?? '',
            'entPhysicalMfgName' => $baseBoard['manufacturer'] ?? '',
        ]));

        foreach ($inventory['processors'] as $cpu) {
            // Identity field (device_id) is validated strictly by
            // app/checks/hardware_inventory.py already, but re-checked
            // here too -- same "don't trust the wire format blindly"
            // stance as every other check's per-entry validation.
            if (! is_array($cpu) || ! is_string($cpu['device_id'] ?? null) || $cpu['device_id'] === '') {
                Log::warning('winrm-poller: malformed processor inventory entry, skipping: ' . var_export($cpu, true));

                continue;
            }

            $rows->push(new EntPhysical([
                'entPhysicalIndex' => $this->syntheticEntPhysicalIndex('cpu:' . $cpu['device_id']),
                'entPhysicalContainedIn' => $chassisIndex,
                'entPhysicalParentRelPos' => -1,
                'entPhysicalClass' => 'other',
                'entPhysicalName' => $cpu['device_id'],
                'entPhysicalDescr' => $cpu['name'] ?: 'Processor',
                'entPhysicalModelName' => $cpu['name'] ?? '',
                'entPhysicalSerialNum' => '',
                'entPhysicalMfgName' => $cpu['manufacturer'] ?? '',
            ]));
        }

        foreach ($inventory['memory'] as $mem) {
            if (! is_array($mem) || ! is_string($mem['device_locator'] ?? null) || $mem['device_locator'] === '') {
                Log::warning('winrm-poller: malformed memory inventory entry, skipping: ' . var_export($mem, true));

                continue;
            }

            $descr = 'Physical Memory';
            $capacityBytes = $mem['capacity_bytes'] ?? null;
            if (is_int($capacityBytes) || is_float($capacityBytes)) {
                $descr .= ' (' . round($capacityBytes / 1024 ** 3, 1) . ' GB)';
            }

            $rows->push(new EntPhysical([
                'entPhysicalIndex' => $this->syntheticEntPhysicalIndex('memory:' . $mem['device_locator']),
                'entPhysicalContainedIn' => $chassisIndex,
                'entPhysicalParentRelPos' => -1,
                'entPhysicalClass' => 'other',
                'entPhysicalName' => $mem['device_locator'],
                'entPhysicalDescr' => $descr,
                'entPhysicalModelName' => $mem['part_number'] ?? '',
                'entPhysicalSerialNum' => $mem['serial_number'] ?? '',
                'entPhysicalMfgName' => $mem['manufacturer'] ?? '',
            ]));
        }

        foreach ($inventory['disks'] as $disk) {
            if (! is_array($disk) || ! is_string($disk['device_id'] ?? null) || $disk['device_id'] === '') {
                Log::warning('winrm-poller: malformed disk inventory entry, skipping: ' . var_export($disk, true));

                continue;
            }

            $interfaceType = $disk['interface_type'] ?? null;
            $descr = 'Physical Disk' . ($interfaceType ? " ({$interfaceType})" : '');

            $rows->push(new EntPhysical([
                'entPhysicalIndex' => $this->syntheticEntPhysicalIndex('disk:' . $disk['device_id']),
                'entPhysicalContainedIn' => $chassisIndex,
                'entPhysicalParentRelPos' => -1,
                'entPhysicalClass' => 'other',
                'entPhysicalName' => $disk['device_id'],
                'entPhysicalDescr' => $descr,
                'entPhysicalModelName' => $disk['model'] ?? '',
                'entPhysicalSerialNum' => $disk['serial_number'] ?? '',
                'entPhysicalMfgName' => '',
                'entPhysicalFirmwareRev' => $disk['firmware_revision'] ?: null,
            ]));
        }

        foreach ($inventory['network_adapters'] as $nic) {
            if (! is_array($nic) || ! is_string($nic['mac_address'] ?? null) || $nic['mac_address'] === '') {
                Log::warning('winrm-poller: malformed network adapter inventory entry, skipping: ' . var_export($nic, true));

                continue;
            }

            $rows->push(new EntPhysical([
                'entPhysicalIndex' => $this->syntheticEntPhysicalIndex('nic:' . $nic['mac_address']),
                'entPhysicalContainedIn' => $chassisIndex,
                'entPhysicalParentRelPos' => -1,
                'entPhysicalClass' => 'other',
                'entPhysicalName' => $nic['name'] ?: $nic['mac_address'],
                'entPhysicalDescr' => 'Network Adapter',
                'entPhysicalModelName' => $nic['name'] ?? '',
                'entPhysicalSerialNum' => '',
                'entPhysicalMfgName' => $nic['manufacturer'] ?? '',
                // MAC stored as Alias, not SerialNum -- it's an
                // identifier, not a manufacturer-assigned serial, and
                // entPhysicalAlias is the free-text field the existing
                // view already renders as "Alias: <value>".
                'entPhysicalAlias' => $nic['mac_address'],
            ]));
        }

        return $rows;
    }

    /**
     * Sets Device.version/Device.features from the check's operating_system
     * data. Revised same day as first written -- the initial version got
     * these two fields backwards, caught only because the user looked at
     * the real rendered devices-list row ("Microsoft Windows
     * 10.0.17763 (Windows Server 2019 Standard)") and asked "is it
     * Windows 10 or 2019", which doesn't fully make sense: 10.0.17763 is
     * shared between Windows 10 1809 and Server 2019 (Microsoft's server
     * SKUs share their raw NT version number with the client release
     * they're derived from), so a bare build number next to a generic
     * "Microsoft Windows" label is genuinely ambiguous on its own.
     *
     * The fix: read the REAL SNMP-based Windows precedent this project
     * had missed on the first pass -- LibreNMS's actual "Windows" OS
     * driver class's discoverOS() (not just the generic "Os" module,
     * which only handles generic field plumbing, not per-OS content).
     * That driver parses Windows' SNMP sysDescr string into:
     *   - Device.version = "<edition> (<release-codename>)", e.g.
     *     "Server 2019 Datacenter (1809)" -- edition AND the human
     *     release codename together, NOT the raw NT build number, which
     *     is never shown to the user directly by the real convention.
     *   - Device.features = "Multiprocessor" or "Uniprocessor" -- an
     *     SMP-kernel-build descriptor. NOT the OS edition/distro string
     *     every other OS type uses this field for (this project's first
     *     pass assumed Windows would follow that same pattern; it
     *     doesn't).
     *
     * Deliberately does NOT copy the real driver's build-number-keyed
     * lookup tables (getServerVersion()/getDatacenterVersion()/etc.) to
     * derive the edition name -- WMI's Caption already gives the exact
     * edition string directly and authoritatively, without a hardcoded
     * table needing a code change for every future Windows release. The
     * release codename ("1809") isn't available from
     * Win32_OperatingSystem at all -- sourced from the registry
     * (HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion,
     * DisplayVersion preferred over the older ReleaseId value name) by
     * the JEA function instead, same registry-read mechanism
     * reboot-pending already uses.
     *
     * Same "write directly to the shared Device model, the real SNMP-
     * gated module never runs for these snmp_disable devices anyway"
     * situation as Storage/Mempool/Processor/EntPhysical -- confirmed by
     * reading LibreNMS\Modules\Os::shouldDiscover()/shouldPoll()
     * directly (require ConnectivityHelper::snmpIsAvailable()).
     *
     * Deliberately does NOT set Device.hardware -- interestingly, the
     * real Windows driver DOES use that field, but for CPU architecture
     * ("AMD x64"/"Intel x64" from sysDescr), not machine model. Still
     * out of scope here: this fix is specifically about the "Operating
     * System" line: a real, separate follow-up, not silently done or
     * silently skipped.
     */
    private function updateDeviceOsVersion(Device $device, array $inventory): void
    {
        $operatingSystem = $inventory['operating_system'] ?? null;
        if (! is_array($operatingSystem)) {
            return;
        }

        $caption = $operatingSystem['caption'] ?? null;
        $releaseId = $operatingSystem['release_id'] ?? null;
        $numberOfLogicalProcessors = $operatingSystem['number_of_logical_processors'] ?? null;

        // "Server 2019 Standard" -- Caption with the redundant "Microsoft
        // Windows " prefix stripped (both words, not just "Microsoft ":
        // the devices-list view's own os_text already shows "Microsoft
        // Windows" separately, confirmed by reading
        // App\Http\Controllers\Table\DeviceController.php directly --
        // leaving "Windows" in here would read as "Microsoft Windows
        // Windows Server 2019 Standard").
        $edition = is_string($caption) ? preg_replace('/^Microsoft\s+Windows\s+/i', '', $caption) : null;

        $version = $edition;
        if (is_string($edition) && is_string($releaseId) && $releaseId !== '') {
            $version = "{$edition} ({$releaseId})";
        }

        // Multiprocessor/Uniprocessor is keyed on LOGICAL processor count
        // (Win32_ComputerSystem.NumberOfLogicalProcessors), not physical
        // socket count -- corrected after real-world testing showed a
        // single-socket, multi-core host should read "Multiprocessor"
        // too. Windows' separate uniprocessor/multiprocessor kernel
        // builds (the historical source of this sysDescr wording) were
        // unified starting with Vista; in the modern single-kernel era
        // the label reflects total logical processors the OS schedules
        // across, confirmed against this project's own real SNMP-
        // monitored Windows devices, not assumed from the field name.
        $features = null;
        if (is_int($numberOfLogicalProcessors) && $numberOfLogicalProcessors >= 1) {
            $features = $numberOfLogicalProcessors > 1 ? 'Multiprocessor' : 'Uniprocessor';
        }

        $device->version = $version;
        $device->features = $features;

        // Same trim + leading-control-character strip every discovered
        // attribute gets in the real Os module's handleChanges(), applied
        // here for the same reason -- defensive, even though WMI string
        // data is unlikely to actually contain control characters.
        foreach (['version', 'features'] as $attribute) {
            if (isset($device->$attribute)) {
                $device->$attribute = trim((string) preg_replace('/^[\x00-\x1F\x7F-\xFF]+/', '', $device->$attribute));
            }
            if ($device->isDirty($attribute)) {
                Log::info(DeviceObserver::attributeChangedMessage($attribute, $device->$attribute, $device->getOriginal($attribute)));
            }
        }

        // Same icon-resolution call the real Os module makes. For
        // os='windows' this resolves to windows.svg/png regardless of
        // $features -- Url::findOsImage()'s per-distro lookup only
        // applies when $os == 'linux' (confirmed by reading that
        // function directly) -- so this is a no-op in practice today,
        // kept for exact parity with the real convention rather than
        // because it currently changes anything.
        $device->icon = basename(Url::findOsImage($device->os, $device->features, null, 'images/os/'));

        $device->save();
    }

    /**
     * Sets Device.hardware from the check's processor_identifier --
     * CPU architecture (e.g. "AMD x64"/"Intel x64"), NOT machine model,
     * confirmed by reading the real precedent directly:
     * LibreNMS\OS\Windows::parseHardware(), found while researching the
     * OS-version fix. The original assumption for this field (before
     * that research) was Win32_ComputerSystem.Model/Manufacturer --
     * wrong, caught before it shipped.
     *
     * parseHardware() parses this exact token+family+model+stepping
     * shape out of Windows' SNMP sysDescr string. The real source of
     * that shape is the registry value HKLM:\HARDWARE\DESCRIPTION\
     * System\CentralProcessor\0\Identifier (confirmed by reading it
     * directly against the real target: "Intel64 Family 15 Model 107
     * Stepping 1", the exact format parseHardware()'s regex expects) --
     * not reconstructed from separate WMI fields (Win32_Processor.
     * Manufacturer + OSArchitecture), which would need this module's
     * own heuristic for the AMD64/Intel64/EM64T/x86/ia64 token and
     * risks getting Intel64-vs-EM64T wrong for older CPU generations
     * (immaterial for parseHardware()'s own output since both map to
     * the same "Intel x64" string, but reading the registry value
     * directly avoids needing to reason about that at all). Same
     * registry-read mechanism reboot-pending/the OS-version ReleaseId
     * fix already use -- no new reachability question.
     *
     * parseCpuArchitecture() below is a direct port of
     * parseHardware()'s regex + lookup table -- same source data, same
     * mapping, not an approximation.
     */
    /**
     * Contact/Location, sourced from the check's root-level contact/
     * location fields (a generic HKLM:\SOFTWARE\LibreNMS\ registry key
     * on the target -- see hardware_inventory.py's docstring for why
     * that's a generic vendor/product key, not org-specific).
     *
     * Real precedent, read directly rather than assumed:
     * LibreNMS\Modules\Os::sysContact()/updateLocation() (the generic
     * SNMP-based module, gated off for snmp_disable devices the same
     * way Storage/Mempool/Processor are -- which is why nothing has
     * ever touched these fields for a WinRM device before now).
     * Confirmed that real mechanism is NOT "fill only if the field is
     * currently blank" -- it unconditionally overwrites sysContact/
     * location from the live SNMP value every discover cycle. The
     * "don't clobber a manual edit" protection comes entirely from a
     * separate, already-built override layer this project doesn't need
     * to reinvent: override_sysContact (a DeviceAttrib) and
     * override_sysLocation (a real column), both already exposed by the
     * device-edit UI as a checkbox, working identically for every
     * device type. Matching that shape exactly: write the registry
     * value only when the corresponding override is off, every
     * discover cycle, same as Os does -- not a blank-check.
     *
     * Device::setLocation()'s $user_override param is deliberately left
     * at its default (false) here, same as Os::updateLocation()'s own
     * call -- that's what makes setLocation() itself respect
     * override_sysLocation internally rather than this method needing
     * its own separate override_sysLocation check for the location half
     * (unlike sysContact, which has no such built-in gate and needs the
     * explicit check below).
     */
    private function updateDeviceContactLocation(Device $device, array $inventory): void
    {
        if (! $device->getAttrib('override_sysContact_bool')) {
            $contact = $inventory['contact'] ?? null;
            $device->sysContact = is_string($contact) && $contact !== '' ? $contact : null;
        }

        $location = $inventory['location'] ?? null;
        $device->setLocation(is_string($location) && $location !== '' ? $location : null, true);
        $device->location?->save();

        if ($device->isDirty('sysContact')) {
            Log::info(DeviceObserver::attributeChangedMessage('sysContact', $device->sysContact, $device->getOriginal('sysContact')));
        }

        $device->save();
    }

    private function updateDeviceHardware(Device $device, array $inventory): void
    {
        $processorIdentifier = $inventory['processor_identifier'] ?? null;
        $device->hardware = $this->parseCpuArchitecture(is_string($processorIdentifier) ? $processorIdentifier : null);

        if (isset($device->hardware)) {
            $device->hardware = trim((string) preg_replace('/^[\x00-\x1F\x7F-\xFF]+/', '', $device->hardware));
        }
        if ($device->isDirty('hardware')) {
            Log::info(DeviceObserver::attributeChangedMessage('hardware', $device->hardware, $device->getOriginal('hardware')));
        }

        $device->save();
    }

    /**
     * Direct port of LibreNMS\OS\Windows::parseHardware()'s regex and
     * lookup table -- see updateDeviceHardware()'s docblock for why this
     * is read from the registry rather than reconstructed from WMI
     * fields.
     */
    private function parseCpuArchitecture(?string $identifier): ?string
    {
        if ($identifier === null) {
            return null;
        }

        if (! preg_match('/^(?<generic>\S+) Family \d+ Model \d+ Stepping \d+/', $identifier, $matches)) {
            return null;
        }

        $genericToArchitecture = [
            'AMD64' => 'AMD x64',
            'Intel64' => 'Intel x64',
            'EM64T' => 'Intel x64',
            'x86' => 'Generic x86',
            'ia64' => 'Intel Itanium IA64',
        ];

        return $genericToArchitecture[$matches['generic']] ?? null;
    }

    public function dependencies(): array
    {
        return [];
    }

    public function shouldDiscover(OS $os, ModuleStatus $status, ConnectivityHelper $connectivity): bool
    {
        return $this->isApplicable($os->getDevice(), $status);
    }

    public function shouldPoll(OS $os, ModuleStatus $status, ConnectivityHelper $connectivity): bool
    {
        return $this->isApplicable($os->getDevice(), $status);
    }

    public function discover(OS $os): void
    {
        $device = $os->getDevice();

        // Fixed set of checks, no per-device topology to discover -- just
        // ensure each state index (state-class checks only) and sensor row
        // exists. Framework only calls this when shouldDiscover() already
        // returned true.
        foreach (self::checks() as $check) {
            if ($check['sensor_class'] === 'state') {
                create_state_index($check['sensor_type'], states: $check['states']);
            }

            app('sensor-discovery')->discover(new Sensor([
                'poller_type' => self::POLLER_TYPE,
                'sensor_class' => $check['sensor_class'],
                'sensor_oid' => $check['sensor_oid'],
                'sensor_index' => $check['sensor_type'],
                'sensor_type' => $check['sensor_type'],
                'sensor_descr' => $check['sensor_descr'],
            ]));
        }

        // network-traffic: needs an actual proxy call at discover time,
        // same reason disk-space does below -- topology (which
        // interfaces exist) only comes from the check itself. Populates
        // only the Port table (no Sensor pair -- see the
        // NETWORK_TRAFFIC_CHECK_NAME constant comment for why that was
        // removed).
        $proxyConfig = $this->resolveProxyConfig($device);
        if ($proxyConfig !== null) {
            $proxy = new WinrmProxy($proxyConfig);
            $interfaces = $this->fetchNetworkInterfaces($proxy, $device);

            // null means the check failed (already logged) -- skip
            // staging rather than sync an empty set, which would delete
            // any previously-discovered interfaces.
            if ($interfaces !== null) {
                foreach ($interfaces as $interface) {
                    // Real ports-table row -- see
                    // the NETWORK_TRAFFIC_SYNTHETIC_IFINDEX_BASE comment
                    // above for why. No unique DB constraint on
                    // (device_id, ifIndex) to lean on (dropped upstream),
                    // so updateOrCreate() is this module's own dedup,
                    // matched against the same deterministic synthetic
                    // ifIndex every time. ifType/ifSpeed deliberately
                    // left unset -- this WMI class has no signal for
                    // either, and guessing would be fabricating data
                    // this module has no basis for; out of scope for the
                    // ifOperStatus/ifAdminStatus fix below (2026-08-10),
                    // not overlooked. Neither of those two is required
                    // for the combined bits graph to render (confirmed
                    // by reading includes/html/graphs/device/bits.inc.php)
                    // -- disabled=0/deleted=0 plus an RRD file at the
                    // right path is the whole requirement.
                    //
                    // ifOperStatus/ifAdminStatus (2026-08-10 fix): set
                    // here too, not just in pollNetworkTraffic() below --
                    // gives a real initial value at discover time rather
                    // than leaving a freshly-discovered port's status
                    // unknown until the next poll cycle happens to run.
                    $portStatus = $this->mapPortStatus($interface['net_enabled'], $interface['net_connection_status']);

                    Port::query()->updateOrCreate(
                        ['device_id' => $device->device_id, 'ifIndex' => $this->syntheticIfIndex($interface['name'])],
                        [
                            'ifDescr' => $interface['name'],
                            'ifName' => $interface['name'],
                            'ifAlias' => $interface['name'],
                            'disabled' => 0,
                            'deleted' => 0,
                            'ifAdminStatus' => $portStatus['ifAdminStatus'],
                            'ifOperStatus' => $portStatus['ifOperStatus'],
                        ]
                    );
                }
            }
        }

        // discover() only stages sensors in memory (App\Discovery\Sensor
        // just pushes to a collection) -- sync() is what actually persists
        // them, filtered by this exact (sensor_class, poller_type) pair.
        // Confirmed by reading app/Discovery/Sensor.php and the one other
        // real caller of this pattern, includes/polling/unix-agent.inc.php
        // ("sync(sensor_class: 'temperature', poller_type: 'agent')") --
        // missing this call was a real bug caught by actually running
        // device:discover and finding zero rows in the sensors table.
        // Loops over every distinct sensor_class actually in use rather
        // than a hardcoded 'state' literal, now that winupdate-pending
        // introduced a second class ('count') -- a hardcoded single-class
        // sync() here would silently stage-but-never-persist any future
        // check using a third class, repeating exactly the missing-sync
        // bug already hit once.
        foreach (array_unique(array_column(self::checks(), 'sensor_class')) as $sensorClass) {
            app('sensor-discovery')->sync(sensor_class: $sensorClass, poller_type: self::POLLER_TYPE);
        }

        // disk-space: unlike the checks() loop above, this needs an
        // actual proxy call at discover time (topology -- which disks
        // exist -- can only come from the check itself, there's no
        // separate "enumerate without querying" step the way SNMP
        // discovery vs. polling normally splits). shouldDiscover()
        // already gates on a proxy being configured, but re-checking
        // defensively here matches poll()'s existing style below rather
        // than trusting that gate blindly two calls removed from where
        // it was evaluated.
        if ($proxyConfig === null) {
            return;
        }

        // $proxy was already constructed above (guaranteed set here --
        // we'd have returned already if $proxyConfig were null).
        $storages = $this->fetchDiskStorageModels($proxy, $device);
        if ($storages === null) {
            // Already logged in fetchDiskStorageModels(). Deliberately
            // does NOT call syncModels() here -- a failed check this
            // cycle shouldn't delete previously-discovered disks, same
            // "skip and log, don't destroy" principle as poll()'s
            // per-check failure handling.
            return;
        }

        // ModuleModelObserver::observe() + syncModels() is the exact
        // pattern LibreNMS\Modules\Storage::discover() itself uses for
        // SNMP-based storage -- reused as-is, not reimplemented, this
        // module's disks just come from a WinRM check instead of an OID
        // walk.
        ModuleModelObserver::observe(Storage::class);
        $this->syncModels($device, 'storage', $storages);

        // memory-usage: same "needs an actual proxy call at discover
        // time" reasoning as disk-space just above -- topology here is
        // "how many page files exist", which only the check itself can
        // answer. A failed check skips staging (does NOT sync an empty
        // set), same "skip and log, don't destroy previously-discovered
        // rows" principle as disk-space's own failure handling.
        $mempools = $this->fetchMemoryMempoolModels($proxy, $device);
        if ($mempools === null) {
            return;
        }

        // Same LibreNMS\Modules\Mempools::discover() pattern reused as-is
        // -- see that class's own discover() method.
        ModuleModelObserver::observe(Mempool::class);
        $this->syncModels($device, 'mempools', $mempools);

        // cpu-usage: same "needs an actual proxy call at discover time"
        // reasoning as disk-space/memory-usage -- topology here is "how
        // many logical cores exist". Manual upsert-by-hand, not
        // syncModels() (Processor has no Keyable support -- see the
        // PROCESSOR_TYPE constant comment). Only creates rows for cores
        // not already discovered -- an existing core's value is left for
        // pollCpuUsage() to update, same discover-handles-topology/
        // poll-handles-values split as every other check. Does NOT
        // remove Processor rows for cores that disappeared (e.g. a vCPU
        // count reduction) -- same accepted gap as network-traffic's
        // Port rows, not solved preemptively.
        $cores = $this->fetchCpuCores($proxy, $device);
        if ($cores === null) {
            return;
        }

        $existingCores = Processor::query()
            ->where('device_id', $device->device_id)
            ->where('processor_type', self::PROCESSOR_TYPE)
            ->pluck('processor_index')
            ->all();

        foreach ($cores as $core) {
            if (in_array($core['index'], $existingCores, true)) {
                continue;
            }

            $processor = new Processor();
            $processor->device_id = $device->device_id;
            $processor->processor_type = self::PROCESSOR_TYPE;
            $processor->processor_index = $core['index'];
            // NOT NULL column, no real OID to give it -- same non-real-
            // but-recognizable-string convention this module's Sensor
            // checks already use for sensor_oid (e.g.
            // 'winrm.reboot_pending').
            $processor->processor_oid = 'winrm.processor.' . $core['index'];
            $processor->processor_descr = 'CPU ' . $core['index'];
            $processor->processor_precision = 1;
            // processor_usage is a real typed int property on the
            // Eloquent model (confirmed by phpstan, which flagged the
            // first version of this line for assigning a float) --
            // LibreNMS\Device\Processor::poll()'s own "round to 2
            // decimals" only avoids that check because it writes via a
            // raw dbUpdate() array, not a typed property assignment, not
            // because the column is meant to hold fractional precision.
            $processor->processor_usage = (int) round($core['busy_percent']);
            $processor->save();
        }

        // hardware-inventory: discover-only, deliberately (see the
        // HARDWARE_INVENTORY_CHECK_NAME constant comment) -- no
        // pollHardwareInventory() exists, and poll() never calls
        // anything for this check. Re-runs (and re-syncs) every time
        // discover() runs, same cadence LibreNMS\Modules\EntityPhysical
        // itself uses for real SNMP-sourced inventory.
        $inventory = $this->fetchHardwareInventory($proxy, $device);
        if ($inventory === null) {
            return;
        }

        $this->updateDeviceOsVersion($device, $inventory);
        $this->updateDeviceHardware($device, $inventory);
        $this->updateDeviceContactLocation($device, $inventory);

        $entPhysicalRows = $this->buildEntPhysicalModels($inventory);

        // Same ModuleModelObserver/syncModels pattern as every other
        // model-backed check. Synced against the device's FULL
        // entityPhysical relation, unscoped -- entPhysical has no
        // type/namespace column to scope by at all (unlike Storage/
        // Mempool), but that's safe here: the real
        // LibreNMS\Modules\EntityPhysical never runs against these
        // snmp_disable devices (see the constant comment), so this
        // relation only ever contains rows this module itself created.
        ModuleModelObserver::observe(EntPhysical::class);
        $this->syncModels($device, 'entityPhysical', $entPhysicalRows);

        // ntp-sync-status: Component-based, not Sensor-based -- see the
        // NTP_SYNC_STATUS_CHECK_NAME constant comment for the full
        // "match the SNMP shape" reasoning.
        $ntpStatus = $this->fetchNtpSyncStatus($proxy, $device);
        if ($ntpStatus !== null) {
            $this->discoverNtpComponent($device, $ntpStatus);
        }

        // winupdate-pending: Application-based (app_type='os-updates'),
        // matching the real osupdate precedent -- see the
        // WINUPDATE_PENDING_CHECK_NAME constant comment. Unlike
        // ntp-sync-status, this doesn't need the check to have
        // succeeded first -- the Application row itself carries no
        // check-derived data (pollWinupdatePending() owns that), so
        // it's safe (and matches includes/discovery/applications.inc.php's
        // own behavior) to create it unconditionally.
        $this->discoverWinupdateApplication($device);
    }

    public function poll(OS $os, DataStorageInterface $datastore): void
    {
        // record_sensor_data() (called below) is legacy code written to
        // run from includes/polling/*.inc.php, where LegacyModule::poll()
        // guarantees dbFacile.php is already include_once'd. Modern
        // class-based Modules get no such guarantee. Confirmed as a real,
        // not hypothetical, gap: a genuine state-sensor value transition
        // during testing crashed with "Call to undefined function
        // dbFetchRows()" from inside record_sensor_data()'s state-change
        // Eventlog branch -- the exception was swallowed by the poller
        // framework's own error handling, so the RRD write (which runs
        // earlier in that function) still silently succeeded while the
        // sensor_current DB update after it never ran. Same require this
        // gap needs.
        require_once base_path('includes/dbFacile.php');

        $device = $os->getDevice();
        $proxyConfig = $this->resolveProxyConfig($device);
        if ($proxyConfig === null) {
            return;
        }

        $proxy = new WinrmProxy($proxyConfig);

        foreach (self::checks() as $checkName => $check) {
            $sensors = Sensor::query()
                ->where('device_id', $device->device_id)
                ->where('sensor_type', $check['sensor_type'])
                ->get();

            if ($sensors->isEmpty()) {
                // discover() hasn't run yet for this device/check, or the
                // sensor was removed -- nothing to poll into.
                continue;
            }

            $result = $proxy->check($device->hostname, $checkName);

            if (! $result->ok) {
                Log::warning("winrm-poller: {$device->hostname}: {$checkName} check failed: {$result->error}");

                continue;
            }

            $rawValue = $result->value[$check['value_key']] ?? null;

            if ($check['sensor_class'] === 'state') {
                $sensorValue = ($check['map'])($rawValue);

                if ($sensorValue === null) {
                    Log::warning("winrm-poller: {$device->hostname}: {$checkName} returned unmapped value: " . var_export($rawValue, true));

                    continue;
                }
            } else {
                // sensor_class='count': the raw value IS the sensor
                // reading, no state-index translation. Still validated,
                // not trusted blindly -- a non-numeric value here means
                // the check's output doesn't match what this module
                // expects, worth logging and skipping rather than writing
                // a garbage RRD value. `null` specifically is a real,
                // expected "don't know yet" state for winupdate-pending
                // v2 (e.g. a host Windows hasn't scanned on its own
                // schedule yet) -- still skipped, but not worth a
                // warning-level log every poll cycle the way a genuinely
                // wrong type (string, array) would be.
                if ($rawValue === null) {
                    Log::info("winrm-poller: {$device->hostname}: {$checkName} has no value yet (null)");

                    continue;
                }

                if (! is_int($rawValue) && ! is_float($rawValue)) {
                    Log::warning("winrm-poller: {$device->hostname}: {$checkName} returned non-numeric value: " . var_export($rawValue, true));

                    continue;
                }

                $sensorValue = $rawValue;
            }

            $sensorArrays = $sensors
                ->map(function (Sensor $sensor) use ($sensorValue) {
                    $row = $sensor->toArray();
                    $row['new_value'] = $sensorValue;

                    return $row;
                })
                ->all();

            // record_sensor_data() (includes/polling/functions.inc.php) is
            // the generic RRD+DB write path used for every sensor class --
            // reused as-is here, not reimplemented. It expects the legacy
            // array form of $device, hence getDeviceArray() not getDevice().
            record_sensor_data($os->getDeviceArray(), $sensorArrays);
        }

        $this->pollDiskSpace($proxy, $os, $datastore);
        $this->pollNetworkTraffic($proxy, $os, $datastore);
        $this->pollMemoryUsage($proxy, $os, $datastore);
        $this->pollCpuUsage($proxy, $os, $datastore);
        $this->pollNtpSyncStatus($proxy, $os, $datastore);
        $this->pollWinupdatePending($proxy, $os, $datastore);
    }

    /**
     * Updates values on already-discovered per-core Processor rows. Does
     * NOT add or remove rows -- discover()'s job, same split as
     * pollDiskSpace()/pollMemoryUsage(). A core appearing here without a
     * matching existing row (e.g. a vCPU count increase since the last
     * discover cycle) is logged and skipped, not silently created here.
     */
    private function pollCpuUsage(WinrmProxy $proxy, OS $os, DataStorageInterface $datastore): void
    {
        $device = $os->getDevice();
        $existing = Processor::query()
            ->where('device_id', $device->device_id)
            ->where('processor_type', self::PROCESSOR_TYPE)
            ->get()
            ->keyBy('processor_index');

        if ($existing->isEmpty()) {
            // discover() hasn't run yet for this device -- nothing to
            // poll into.
            return;
        }

        $cores = $this->fetchCpuCores($proxy, $device);
        if ($cores === null) {
            return;
        }

        $rrdDef = RrdDefinition::make()->addDataset('usage', 'GAUGE', -273, 1000);

        foreach ($cores as $core) {
            $processor = $existing->get($core['index']);
            if ($processor === null) {
                Log::warning("winrm-poller: {$device->hostname}: cpu core {$core['index']} not in discovered processors, run discovery to pick it up");

                continue;
            }

            // RRD keeps full precision (2 decimals); the DB column is a
            // real typed int property (see the discover()-time write
            // above for why this needs an explicit cast, unlike
            // LibreNMS\Device\Processor::poll()'s raw dbUpdate() array).
            $usage = round($core['busy_percent'], 2);
            $processor->processor_usage = (int) round($usage);
            $processor->save();

            // Same RRD tags/dataset convention LibreNMS\Device\Processor
            // itself uses (see that class's poll()) -- 'processor', type,
            // index; dataset name 'usage' -- so this interoperates with
            // existing processor-graph rendering code with no changes
            // needed there.
            $datastore->put($os->getDeviceArray(), 'processors', [
                'processor_type' => $processor->processor_type,
                'processor_index' => $processor->processor_index,
                'rrd_name' => ['processor', $processor->processor_type, $processor->processor_index],
                'rrd_def' => $rrdDef,
            ], [
                'usage' => $usage,
            ]);
        }
    }

    /**
     * Updates values on already-discovered `ports` rows and their RRDs
     * (see the NETWORK_TRAFFIC_SYNTHETIC_IFINDEX_BASE comment for the
     * synthetic ifIndex scheme). No Sensor involved -- see the
     * NETWORK_TRAFFIC_CHECK_NAME constant comment for why. Same
     * discover-handles-topology/poll-handles-values split as
     * pollDiskSpace() -- an interface appearing here without a matching
     * discovered ports row (added since the last discover cycle) is
     * logged and skipped, not silently created in poll().
     */
    private function pollNetworkTraffic(WinrmProxy $proxy, OS $os, DataStorageInterface $datastore): void
    {
        $device = $os->getDevice();

        $existingPorts = $this->winrmPortsQuery($device)->get()->keyBy('ifIndex');
        if ($existingPorts->isEmpty()) {
            // discover() hasn't run yet for this device, or found no
            // interfaces -- nothing to poll into.
            return;
        }

        $interfaces = $this->fetchNetworkInterfaces($proxy, $device);
        if ($interfaces === null) {
            return;
        }

        foreach ($interfaces as $interface) {
            $port = $existingPorts->get($this->syntheticIfIndex($interface['name']));

            if ($port === null) {
                Log::warning("winrm-poller: {$device->hostname}: interface {$interface['name']} not in discovered ports, run discovery to pick it up");

                continue;
            }

            // ifOperStatus/ifAdminStatus refreshed on every poll, not
            // just at discover time (2026-08-10 fix) -- this is genuinely
            // dynamic data (a cable can be unplugged between discovery
            // cycles), same reasoning real SNMP port polling re-reads
            // these every cycle rather than only at discovery.
            $portStatus = $this->mapPortStatus($interface['net_enabled'], $interface['net_connection_status']);
            $port->ifAdminStatus = $portStatus['ifAdminStatus'];
            $port->ifOperStatus = $portStatus['ifOperStatus'];
            $port->save();

            // Same RRD file/dataset-name convention the real SNMP port
            // poller uses (includes/polling/ports.inc.php) -- port_id-
            // keyed filename via Rrd::portName(), INOCTETS/OUTOCTETS
            // dataset names -- so the stock device_bits/port_bits graph
            // code (which resolves both purely by port_id, confirmed by
            // reading includes/html/graphs/device/bits.inc.php and
            // port/auth.inc.php) finds real data with no changes needed
            // on the rendering side. Only INOCTETS/OUTOCTETS -- this
            // check doesn't have errors/discards/packet-count data the
            // real SNMP poller's fuller RrdDefinition includes.
            $datastore->put($os->getDeviceArray(), 'ports', [
                'rrd_name' => Rrd::portName($port->port_id),
                'rrd_def' => RrdDefinition::make()
                    ->addDataset('INOCTETS', 'DERIVE', 0, 12500000000)
                    ->addDataset('OUTOCTETS', 'DERIVE', 0, 12500000000),
            ], [
                'INOCTETS' => $interface['bytes_received'],
                'OUTOCTETS' => $interface['bytes_sent'],
            ]);
        }
    }

    /**
     * Updates values on already-discovered disk Storage rows. Does NOT
     * add or remove rows -- that's discover()'s job, same
     * discover-handles-topology/poll-handles-values split
     * LibreNMS\Modules\Storage itself uses for SNMP-based storage. A
     * disk that appears in this poll's result but has no matching
     * existing row (added since the last discover cycle) is logged and
     * skipped, not silently created here.
     */
    private function pollDiskSpace(WinrmProxy $proxy, OS $os, DataStorageInterface $datastore): void
    {
        $device = $os->getDevice();
        $existing = $device->storage()->where('type', self::STORAGE_TYPE)->get()->keyBy->getCompositeKey();
        if ($existing->isEmpty()) {
            // discover() hasn't run yet for this device, or found no
            // disks -- nothing to poll into.
            return;
        }

        $fresh = $this->fetchDiskStorageModels($proxy, $device);
        if ($fresh === null) {
            return;
        }

        foreach ($fresh as $freshStorage) {
            $key = $freshStorage->getCompositeKey();
            $storage = $existing->get($key);

            if ($storage === null) {
                Log::warning("winrm-poller: {$device->hostname}: disk {$freshStorage->storage_descr} not in discovered storage, run discovery to pick it up");

                continue;
            }

            $storage->fillUsage(null, $freshStorage->storage_size, $freshStorage->storage_free, null);
            $storage->save();

            $datastore->put($os->getDeviceArray(), 'storage', [
                'type' => $storage->type,
                'descr' => $storage->storage_descr,
                'rrd_name' => ['storage', $storage->type, $storage->storage_descr],
                'rrd_def' => RrdDefinition::make()
                    ->addDataset('used', 'GAUGE', 0)
                    ->addDataset('free', 'GAUGE', 0),
            ], [
                'used' => $storage->storage_used,
                'free' => $storage->storage_free,
            ]);
        }
    }

    /**
     * Sets Device.uptime from the memory-usage check's last_boot_up_time,
     * refreshed every poll (unlike hardware-inventory's discover-only
     * fields) -- confirmed by reading the real precedent directly,
     * LibreNMS\Modules\Core::calculateUptime(), rather than following
     * the original handoff's suggestion to place this alongside
     * hardware-inventory's static-identity fields. Core's own
     * shouldDiscover()/shouldPoll() require
     * ConnectivityHelper::snmpIsAvailable() (never runs for these
     * snmp_disable devices, same as every other built-in module this
     * project bypasses), and critically its uptime handling lives
     * entirely in poll(), not discover() -- uptime is inherently
     * dynamic, unlike the identity data every other fetch*()/update*()
     * method in this module handles.
     *
     * Replicates Core::calculateUptime()'s real behavior directly, not a
     * simplified version of it: writes the same dedicated 'uptime' RRD
     * (GAUGE, matching Core's own RrdDefinition exactly, so any existing
     * uptime-graph rendering code finds real data with no changes
     * needed), calls the same $os->enableGraph('uptime'), and logs the
     * same device-rebooted Eventlog entry when uptime decreases
     * (comparing against the device's previous uptime value, same
     * ordering as Core's own check).
     *
     * last_boot_up_time is converted to UTC inside the JEA function
     * itself (Get-MemoryUsageStatus) before this ever sees it --
     * [System.Management.ManagementDateTimeConverter]::ToDateTime()
     * returns a DateTime with Kind=Unspecified (confirmed by real
     * testing, not assumed), so a naive .ToString('o') on it omits any
     * UTC offset and would be silently misinterpreted as UTC by PHP's
     * date parsing even though it's really the target's local time --
     * a real, caught-before-shipping bug, not a hypothetical one.
     * .ToUniversalTime().ToString('o') first is what actually produces
     * an unambiguous, correctly-offset string.
     */
    private function updateDeviceUptime(Device $device, OS $os, DataStorageInterface $datastore, mixed $lastBootUpTime): void
    {
        if (! is_string($lastBootUpTime) || $lastBootUpTime === '') {
            return;
        }

        try {
            $bootTime = new \DateTimeImmutable($lastBootUpTime);
        } catch (\Exception $e) {
            Log::warning("winrm-poller: {$device->hostname}: unparseable last_boot_up_time, skipping uptime: " . var_export($lastBootUpTime, true));

            return;
        }

        $uptime = max(0, time() - $bootTime->getTimestamp());
        if ($uptime <= 0) {
            return;
        }

        if ($uptime < $device->uptime) {
            Eventlog::log('Device rebooted after ' . Time::formatInterval($device->uptime) . " -> {$uptime}s", $device, 'reboot', Severity::Warning, $device->uptime);
        }

        $datastore->put($os->getDeviceArray(), 'uptime', [
            'rrd_def' => RrdDefinition::make()->addDataset('uptime', 'GAUGE', 0),
        ], $uptime);

        $os->enableGraph('uptime');

        $device->uptime = $uptime;
        $device->save();
    }

    /**
     * Updates values on already-discovered physical/virtual/page-file
     * Mempool rows. Does NOT add or remove rows -- discover()'s job,
     * same discover-handles-topology/poll-handles-values split as
     * pollDiskSpace() and LibreNMS\Modules\Mempools itself. An entry
     * appearing here without a matching existing row (e.g. a page file
     * added since the last discover cycle) is logged and skipped, not
     * silently created in poll().
     */
    private function pollMemoryUsage(WinrmProxy $proxy, OS $os, DataStorageInterface $datastore): void
    {
        $device = $os->getDevice();
        $existing = $device->mempools()->where('mempool_type', self::MEMPOOL_TYPE)->get()->keyBy->getCompositeKey();
        if ($existing->isEmpty()) {
            // discover() hasn't run yet for this device -- nothing to
            // poll into.
            return;
        }

        $raw = $this->fetchMemoryUsageRaw($proxy, $device);
        if ($raw === null) {
            return;
        }

        $this->updateDeviceUptime($device, $os, $datastore, $raw['last_boot_up_time'] ?? null);

        $fresh = $this->buildMemoryMempoolModels($raw);

        foreach ($fresh as $freshMempool) {
            $key = $freshMempool->getCompositeKey();
            $mempool = $existing->get($key);

            if ($mempool === null) {
                Log::warning("winrm-poller: {$device->hostname}: mempool {$freshMempool->mempool_descr} not in discovered mempools, run discovery to pick it up");

                continue;
            }

            // Same (used, total, free, percent) pairing per mempool_class
            // fetchMemoryMempoolModels() used to build $freshMempool --
            // physical/virtual give total+free, page files give used+total
            // (see that method's own comment for why).
            if ($mempool->mempool_class === 'swap') {
                $mempool->fillUsage($freshMempool->mempool_used, $freshMempool->mempool_total, null, null);
            } else {
                $mempool->fillUsage(null, $freshMempool->mempool_total, $freshMempool->mempool_free, null);
            }
            $mempool->save();

            // Same RRD tags/dataset convention LibreNMS\Modules\Mempools
            // itself uses (see that class's poll()) -- 'mempool',
            // type/class/index -- so this interoperates with any existing
            // mempool-graph rendering code with no changes needed there.
            $datastore->put($os->getDeviceArray(), 'mempool', [
                'mempool_type' => $mempool->mempool_type,
                'mempool_class' => $mempool->mempool_class,
                'mempool_index' => $mempool->mempool_index,
                'rrd_name' => ['mempool', $mempool->mempool_type, $mempool->mempool_class, $mempool->mempool_index],
                'rrd_def' => RrdDefinition::make()
                    ->addDataset('used', 'GAUGE', 0)
                    ->addDataset('free', 'GAUGE', 0),
            ], [
                'used' => $mempool->mempool_used,
                'free' => $mempool->mempool_free,
            ]);
        }
    }

    /**
     * `ports` has no source/type column to scope by (unlike Storage's
     * `type` or Sensor's `poller_type`) -- the synthetic-ifIndex range
     * (see NETWORK_TRAFFIC_SYNTHETIC_IFINDEX_BASE) is what identifies a
     * WinRM-created row instead, for cleanup()/dataExists()/dump(). Real
     * SNMP ifIndex values are never remotely this large in practice.
     *
     * @return Builder<Port>
     */
    private function winrmPortsQuery(Device $device): Builder
    {
        return Port::query()
            ->where('device_id', $device->device_id)
            ->where('ifIndex', '>=', self::NETWORK_TRAFFIC_SYNTHETIC_IFINDEX_BASE);
    }

    public function dataExists(Device $device): bool
    {
        return Sensor::query()
            ->where('device_id', $device->device_id)
            ->whereIn('sensor_type', self::sensorTypes())
            ->exists()
            || $device->storage()->where('type', self::STORAGE_TYPE)->exists()
            || $this->winrmPortsQuery($device)->exists()
            || $device->mempools()->where('mempool_type', self::MEMPOOL_TYPE)->exists()
            || Processor::query()->where('device_id', $device->device_id)->where('processor_type', self::PROCESSOR_TYPE)->exists()
            || $device->entityPhysical()->exists()
            || $device->components()->where('type', self::NTP_TYPE)->exists()
            || Application::where('device_id', $device->device_id)->where('app_type', self::WINUPDATE_PENDING_APP_TYPE)->exists();
    }

    public function cleanup(Device $device): int
    {
        $sensorsDeleted = Sensor::query()
            ->where('device_id', $device->device_id)
            ->whereIn('sensor_type', self::sensorTypes())
            ->delete();

        $storageDeleted = $device->storage()->where('type', self::STORAGE_TYPE)->delete();

        $portsDeleted = $this->winrmPortsQuery($device)->delete();

        $mempoolsDeleted = $device->mempools()->where('mempool_type', self::MEMPOOL_TYPE)->delete();

        $processorsDeleted = Processor::query()->where('device_id', $device->device_id)->where('processor_type', self::PROCESSOR_TYPE)->delete();

        $entPhysicalDeleted = $device->entityPhysical()->delete();

        // component_prefs has a real DB-level ON DELETE CASCADE foreign
        // key back to component.id (confirmed by reading
        // 2018_07_03_091322_add_foreign_keys_to_component_prefs_table.php
        // directly, not assumed) -- deleting the component row here is
        // enough, no manual ComponentPref cleanup needed.
        $ntpComponentsDeleted = $device->components()->where('type', self::NTP_TYPE)->delete();
        Application::where('device_id', $device->device_id)->where('app_type', self::NTP_TYPE)->delete();

        // Unlike component_prefs, application_metrics has no DB-level FK/
        // cascade back to applications (confirmed by reading
        // 2018_07_03_091314_create_application_metrics_table.php directly --
        // app_id is a bare unsignedInteger, no foreign()). The real
        // includes/discovery/applications.inc.php has its own sweep for
        // this (ApplicationMetric::doesntHave('app')->delete()), but that
        // only runs as part of the generic SNMP-extend Applications
        // module, which LegacyModule::shouldPoll() gates on
        // snmpIsAvailable() -- it never runs against an snmp_disable
        // WinRM device, so nothing else will ever clear these rows.
        // Deleted explicitly here, before the (soft) Application delete,
        // while $winupdateApp still resolves the right app_id.
        $winupdateApp = Application::where('device_id', $device->device_id)
            ->where('app_type', self::WINUPDATE_PENDING_APP_TYPE)
            ->first();
        if ($winupdateApp !== null) {
            ApplicationMetric::where('app_id', $winupdateApp->app_id)->delete();
        }
        $winupdateAppsDeleted = Application::where('device_id', $device->device_id)->where('app_type', self::WINUPDATE_PENDING_APP_TYPE)->delete();

        return $sensorsDeleted + $storageDeleted + $portsDeleted + $mempoolsDeleted + $processorsDeleted + $entPhysicalDeleted + $ntpComponentsDeleted + $winupdateAppsDeleted;
    }

    public function dump(Device $device, string $type): ?array
    {
        if ($type === 'discovery') {
            return null;
        }

        return [
            'sensors' => Sensor::query()
                ->where('device_id', $device->device_id)
                ->whereIn('sensor_type', self::sensorTypes())
                ->orderBy('sensor_id')
                ->get()
                ->map->makeHidden(['sensor_id', 'device_id', 'poller_id']),
            'storage' => $device->storage()
                ->where('type', self::STORAGE_TYPE)
                ->orderBy('storage_index')
                ->get()
                ->map->makeHidden(['storage_id', 'device_id']),
            'ports' => $this->winrmPortsQuery($device)
                ->orderBy('ifIndex')
                ->get()
                ->map->makeHidden(['port_id', 'device_id']),
            'mempools' => $device->mempools()
                ->where('mempool_type', self::MEMPOOL_TYPE)
                ->orderBy('mempool_index')
                ->get()
                ->map->makeHidden(['mempool_id', 'device_id']),
            'processors' => Processor::query()
                ->where('device_id', $device->device_id)
                ->where('processor_type', self::PROCESSOR_TYPE)
                ->orderBy('processor_index')
                ->get()
                ->map->makeHidden(['processor_id', 'device_id']),
            'entPhysical' => $device->entityPhysical()
                ->orderBy('entPhysicalIndex')
                ->get()
                ->map->makeHidden(['entPhysical_id', 'device_id']),
            'ntpComponents' => $device->components()
                ->where('type', self::NTP_TYPE)
                ->with('prefs')
                ->orderBy('id')
                ->get()
                ->map->makeHidden(['id', 'device_id']),
            'winupdatePendingApp' => Application::where('device_id', $device->device_id)
                ->where('app_type', self::WINUPDATE_PENDING_APP_TYPE)
                ->with('metrics')
                ->orderBy('app_id')
                ->get()
                ->map->makeHidden(['app_id', 'device_id']),
        ];
    }

    private function isApplicable(Device $device, ModuleStatus $status): bool
    {
        return $status->isEnabled()
            && $device->os === 'windows'
            && $this->resolveProxyConfig($device) !== null;
    }

    /**
     * Resolve which configured proxy entry applies to this device, by
     * matching its group membership against $config['winrm']['proxies']
     * keys, falling back to a 'default' entry if present.
     *
     * @return array<string, mixed>|null
     */
    private function resolveProxyConfig(Device $device): ?array
    {
        $proxies = LibrenmsConfig::get('winrm.proxies', []);
        if (empty($proxies)) {
            return null;
        }

        foreach ($device->groups as $group) {
            if (isset($proxies[$group->name])) {
                return $proxies[$group->name];
            }
        }

        return $proxies['default'] ?? null;
    }
}
