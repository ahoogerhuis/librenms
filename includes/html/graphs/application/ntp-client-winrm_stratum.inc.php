<?php
/*
 * Representative overview graph for the global app-overview grid
 * (includes/html/pages/apps/default.inc.php, driven by
 * $graphs['ntp-client-winrm'] in includes/html/pages/apps.inc.php) --
 * one graph per app, matching the same real convention os-updates
 * uses for its own single 'packages' entry. `stratum` chosen as the
 * one representative value (not offset/delay/dispersion) -- the
 * single number that answers "is this device's NTP sync currently
 * healthy" at a glance, same role stratum already plays in the
 * Component's own status derivation (WinrmPoller::ntpStratumIsBad()).
 *
 * Deliberately NOT the standard `['app', $name, $app->app_id]` RRD
 * convention the rest of this directory uses (see os-updates_packages
 * .inc.php) -- this check's data lives in a Component-scoped RRD file
 * (`WinrmPoller::NTP_TYPE`, `[NTP_TYPE, $peer]`), written by
 * pollNtpSyncStatus(), not application_metrics/an app-prefixed RRD
 * file. $app->device_id (bound by includes/html/graphs/application/
 * auth.inc.php before this file runs) is used to look up that
 * device's own ntp-client-winrm Component and find its real RRD
 * filename, mirroring includes/html/graphs/device/ntp_stratum.inc.php's
 * own lookup, just scoped to one device instead of looping all of
 * them.
 */

$scale_min = 0;
$scale_max = 16;

require 'includes/html/graphs/common.inc.php';

$component = new LibreNMS\Component();
$options = [];
$options['filter']['ignore'] = ['=', 0];
$components = $component->getComponents($app->device_id, $options);
$components = $components[$app->device_id] ?? [];
$components = array_filter($components, fn ($c) => ($c['type'] ?? null) === 'ntp-client-winrm');
$peer = (reset($components) ?: [])['peer'] ?? null;

$colours = 'mixed';
$unit_text = 'Stratum';
$unitlen = 18;
$bigdescrlen = 18;
$smalldescrlen = 18;
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 33;

$rrd_filename = $peer !== null ? Rrd::name($device['hostname'], ['ntp-client-winrm', $peer]) : '';

$array = [
    'stratum' => ['descr' => 'stratum', 'colour' => '2B9220'],
];

$i = 0;
foreach ($array as $ds => $var) {
    $rrd_list[$i]['filename'] = $rrd_filename;
    $rrd_list[$i]['descr'] = $var['descr'];
    $rrd_list[$i]['ds'] = $ds;
    $rrd_list[$i]['colour'] = $var['colour'];
    $i++;
}

require 'includes/html/graphs/generic_v3_multiline.inc.php';
