<?php

/*
 * Clock-discipline graph for the WinRM ntp-sync-status check's own
 * Component type (NTP_TYPE = 'ntp-client-winrm' in
 * LibreNMS/Modules/WinrmPoller.php). Deliberately NOT widened to
 * include the native-MIB-based module's 'ntp' components the way the
 * four original ntp_{stratum,offset,delay,dispersion}.inc.php graphs
 * are -- those RRD files genuinely don't have a `frequency_ppb`
 * dataset (CISCO-NTP-MIB has no equivalent concept), so a widened
 * DEF against one would fail. See
 * docs/WINRM_CLOCK_DISCIPLINE_RESEARCH.md for the real research this
 * field is based on.
 */

$component = new LibreNMS\Component();
$components = $component->getComponents($device['device_id']);

// We only care about our device id.
$components = $components[$device['device_id']];
$components = array_filter($components, fn ($c) => ($c['type'] ?? null) === 'ntp-client-winrm');

include 'includes/html/graphs/common.inc.php';
$graph_params->vertical_label = 'PPB';

$rrd_options[] = 'COMMENT:Frequency (PPB)       Now      Min      Max\\n';
$rrd_additions = '';

$count = 0;
foreach ($components as $array) {
    $rrd_filename = Rrd::name($device['hostname'], [$array['type'], $array['peer']]);

    if (Rrd::checkRrdExists($rrd_filename)) {
        $color = \App\Facades\LibrenmsConfig::get("graph_colours.mixed.$count", \App\Facades\LibrenmsConfig::get('graph_colours.oranges.' . ($count - 7)));

        $rrd_options[] = 'DEF:DS' . $count . '=' . $rrd_filename . ':frequency_ppb:AVERAGE';
        $rrd_options[] = 'LINE1.25:DS' . $count . '#' . $color . ':' . str_pad(substr((string) $array['peer'], 0, 15), 15) . $stack;
        $rrd_options[] = 'GPRINT:DS' . $count . ':LAST:%7.2lf';
        $rrd_options[] = 'GPRINT:DS' . $count . ':MIN:%7.2lf';
        $rrd_options[] = 'GPRINT:DS' . $count . ':MAX:%7.2lf\\l';
        $count++;
    }
}
