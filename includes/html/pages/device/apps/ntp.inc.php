<?php
/*
 * LibreNMS module to capture statistics from the CISCO-NTP-MIB
 *
 * Copyright (c) 2016 Aaron Daniels <aaron@daniels.id.au>
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the
 * Free Software Foundation, either version 3 of the License, or (at your
 * option) any later version.  Please see LICENSE.txt at the top level of
 * the source code distribution for details.
 */

$component = new LibreNMS\Component();
$options = [];
$options['filter']['ignore'] = ['=', 0];
$components = $component->getComponents($device['device_id'], $options);
$components = $components[$device['device_id']];
// 'ntp' is the real CISCO-NTP-MIB type this page/module was built for.
// 'ntp-client-winrm' is a second, unrelated real mechanism (WinRM/
// w32tm, alexh/librenms-fork) reporting the same real shape (peer/
// stratum/peerref/status + stratum/offset/delay/dispersion RRD
// datasets) -- widened to include it here rather than duplicating
// this page, since Component::getComponents()'s own filter mechanism
// forwards its operator directly to a single-value Eloquent
// where($field, $op, $value) call with no array/IN support, so the
// widening is a plain post-fetch filter, not a query-level IN clause.
$components = array_filter($components, fn ($c) => in_array($c['type'] ?? null, ['ntp', 'ntp-client-winrm'], true));
// Frequency/Phase Offset panels below are WinRM-only real data
// (docs/WINRM_CLOCK_DISCIPLINE_RESEARCH.md) -- CISCO-NTP-MIB has no
// equivalent, so those panels only render when there's an actual
// 'ntp-client-winrm' component to show, not for a native-MIB-only
// device.
$hasWinrmComponent = ! empty(array_filter($components, fn ($c) => ($c['type'] ?? null) === 'ntp-client-winrm'));

?>
<table id='table' class='table table-condensed table-responsive table-striped'>
    <thead>
    <tr>
        <th>Peer</th>
        <th>Stratum</th>
        <th>Peer Reference</th>
        <th>Reachability</th>
        <th>Status</th>
    </tr>
    </thead>
<?php
foreach ($components as $peer) {
    $string = $peer['peer'] . ':' . $peer['port'];
    if ($peer['status'] == 2) {
        $status = $peer['error'];
        $error = 'class="danger"';
    } else {
        $status = 'Ok';
        $error = '';
    }
    // Only the WinRM check populates this (the classic NTP 8-bit
    // "reach" register, see WinrmPoller.php's reachability comment) --
    // the native-MIB-based module's own 'ntp' components never set
    // this ComponentPref, so $peer['reachability'] is simply absent
    // for them; blank cell, not a fabricated value.
    $reachability = $peer['reachability'] ?? '';
    ?>
<tr <?php echo $error; ?>>
<td><?php echo $string; ?></td>
<td><?php echo $peer['stratum']; ?></td>
<td><?php echo $peer['peerref']; ?></td>
<td><?php echo $reachability; ?></td>
<td><?php echo $status; ?></td>
</tr>
    <?php
}
?>
</table>

<div class="panel panel-default" id="stratum">
    <div class="panel-heading">
        <h3 class="panel-title">NTP Stratum</h3>
    </div>
    <div class="panel-body">
        <?php

        $graph_array = [];
        $graph_array['device'] = $device['device_id'];
        $graph_array['height'] = '100';
        $graph_array['width'] = '215';
        $graph_array['to'] = \App\Facades\LibrenmsConfig::get('time.now');
        $graph_array['type'] = 'device_ntp_stratum';
        require 'includes/html/print-graphrow.inc.php';

        ?>
    </div>
</div>

<div class="panel panel-default" id="offset">
    <div class="panel-heading">
        <h3 class="panel-title">Offset</h3>
    </div>
    <div class="panel-body">
        <?php

        $graph_array = [];
        $graph_array['device'] = $device['device_id'];
        $graph_array['height'] = '100';
        $graph_array['width'] = '215';
        $graph_array['to'] = \App\Facades\LibrenmsConfig::get('time.now');
        $graph_array['type'] = 'device_ntp_offset';
        require 'includes/html/print-graphrow.inc.php';

        ?>
    </div>
</div>

<div class="panel panel-default" id="delay">
    <div class="panel-heading">
        <h3 class="panel-title">Delay</h3>
    </div>
    <div class="panel-body">
        <?php

        $graph_array = [];
        $graph_array['device'] = $device['device_id'];
        $graph_array['height'] = '100';
        $graph_array['width'] = '215';
        $graph_array['to'] = \App\Facades\LibrenmsConfig::get('time.now');
        $graph_array['type'] = 'device_ntp_delay';
        require 'includes/html/print-graphrow.inc.php';

        ?>
    </div>
</div>

<div class="panel panel-default" id="dispersion">
    <div class="panel-heading">
        <h3 class="panel-title">Dispersion</h3>
    </div>
    <div class="panel-body">
        <?php

        $graph_array = [];
        $graph_array['device'] = $device['device_id'];
        $graph_array['height'] = '100';
        $graph_array['width'] = '215';
        $graph_array['to'] = \App\Facades\LibrenmsConfig::get('time.now');
        $graph_array['type'] = 'device_ntp_dispersion';
        require 'includes/html/print-graphrow.inc.php';

        ?>
    </div>
</div>

<?php if ($hasWinrmComponent) { ?>
<div class="panel panel-default" id="frequency">
    <div class="panel-heading">
        <h3 class="panel-title">Frequency</h3>
    </div>
    <div class="panel-body">
        <?php

        $graph_array = [];
        $graph_array['device'] = $device['device_id'];
        $graph_array['height'] = '100';
        $graph_array['width'] = '215';
        $graph_array['to'] = \App\Facades\LibrenmsConfig::get('time.now');
        $graph_array['type'] = 'device_ntp_frequency';
        require 'includes/html/print-graphrow.inc.php';

        ?>
    </div>
</div>

<div class="panel panel-default" id="phase_offset">
    <div class="panel-heading">
        <h3 class="panel-title">Phase Offset</h3>
    </div>
    <div class="panel-body">
        <?php

        $graph_array = [];
        $graph_array['device'] = $device['device_id'];
        $graph_array['height'] = '100';
        $graph_array['width'] = '215';
        $graph_array['to'] = \App\Facades\LibrenmsConfig::get('time.now');
        $graph_array['type'] = 'device_ntp_phase_offset';
        require 'includes/html/print-graphrow.inc.php';

        ?>
    </div>
</div>
<?php } ?>
