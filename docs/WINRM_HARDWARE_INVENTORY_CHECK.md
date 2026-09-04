# WinRM Check — Hardware Inventory (`hardware-inventory`)

**Contents**
- [Status](#status)
- [Why this check is architecturally new, not just another data source](#why-this-check-is-architecturally-new-not-just-another-data-source)
- [Deliberate scope decisions](#deliberate-scope-decisions)
- [JEA function](#jea-function)
- [Proxy side](#proxy-side)
- [`WinrmPoller.php` side: real research before assuming a model, per the handoff's explicit instruction](#winrmpollerphp-side-real-research-before-assuming-a-model-per-the-handoffs-explicit-instruction)
- [Minor note](#minor-note)
- [Next step](#next-step)
- [Deploying this check](#deploying-this-check)

<a id="status"></a>

## Status
**Confirmed working end-to-end against the real target** (2026-08-10) — `WinrmPoller.php` populating a real 8-row `EntPhysical` tree via `device:discover` on `lnms-poller.vpp.local`: a `chassis`-class root plus BIOS, base board, one CPU, one DIMM, two physical disks, and one physical NIC, all correctly nested under the chassis. A second `device:discover` confirmed the update-only sync path (0 inserts, 4 updates, still 8 rows). A real bug was caught by this end-to-end test and fixed before considering this done — see "A real bug caught by testing" below. `phpstan analyse` and `php -l` clean, existing `WinrmPollerTest.php` (applicability logic, untouched) still 6/6.

<a id="why-this-check-is-architecturally-new-not-just-another-data-source"></a>

## Why this check is architecturally new, not just another data source

Every prior check queries exactly one WMI/registry/event-log source. This one aggregates seven: `Win32_BIOS`, `Win32_BaseBoard`, `Win32_SystemEnclosure` (each a singleton), plus `Win32_Processor` (static identity, not `cpu-usage`'s `Get-Counter`-based utilization — same class name, completely different query), `Win32_PhysicalMemory`, `Win32_DiskDrive` (physical disks, distinct from `disk-space`'s `Win32_LogicalDisk` logical volumes — a two-disk host can have only one lettered volume, confirmed for real: the test VM has 2 physical disks but `disk-space` only ever reported one `C:` volume), and `Win32_NetworkAdapter` (filtered to `PhysicalAdapter=True` at the source, same "filter in the query" pattern as `disk-space`'s `DriveType=3`).

**Combined real latency measured, not assumed negligible**: ~1.8s for all seven `Get-WmiObject` calls in one function (`ELAPSED_MS: 1774` on the real target). Acceptable for a discover-only check (see below), would be a real concern if this ran on the 5-minute poll cadence.

<a id="deliberate-scope-decisions"></a>

## Deliberate scope decisions

- **No manufacture/release dates** (`Win32_BIOS.ReleaseDate`). WMI datetime strings (`20230615000000.000000+000`) need explicit `[System.Management.ManagementDateTimeConverter]` conversion to be usable — decided not worth that parsing complexity for v1, so the field is simply never requested rather than carried unused.
- **`-Depth 4` tested, not assumed from `memory-usage`'s value** — this check nests several arrays as siblings (`Processors`/`Memory`/`Disks`/`NetworkAdapters`) inside one combined object, a different shape than `memory-usage`'s single nested array. Verified by inspecting the actual serialized output against the real target: fully expanded, nothing truncated to a type name.
- **`Win32_Processor` array-wrapped despite being single-entry on every real target so far** — multi-socket systems exist, and treating it as scalar now would need a breaking change later. Same `@(...)` + `ConvertTo-Json -InputObject` pattern (not `-AsArray`, still absent on PowerShell 5.1) applied to all four list sections uniformly.
- **Real WMI data-quality variance observed and tolerated, not treated as a bug**: on the QEMU/Proxmox test VM, `BaseBoard`'s three fields were all `null`, `Chassis.SerialNumber`/`AssetTag` were empty strings (not `null`), and `Bios.SerialNumber` was `null`. Real hardware from vendors that populate SMBIOS more completely will likely return richer data. The proxy-side check accepts `null` everywhere except the four identity fields (`DeviceId`/`DeviceLocator`/`MacAddress`) `WinrmPoller.php` needs as stable per-item keys.

<a id="jea-function"></a>

## JEA function

Nine `Get-WmiObject` calls plus two registry reads (never `Get-CimInstance` — same always-loaded-module reasoning as every prior check, applied proactively and confirmed on real deployment rather than assumed a sixth-in-a-row success was guaranteed), combined into one `[pscustomobject]` with three singleton sections, four array sections, and root-level optional fields added across several later fixes (`ProcessorIdentifier`, `Contact`, `Location` — see `WINRM_OS_VERSION_FIX.md`, `WINRM_UPTIME_AND_HARDWARE_FIX.md`, `WINRM_CONTACT_LOCATION_FIX.md`). Current, complete version:

```powershell
function Get-HardwareInventoryStatus {
    [CmdletBinding()]
    param()

    $bios = Get-WmiObject -Class Win32_BIOS
    $baseBoard = Get-WmiObject -Class Win32_BaseBoard
    $chassis = Get-WmiObject -Class Win32_SystemEnclosure
    $operatingSystem = Get-WmiObject -Class Win32_OperatingSystem
    $computerSystem = Get-WmiObject -Class Win32_ComputerSystem
    $verKey = Get-ItemProperty -Path "HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion" -ErrorAction SilentlyContinue

    $releaseId = $null
    if ($verKey) {
        if ($verKey.DisplayVersion) {
            $releaseId = $verKey.DisplayVersion
        } elseif ($verKey.ReleaseId) {
            $releaseId = $verKey.ReleaseId
        }
    }

    $cpuKey = Get-ItemProperty -Path "HKLM:\HARDWARE\DESCRIPTION\System\CentralProcessor\0" -ErrorAction SilentlyContinue
    $processorIdentifier = $null
    if ($cpuKey -and $cpuKey.Identifier) {
        $processorIdentifier = $cpuKey.Identifier
    }

    $lnmsKey = Get-ItemProperty -Path "HKLM:\SOFTWARE\LibreNMS" -ErrorAction SilentlyContinue
    $contact = $null
    if ($lnmsKey -and $lnmsKey.Contact) {
        $contact = $lnmsKey.Contact
    }
    $location = $null
    if ($lnmsKey -and $lnmsKey.Location) {
        $location = $lnmsKey.Location
    }

    $processors = @(
        Get-WmiObject -Class Win32_Processor | ForEach-Object {
            [pscustomobject]@{
                DeviceId     = $_.DeviceID
                Name         = $_.Name
                Manufacturer = $_.Manufacturer
            }
        }
    )

    $memory = @(
        Get-WmiObject -Class Win32_PhysicalMemory | ForEach-Object {
            [pscustomobject]@{
                DeviceLocator = $_.DeviceLocator
                CapacityBytes = [int64]$_.Capacity
                Manufacturer  = $_.Manufacturer
                PartNumber    = $_.PartNumber
                SerialNumber  = $_.SerialNumber
            }
        }
    )

    $disks = @(
        Get-WmiObject -Class Win32_DiskDrive | ForEach-Object {
            [pscustomobject]@{
                DeviceId         = $_.DeviceID
                Model            = $_.Model
                SerialNumber     = $_.SerialNumber
                InterfaceType    = $_.InterfaceType
                FirmwareRevision = $_.FirmwareRevision
            }
        }
    )

    $networkAdapters = @(
        Get-WmiObject -Class Win32_NetworkAdapter -Filter "PhysicalAdapter=True" | Where-Object { $_.MACAddress } | ForEach-Object {
            [pscustomobject]@{
                MacAddress   = $_.MACAddress
                Name         = $_.Name
                Manufacturer = $_.Manufacturer
            }
        }
    )

    [pscustomobject]@{
        Bios = [pscustomobject]@{
            SerialNumber = $bios.SerialNumber
            Version      = $bios.SMBIOSBIOSVersion
            Manufacturer = $bios.Manufacturer
        }
        BaseBoard = [pscustomobject]@{
            Manufacturer = $baseBoard.Manufacturer
            Product      = $baseBoard.Product
            SerialNumber = $baseBoard.SerialNumber
        }
        Chassis = [pscustomobject]@{
            Manufacturer = $chassis.Manufacturer
            SerialNumber = $chassis.SerialNumber
            AssetTag     = $chassis.SMBIOSAssetTag
        }
        OperatingSystem = [pscustomobject]@{
            Caption                   = $operatingSystem.Caption
            Version                   = $operatingSystem.Version
            ReleaseId                 = $releaseId
            NumberOfLogicalProcessors = $computerSystem.NumberOfLogicalProcessors
        }
        Processors      = $processors
        Memory          = $memory
        Disks           = $disks
        NetworkAdapters = $networkAdapters
        ProcessorIdentifier = $processorIdentifier
        Contact = $contact
        Location = $location
    } | ConvertTo-Json -Compress -Depth 4
}
```

`Bios`/`BaseBoard`/`Chassis`/`OperatingSystem` are singleton sections (one instance always expected); `Processors`/`Memory`/`Disks`/`NetworkAdapters` are array sections, each wrapped in `@(...)` for the same single-element-collapse reason as `disk-space`'s `Get-LocalDiskSpace` — `Processors` included despite usually being one entry, since multi-socket hosts exist and treating it as scalar now would need a breaking change later. `ReleaseId`/`ProcessorIdentifier` come from the registry (`HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion`/`HKLM:\HARDWARE\DESCRIPTION\System\CentralProcessor\0`, both confirmed reachable via the same mechanism `reboot-pending` already uses); `NumberOfLogicalProcessors` from `Win32_ComputerSystem`, used to derive the Multiprocessor/Uniprocessor label — see `WINRM_OS_VERSION_FIX.md` for why it's the *logical*, not physical, count. `Contact`/`Location` come from a generic `HKLM:\SOFTWARE\LibreNMS` key (not org-specific — see `WINRM_CONTACT_LOCATION_FIX.md`), genuinely optional and expected absent on most hosts, hence the null-guarded reads rather than a bare `Get-ItemProperty` call.

<a id="proxy-side"></a>

## Proxy side

`app/checks/hardware_inventory.py` (`alexh/librenms-bits`) validates the aggregate shape: strict on identity fields (non-empty string), loose everywhere else (right type or `null`). 15 tests, including one built directly from the real sparse response captured against the test VM (`test_sparse_real_world_data_is_ok_not_malformed`) rather than a synthetic example. Registered in `registry.py` as `"hardware-inventory"`. Full proxy suite 132/132 passing.

<a id="winrmpollerphp-side-real-research-before-assuming-a-model-per-the-handoffs-explicit-instruction"></a>

## `WinrmPoller.php` side: real research before assuming a model, per the handoff's explicit instruction

**`entPhysical`, and it fits well** — but this wasn't assumed going in. Read `LibreNMS\Modules\EntityPhysical` and `App\Models\EntPhysical` directly first, same discipline `Storage.php` got before `disk-space`, given how wrong the original `network-traffic`/`Ports` guess turned out to be. Findings:

- **`EntityPhysical` is a modern class-based `Module`** (same lineage as `Mempools`/`Storage`), unlike `Processor`'s legacy path — a genuine relief given `cpu-usage`'s experience with `Processor`.
- **`EntityPhysical::poll()` is a real, deliberate no-op** (`// no polling`) in the actual shipped module. This is direct evidence — not a design invented for this check — that LibreNMS's own native inventory concept is already discover-only. Answers the handoff's explicit "does this need `poll()` at all?" question: no. **`hardware-inventory` has no `pollHardwareInventory()` method and nothing in `poll()` calls anything for it.** Data refreshes only when `discover()` runs, at LibreNMS's normal (much longer) discovery cadence rather than the 5-minute poll cadence — the cleanest possible answer to "does static data need frequent polling," achieved by just not writing a poll path at all rather than adding a custom slower-interval mechanism.
- **`EntPhysical`'s composite key is a bare `int`** (`entPhysicalIndex` alone — `getCompositeKey(): int { return (int) $this->entPhysicalIndex; }`), not a type+index pair like `Storage`/`Mempool`/`Port`. There's no type/namespace column on this table at all. Not a real collision risk here: `EntityPhysical::shouldDiscover()`/`shouldPoll()` both require `ConnectivityHelper::snmpIsAvailable()` — same gate as `Mempools`/`Storage` — so it never runs against these `snmp_disable` devices, meaning `$device->entityPhysical` only ever contains rows this module itself created. `dataExists()`/`cleanup()`/`dump()` use the unscoped relation directly, matching the real module's own implementation exactly (read, not guessed).
- **`EntPhysical` does declare `$fillable`** (unlike `Processor`) — mass assignment via `new EntPhysical([...])` works fine, same pattern as `Storage`/`Mempool`.

### A real bug caught by testing, not review

`entPhysicalIndex` is a plain signed 32-bit `integer` column (confirmed by reading the actual migration) — **not** `bigInteger` like `Port.ifIndex`. The first version of `syntheticEntPhysicalIndex()` copied `syntheticIfIndex()`'s `1_000_000_000`-wide-range pattern with a `2_000_000_000` base, which overflows signed INT32's ~2.147B ceiling. This wasn't caught by `phpstan` or `php -l` — only by actually running `device:discover` against the real device, which failed immediately with `SQLSTATE[22003]: Numeric value out of range... 2902394847`. Fixed by lowering the base/range (`500_000_000` base, `% 400_000_000`) to stay safely under the signed INT32 ceiling. Verified clean on retest: 8 rows created, indices like `602394847` well within range.

### Tree structure: chassis-rooted, not flat

`includes/html/pages/device/entphysical.inc.php` (the real Inventory tab renderer, read directly) expects a real containment tree via `entPhysicalContainedIn`/`entPhysicalParentRelPos`, starting from `entPhysicalContainedIn = 0` as the root query. Rather than a flat list (every row `containedIn = 0`) or attempting a deeper hierarchy WMI has no natural basis for, this check builds a simple two-level tree: **chassis at the root** (`entPhysicalClass = 'chassis'`, which the view renders with a real server icon), and BIOS/base board/every CPU/RAM stick/disk/NIC nested directly under it. `entPhysicalParentRelPos = -1` uniformly (the view only prints a position prefix when `> -1`; no natural per-item ordering exists here, not worth inventing one).

**`entPhysicalClass` values**: `'chassis'` for the root (gets a real icon); `'other'` for everything else — a real ENTITY-MIB `PhysicalClass` value (`other(1)`), not a misused or invented one. Considered `'module'` (which some vendors use broadly for DIMMs/disks/NICs) but stuck with `'other'` since this data has no vendor convention to justify a more specific class — the view degrades gracefully to plain text for any class it doesn't have a specific icon for, so this is purely cosmetic, not a functional gap.

**Field mapping highlights**: BIOS version stored as `entPhysicalSoftwareRev` (a genuine fit, not a stretch). Disk firmware stored as `entPhysicalFirmwareRev`. NIC MAC address stored as `entPhysicalAlias` (a free-text identifier field the view already renders as `"Alias: <value>"`) rather than `entPhysicalSerialNum`, since a MAC isn't a manufacturer-assigned serial. Memory capacity has no dedicated `EntPhysical` column, so it's folded into `entPhysicalDescr` as a human-readable string (`"Physical Memory (8 GB)"`).

**Identity/synthetic-index scheme**: same `crc32(stable identity string)`-based technique as `network-traffic`'s `Port.ifIndex`, reused for the same reason — WMI's enumeration order for multi-instance classes isn't guaranteed stable across calls, so indices need to come from a stable per-item string (`DeviceID`/`DeviceLocator`/`MacAddress`), not array position. Same caveat as every prior synthetic-index/identity-key decision in this module: a hardware swap reads as "removed, new one added" on the next discovery, not worth solving preemptively.

<a id="minor-note"></a>

## Minor note

Serial numbers and asset tags are semi-sensitive inventory data — same trust boundary as everything else this JEA account can already read (the whitelisted functions have no broader access than before). Noted explicitly here rather than collected without comment.

<a id="next-step"></a>

## Next step

None outstanding for this check — fully built, tested, and verified end-to-end.

<a id="deploying-this-check"></a>

## Deploying this check

Incremental add — assumes `WINRM_JEA_SETUP.md`'s first-time setup already ran, plus `reboot-pending`, the two `service-status-*` checks, `winupdate-pending`, `disk-space`, `network-traffic`, `memory-usage`, and `cpu-usage` (see those docs). Adds `Get-HardwareInventoryStatus` (current, complete version — see the JEA function section above) and its `.psrc` whitelist entry. `.psrc`/`.psm1`-only — no `.pssc` change, no WinRM restart (see `WINRM_JEA_SETUP.md` §5). This is the last of the nine checks, so the module below is the project's complete, final `WinrmProbeJEA.psm1`.

```powershell
# Update-WinrmProbeJEA-HardwareInventory.ps1
if ($env:COMPUTERNAME -ne 'WINRM-TEST01') {
    throw "This script must run on WINRM-TEST01, not $env:COMPUTERNAME. Aborting."
}

$moduleRoot = 'C:\Program Files\WindowsPowerShell\Modules\WinrmProbeJEA'
$roleCapDir = Join-Path $moduleRoot 'RoleCapabilities'

@'
function Get-RebootPendingStatus {
    [CmdletBinding()]
    param()

    $wuPath  = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired'
    $pfroKey = 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager'

    [pscustomobject]@{
        RebootRequired = Test-Path $wuPath
        PendingFileRenameOperations = $null -ne (
            Get-ItemProperty -Path $pfroKey -Name PendingFileRenameOperations -ErrorAction SilentlyContinue
        )
    } | ConvertTo-Json -Compress
}

function Get-WuauservStatus {
    [CmdletBinding()]
    param()
    (Get-Service -Name wuauserv).Status.ToString() | ConvertTo-Json -Compress
}

function Get-W32timeStatus {
    [CmdletBinding()]
    param()
    (Get-Service -Name W32Time).Status.ToString() | ConvertTo-Json -Compress
}

function Get-PendingUpdateStatus {
    [CmdletBinding()]
    param()

    try {
        $e = Get-WinEvent -LogName 'Microsoft-Windows-WindowsUpdateClient/Operational' -FilterXPath "*[System[EventID=26]]" -MaxEvents 1 -ErrorAction Stop
    } catch [Exception] {
        [pscustomobject]@{
            PendingCount = $null
            LastScanTime = $null
        } | ConvertTo-Json -Compress
        return
    }

    $count = $null
    if ($e.Message -match 'successfully found (\d+) updates') {
        $count = [int]$Matches[1]
    }

    [pscustomobject]@{
        PendingCount = $count
        LastScanTime = $e.TimeCreated.ToString('o')
    } | ConvertTo-Json -Compress
}

function Get-LocalDiskSpace {
    [CmdletBinding()]
    param()

    $disks = @(
        Get-WmiObject -Class Win32_LogicalDisk -Filter "DriveType=3" | ForEach-Object {
            [pscustomobject]@{
                DriveLetter = $_.DeviceID
                SizeBytes   = [int64]$_.Size
                FreeBytes   = [int64]$_.FreeSpace
            }
        }
    )
    ConvertTo-Json -InputObject $disks -Compress
}

function Get-NetworkInterfaceStats {
    [CmdletBinding()]
    param()

    $adaptersByName = @{}
    Get-WmiObject -Class Win32_NetworkAdapter -Filter "PhysicalAdapter=True" | ForEach-Object {
        $adaptersByName[$_.Name] = $_
    }

    $stats = @(
        Get-WmiObject -Class Win32_PerfRawData_Tcpip_NetworkInterface | ForEach-Object {
            $adapter = $adaptersByName[$_.Name]

            $netEnabled = $null
            $netConnectionStatus = $null
            if ($adapter) {
                $netEnabled = $adapter.NetEnabled
                $netConnectionStatus = $adapter.NetConnectionStatus
            }

            [pscustomobject]@{
                InterfaceName       = $_.Name
                BytesSent           = [int64]$_.BytesSentPersec
                BytesReceived       = [int64]$_.BytesReceivedPersec
                NetEnabled          = $netEnabled
                NetConnectionStatus = $netConnectionStatus
            }
        }
    )
    ConvertTo-Json -InputObject $stats -Compress
}

function Get-MemoryUsageStatus {
    [CmdletBinding()]
    param()

    $os = Get-WmiObject -Class Win32_OperatingSystem
    $pageFiles = @(
        Get-WmiObject -Class Win32_PageFileUsage | ForEach-Object {
            [pscustomobject]@{
                Name           = $_.Name
                AllocatedBytes = [int64]$_.AllocatedBaseSize * 1MB
                UsedBytes      = [int64]$_.CurrentUsage * 1MB
            }
        }
    )

    $lastBootUpTime = $null
    if ($os.LastBootUpTime) {
        $lastBootUpTime = [System.Management.ManagementDateTimeConverter]::ToDateTime($os.LastBootUpTime).ToUniversalTime().ToString('o')
    }

    [pscustomobject]@{
        PhysicalTotalBytes = [int64]$os.TotalVisibleMemorySize * 1KB
        PhysicalFreeBytes  = [int64]$os.FreePhysicalMemory * 1KB
        VirtualTotalBytes  = [int64]$os.TotalVirtualMemorySize * 1KB
        VirtualFreeBytes   = [int64]$os.FreeVirtualMemory * 1KB
        PageFiles          = $pageFiles
        LastBootUpTime     = $lastBootUpTime
    } | ConvertTo-Json -Compress -Depth 4
}

function Get-CpuUsageStatus {
    [CmdletBinding()]
    param()

    $counters = Get-Counter -Counter '\Processor(*)\% Processor Time' -SampleInterval 1 -MaxSamples 1

    $cores = @(
        $counters.CounterSamples | Where-Object { $_.InstanceName -ne '_total' } | ForEach-Object {
            [pscustomobject]@{
                CoreIndex   = $_.InstanceName
                BusyPercent = [math]::Round($_.CookedValue, 2)
            }
        }
    )
    ConvertTo-Json -InputObject $cores -Compress
}

function Get-HardwareInventoryStatus {
    [CmdletBinding()]
    param()

    $bios = Get-WmiObject -Class Win32_BIOS
    $baseBoard = Get-WmiObject -Class Win32_BaseBoard
    $chassis = Get-WmiObject -Class Win32_SystemEnclosure
    $operatingSystem = Get-WmiObject -Class Win32_OperatingSystem
    $computerSystem = Get-WmiObject -Class Win32_ComputerSystem
    $verKey = Get-ItemProperty -Path "HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion" -ErrorAction SilentlyContinue

    $releaseId = $null
    if ($verKey) {
        if ($verKey.DisplayVersion) {
            $releaseId = $verKey.DisplayVersion
        } elseif ($verKey.ReleaseId) {
            $releaseId = $verKey.ReleaseId
        }
    }

    $cpuKey = Get-ItemProperty -Path "HKLM:\HARDWARE\DESCRIPTION\System\CentralProcessor\0" -ErrorAction SilentlyContinue
    $processorIdentifier = $null
    if ($cpuKey -and $cpuKey.Identifier) {
        $processorIdentifier = $cpuKey.Identifier
    }

    $lnmsKey = Get-ItemProperty -Path "HKLM:\SOFTWARE\LibreNMS" -ErrorAction SilentlyContinue
    $contact = $null
    if ($lnmsKey -and $lnmsKey.Contact) {
        $contact = $lnmsKey.Contact
    }
    $location = $null
    if ($lnmsKey -and $lnmsKey.Location) {
        $location = $lnmsKey.Location
    }

    $processors = @(
        Get-WmiObject -Class Win32_Processor | ForEach-Object {
            [pscustomobject]@{
                DeviceId     = $_.DeviceID
                Name         = $_.Name
                Manufacturer = $_.Manufacturer
            }
        }
    )

    $memory = @(
        Get-WmiObject -Class Win32_PhysicalMemory | ForEach-Object {
            [pscustomobject]@{
                DeviceLocator = $_.DeviceLocator
                CapacityBytes = [int64]$_.Capacity
                Manufacturer  = $_.Manufacturer
                PartNumber    = $_.PartNumber
                SerialNumber  = $_.SerialNumber
            }
        }
    )

    $disks = @(
        Get-WmiObject -Class Win32_DiskDrive | ForEach-Object {
            [pscustomobject]@{
                DeviceId         = $_.DeviceID
                Model            = $_.Model
                SerialNumber     = $_.SerialNumber
                InterfaceType    = $_.InterfaceType
                FirmwareRevision = $_.FirmwareRevision
            }
        }
    )

    $networkAdapters = @(
        Get-WmiObject -Class Win32_NetworkAdapter -Filter "PhysicalAdapter=True" | Where-Object { $_.MACAddress } | ForEach-Object {
            [pscustomobject]@{
                MacAddress   = $_.MACAddress
                Name         = $_.Name
                Manufacturer = $_.Manufacturer
            }
        }
    )

    [pscustomobject]@{
        Bios = [pscustomobject]@{
            SerialNumber = $bios.SerialNumber
            Version      = $bios.SMBIOSBIOSVersion
            Manufacturer = $bios.Manufacturer
        }
        BaseBoard = [pscustomobject]@{
            Manufacturer = $baseBoard.Manufacturer
            Product      = $baseBoard.Product
            SerialNumber = $baseBoard.SerialNumber
        }
        Chassis = [pscustomobject]@{
            Manufacturer = $chassis.Manufacturer
            SerialNumber = $chassis.SerialNumber
            AssetTag     = $chassis.SMBIOSAssetTag
        }
        OperatingSystem = [pscustomobject]@{
            Caption                   = $operatingSystem.Caption
            Version                   = $operatingSystem.Version
            ReleaseId                 = $releaseId
            NumberOfLogicalProcessors = $computerSystem.NumberOfLogicalProcessors
        }
        Processors      = $processors
        Memory          = $memory
        Disks           = $disks
        NetworkAdapters = $networkAdapters
        ProcessorIdentifier = $processorIdentifier
        Contact = $contact
        Location = $location
    } | ConvertTo-Json -Compress -Depth 4
}

Export-ModuleMember -Function Get-RebootPendingStatus, Get-WuauservStatus, Get-W32timeStatus, Get-PendingUpdateStatus, Get-LocalDiskSpace, Get-NetworkInterfaceStats, Get-MemoryUsageStatus, Get-CpuUsageStatus, Get-HardwareInventoryStatus
'@ | Set-Content -Path (Join-Path $moduleRoot 'WinrmProbeJEA.psm1') -Encoding UTF8

@'
@{
    Author = 'Your Name'
    CompanyName = 'VPP'
    Description = 'Constrained check functions exposed to the WinRM proxy service account.'

    VisibleFunctions = @('Get-RebootPendingStatus', 'Get-WuauservStatus', 'Get-W32timeStatus', 'Get-PendingUpdateStatus', 'Get-LocalDiskSpace', 'Get-NetworkInterfaceStats', 'Get-MemoryUsageStatus', 'Get-CpuUsageStatus', 'Get-HardwareInventoryStatus')
    VisibleCmdlets = @()
    VisibleAliases = @()
    VisibleExternalCommands = @()
    VisibleProviders = @()
}
'@ | Set-Content -Path (Join-Path $roleCapDir 'WinrmProbe.psrc') -Encoding UTF8

Write-Host "Done, no WinRM restart performed. Validate with a NEW session:"
Write-Host "  Enter-PSSession -ComputerName $env:COMPUTERNAME -ConfigurationName WinrmProbe"
Write-Host "  Get-Command              # expect all nine Get-* functions listed, nothing else"
Write-Host "  Get-HardwareInventoryStatus   # expect the full aggregate JSON object"
```

Then run `WINRM_JEA_SETUP.md` §7's validation checklist to confirm the constraint still holds — this is the last check, so this is also the last checkpoint to validate the whole module before considering the endpoint fleet-ready.
