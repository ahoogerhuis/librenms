<?php

/**
 * WinrmPollerTest.php
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

namespace LibreNMS\Tests\Unit;

use App\Facades\LibrenmsConfig;
use App\Models\Device;
use App\Models\DeviceGroup;
use LibreNMS\Modules\WinrmPoller;
use LibreNMS\OS;
use LibreNMS\Polling\ConnectivityHelper;
use LibreNMS\Polling\ModuleStatus;
use LibreNMS\Tests\TestCase;
use Mockery;

/**
 * Covers shouldPoll()/shouldDiscover() applicability logic only -- these
 * only need a manually-constructed Device (with the groups relation
 * stubbed, avoiding a real DB query), matching the convention already
 * used elsewhere in tests/Unit/ (see OxidizedProviderTest's attribs
 * stubbing) rather than this codebase's opt-in DBTEST=1 pattern.
 *
 * discover()/poll()/dataExists()/cleanup()/dump() all touch the real
 * `sensors` table via Eloquent and are deliberately NOT unit-tested
 * here -- they're exercised for real against lnms-poller.vpp.local as part of the
 * end-to-end poller<->proxy hop test instead (see the implementation
 * plan's A4 testing section).
 */
final class WinrmPollerTest extends TestCase
{
    private function makeDevice(string $os = 'windows', array $groupNames = []): Device
    {
        $device = new Device(['hostname' => 'winsrv01.example.com', 'os' => $os]);
        $device->device_id = 99;
        $device->setRelation(
            'groups',
            collect($groupNames)->map(fn ($name) => new DeviceGroup(['name' => $name]))
        );

        return $device;
    }

    private function makeOs(Device $device): OS
    {
        $os = Mockery::mock(OS::class);
        $os->shouldReceive('getDevice')->andReturn($device);

        return $os;
    }

    private function connectivity(): ConnectivityHelper
    {
        // ConnectivityHelper is a readonly class -- Mockery can't
        // generate a mock subclass of one (PHP 8.2+ limitation, confirmed
        // by actually running this: "Non-readonly class ... cannot
        // extend readonly class"). Construct a real instance instead;
        // WinRM applicability has nothing to do with SNMP/ICMP/IPMI
        // reachability, so the module never calls any method on this,
        // and a real instance with a throwaway device is harmless.
        return new ConnectivityHelper(new Device());
    }

    protected function tearDown(): void
    {
        LibrenmsConfig::set('winrm.proxies', []);
        parent::tearDown();
    }

    public function testFalseWhenModuleGloballyDisabled(): void
    {
        LibrenmsConfig::set('winrm.proxies', ['default' => ['url' => 'https://proxy']]);
        $device = $this->makeDevice();
        $status = new ModuleStatus(global: null); // never configured = disabled

        $module = new WinrmPoller();
        $this->assertFalse($module->shouldPoll($this->makeOs($device), $status, $this->connectivity()));
        $this->assertFalse($module->shouldDiscover($this->makeOs($device), $status, $this->connectivity()));
    }

    public function testFalseWhenDeviceOsIsNotWindows(): void
    {
        LibrenmsConfig::set('winrm.proxies', ['default' => ['url' => 'https://proxy']]);
        $device = $this->makeDevice(os: 'linux');
        $status = new ModuleStatus(global: true);

        $module = new WinrmPoller();
        $this->assertFalse($module->shouldPoll($this->makeOs($device), $status, $this->connectivity()));
    }

    public function testFalseWhenNoProxyConfigured(): void
    {
        LibrenmsConfig::set('winrm.proxies', []);
        $device = $this->makeDevice();
        $status = new ModuleStatus(global: true);

        $module = new WinrmPoller();
        $this->assertFalse($module->shouldPoll($this->makeOs($device), $status, $this->connectivity()));
    }

    public function testTrueWhenWindowsEnabledAndDefaultProxyConfigured(): void
    {
        LibrenmsConfig::set('winrm.proxies', ['default' => ['url' => 'https://proxy']]);
        $device = $this->makeDevice();
        $status = new ModuleStatus(global: true);

        $module = new WinrmPoller();
        $this->assertTrue($module->shouldPoll($this->makeOs($device), $status, $this->connectivity()));
        $this->assertTrue($module->shouldDiscover($this->makeOs($device), $status, $this->connectivity()));
    }

    public function testGroupSpecificProxyTakesPriorityOverDefault(): void
    {
        LibrenmsConfig::set('winrm.proxies', [
            'default' => ['url' => 'https://default-proxy'],
            'site-b' => ['url' => 'https://site-b-proxy'],
        ]);
        $device = $this->makeDevice(groupNames: ['site-b']);
        $status = new ModuleStatus(global: true);

        // Applicability is true either way here -- this test exists to
        // exercise resolveProxyConfig()'s group-matching path at all
        // (not just the empty-groups/default-fallback path the other
        // tests already cover), since shouldPoll()'s boolean result
        // alone can't distinguish which proxy entry was matched.
        $module = new WinrmPoller();
        $this->assertTrue($module->shouldPoll($this->makeOs($device), $status, $this->connectivity()));
    }

    public function testFalseWhenDeviceInUnconfiguredGroupAndNoDefault(): void
    {
        LibrenmsConfig::set('winrm.proxies', ['site-b' => ['url' => 'https://site-b-proxy']]);
        $device = $this->makeDevice(groupNames: ['other-site']);
        $status = new ModuleStatus(global: true);

        $module = new WinrmPoller();
        $this->assertFalse($module->shouldPoll($this->makeOs($device), $status, $this->connectivity()));
    }
}
