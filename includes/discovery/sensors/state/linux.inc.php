<?php

use LibreNMS\Util\Oid;

/*
 * reboot-required state sensor (prototype)
 * requires snmp extend agent script from librenms-agent
 */
$snmpData = SnmpQuery::cache()->hideMib()->walk('NET-SNMP-EXTEND-MIB::nsExtendOutLine."reboot-required"')->table(3);
if (! empty($snmpData)) {
    $snmpData = array_shift($snmpData); // drop [reboot-required]

    if (! empty($snmpData[1])) {
        $oid = Oid::of('NET-SNMP-EXTEND-MIB::nsExtendOutLine."reboot-required".1')->toNumeric();
        $value = current($snmpData[1]);
        $state_name = 'linuxRebootRequired';
        $descr = 'Reboot Required';
        $states = [
            ['value' => 0, 'generic' => 0, 'descr' => 'No reboot required'],
            ['value' => 1, 'generic' => 2, 'descr' => 'Reboot required'],
            ['value' => 2, 'generic' => 3, 'descr' => 'Check failed'],
        ];

        create_state_index($state_name, $states);
        discover_sensor(null, 'state', $device, $oid, $state_name, $state_name, $descr, '1', '1', null, null, null, null, $value, 'snmp', null, null, null, 'reboot-required');
    }
}

/*
 * codec states for raspberry pi
 * requires snmp extend agent script from librenms-agent
 */
if (! empty($pre_cache['raspberry_pi_sensors'])) {
    $state_name = 'raspberry_codec';
    $oid = '.1.3.6.1.4.1.8072.1.3.2.4.1.2.9.114.97.115.112.98.101.114.114.121.';
    for ($codec = 8; $codec < 14; $codec++) {
        switch ($codec) {
            case '8':
                $descr = 'H264 codec';
                break;
            case '9':
                $descr = 'MPG2 codec';
                break;
            case '10':
                $descr = 'WVC1 codec';
                break;
            case '11':
                $descr = 'MPG4 codec';
                break;
            case '12':
                $descr = 'MJPG codec';
                break;
            case '13':
                $descr = 'WMV9 codec';
                break;
        }
        $value = (string) current($pre_cache['raspberry_pi_sensors']['raspberry.' . $codec] ?? []);
        if (stripos($value, 'abled') !== false) {
            $states = [
                ['value' => 2, 'generic' => 0, 'descr' => 'enabled'],
                ['value' => 3, 'generic' => 3, 'descr' => 'disabled'],
            ];
            create_state_index($state_name, $states);

            discover_sensor(null, 'state', $device, $oid . $codec, $codec, $state_name, $descr, 1, 1, null, null, null, null, $value, 'snmp', $codec);
        } else {
            break;
        }
    }
}
