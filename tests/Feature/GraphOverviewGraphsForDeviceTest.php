<?php

/**
 * GraphOverviewGraphsForDeviceTest.php
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2026 LibreNMS
 */

namespace LibreNMS\Tests\Feature;

use App\Models\Device;
use App\Models\Mempool;
use App\Models\Processor;
use App\Models\Storage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LibreNMS\Tests\Traits\RequiresDatabase;
use LibreNMS\Tests\TestCase;
use LibreNMS\Util\Graph;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Covers the real header-sparkline bug this project hit: a device with
 * snmp_disable set (every WinRM device) always used to fall back to the
 * ping-only "ICMP Response" overview graph, even when it had genuine
 * Processor/Mempool/Storage data collected by a non-SNMP poller. These
 * tests need a real DB since getOverviewGraphsForDevice() now queries the
 * device's actual Processor/Mempool/Storage rows, not just its os/flags.
 */
#[TestDox('Graph::getOverviewGraphsForDevice()')]
final class GraphOverviewGraphsForDeviceTest extends TestCase
{
    use RequiresDatabase;
    use DatabaseTransactions;

    #[TestDox('snmp_disable device with no usage data falls back to ping-only')]
    public function testSnmpDisabledWithNoDataFallsBackToPing(): void
    {
        $device = Device::factory()->create(['snmp_disable' => true, 'os' => 'windows']);

        $graphs = Graph::getOverviewGraphsForDevice($device);

        $this->assertCount(1, $graphs);
        $this->assertSame('device_ping_perf', $graphs[0]['graph']);
    }

    #[TestDox('snmp_disable device with real Processor/Mempool/Storage data shows its OS usage graphs')]
    public function testSnmpDisabledWithUsageDataShowsOsGraphs(): void
    {
        $device = Device::factory()->create(['snmp_disable' => true, 'os' => 'windows']);
        $device->processors()->save(Processor::factory()->make());

        $graphs = Graph::getOverviewGraphsForDevice($device);

        $graphNames = array_column($graphs, 'graph');
        $this->assertContains('device_processor', $graphNames);
        $this->assertContains('device_mempool', $graphNames);
        $this->assertContains('device_storage', $graphNames);
        $this->assertNotContains('device_ping_perf', $graphNames);
    }

    #[TestDox('data-driven fallback also works via Mempool-only or Storage-only data, not just Processor')]
    public function testSnmpDisabledWithOnlyMempoolOrStorageDataStillShowsOsGraphs(): void
    {
        $mempoolOnly = Device::factory()->create(['snmp_disable' => true, 'os' => 'windows']);
        $mempoolOnly->mempools()->save(Mempool::factory()->make());
        $this->assertContains('device_processor', array_column(Graph::getOverviewGraphsForDevice($mempoolOnly), 'graph'));

        $storageOnly = Device::factory()->create(['snmp_disable' => true, 'os' => 'windows']);
        $storageOnly->storage()->save(Storage::factory()->make());
        $this->assertContains('device_processor', array_column(Graph::getOverviewGraphsForDevice($storageOnly), 'graph'));
    }

    #[TestDox('a normal SNMP device (snmp_disable false) is unaffected -- always uses its OS graphs')]
    public function testSnmpEnabledDeviceUnaffected(): void
    {
        $device = Device::factory()->create(['snmp_disable' => false, 'os' => 'windows']);

        $graphNames = array_column(Graph::getOverviewGraphsForDevice($device), 'graph');
        $this->assertContains('device_processor', $graphNames);
        $this->assertNotContains('device_ping_perf', $graphNames);
    }
}
