# WinRM Check — Service Status (`service-status-wuauserv`, `service-status-w32time`)

**Contents**
- [Status](#status)
- [Goal](#goal)
- [One function per service — decided, not left open](#one-function-per-service-decided-not-left-open)
- [JEA functions](#jea-functions)
- [Proxy side](#proxy-side)
- [`WinrmPoller.php` side](#winrmpollerphp-side)
- [Deploying these checks](#deploying-these-checks)

<a id="status"></a>

## Status

**Built, deployed, and confirmed working end-to-end against the real target.** The second and third checks built (after `reboot-pending`), and the first real test of a couple of things `WINRM_DESIGN.md` had only claimed until then — see "JEA functions" below for the `ConvertTo-Json`-on-a-bare-enum finding, and `WINRM_JEA_SETUP.md` §8 for the generalized version of that lesson.

<a id="goal"></a>

## Goal

Whether the Windows Update service (`wuauserv`) and the Windows Time service (`W32Time`) are running — two services worth monitoring directly rather than only inferring their health indirectly (e.g. via `winupdate-pending` never returning fresh data, or via visible clock drift).

<a id="one-function-per-service-decided-not-left-open"></a>

## One function per service — decided, not left open

Considered and rejected a single parameterized `Get-ServiceStatus` function (`ValidateSet`-constrained to `wuauserv`/`W32Time`) in favor of one function per service, for a real reason, not just consistency with the zero-parameter pattern elsewhere: that approach's safety depends on correctly implementing the constraint in **two** places — the function's own `ValidateSet` and a matching `VisibleFunctions`/parameter entry in the `.psrc` — and skipping the second layer is an easy, silent mistake, since the function still works correctly for every allowed value either way. One function per service has zero parameter-handling code to get wrong at all, and the `.psrc` file alone tells you everything reachable, no cross-referencing needed.

**Worth reopening at fleet scale, not a settled-forever answer.** This tradeoff was framed as "one service, a binary choice," where the ceremony cost of one-function-per-service is trivial. A fleet-wide, many-services version of the same question changes the calculus: Option A's ceremony (one function + one whitelist entry, every time) compounds linearly per service, while the parameterized approach's two-layer-enforcement risk is a one-time cost to get right rather than a recurring one. Worth re-deciding with that framing if this project's check count grows substantially past what it covers today — not deferring to this answer by default just because it was decided once.

<a id="jea-functions"></a>

## JEA functions

```powershell
function Get-WuauservStatus {
    [CmdletBinding()]
    param()  # deliberately no parameters
    (Get-Service -Name wuauserv).Status.ToString() | ConvertTo-Json -Compress
}

function Get-W32timeStatus {
    [CmdletBinding()]
    param()  # deliberately no parameters
    (Get-Service -Name W32Time).Status.ToString() | ConvertTo-Json -Compress
}
```

**`.ToString()` is required, not decorative.** `ConvertTo-Json` on a bare `ServiceControllerStatus` enum value serializes it as its underlying **integer** (`1`, `4`, ...), not its string name (`Stopped`, `Running`, ...) — found for real during first end-to-end testing: `Get-WuauservStatus` returned `1`, `Get-W32timeStatus` returned `4`, both semantically correct but the wrong wire shape for the proxy-side parser, which expects a JSON string. `.ToString()` forces the string name before serialization. Generalizes beyond these two checks: any PowerShell enum value going into a JEA check function's JSON output needs an explicit `.ToString()` — don't assume `ConvertTo-Json` picks the "obviously more useful" representation.

<a id="proxy-side"></a>

## Proxy side

`app/checks/service_status_wuauserv.py`/`service_status_w32time.py` each invoke their respective function and return `{"status": "<name>"}` — a plain string, validated against the real `ServiceControllerStatus` name set (`Stopped`/`StartPending`/`StopPending`/`Running`/`ContinuePending`/`PausePending`/`Paused`), fails loud on anything else (a typo'd name, an integer that slipped through, an unrecognized state) rather than passing an unvalidated string downstream.

<a id="winrmpollerphp-side"></a>

## `WinrmPoller.php` side

Both checks are `sensor_class: 'state'`, reusing the exact same shape `reboot-pending` established: `sensor_type: 'winrm-service-status-wuauserv'`/`'winrm-service-status-w32time'`, `value_key: 'status'`, mapped through a shared lookup table (`ServiceControllerStatus` name → its real underlying integer value — `Stopped => 1`, `StartPending => 2`, `StopPending => 3`, `Running => 4`, `ContinuePending => 5`, `PausePending => 6`, `Paused => 7`) rather than inventing a new arbitrary state numbering. Both checks' `states` arrays are built from that one shared table via `array_map`, not hand-duplicated per check — a typo in one service's state list can't silently diverge from the other's.

<a id="deploying-these-checks"></a>

## Deploying these checks

Incremental add — assumes `WINRM_JEA_SETUP.md`'s first-time setup already ran, plus `reboot-pending` (see `WINRM_REBOOT_PENDING_CHECK.md`). Adds both `Get-WuauservStatus`/`Get-W32timeStatus` and their `.psrc` whitelist entries together, since they share one design (see above) and were built as a pair. `.psrc`/`.psm1`-only — no `.pssc` change, no WinRM restart (see `WINRM_JEA_SETUP.md` §5).

```powershell
# Update-WinrmProbeJEA-ServiceStatus.ps1
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

Export-ModuleMember -Function Get-RebootPendingStatus, Get-WuauservStatus, Get-W32timeStatus
'@ | Set-Content -Path (Join-Path $moduleRoot 'WinrmProbeJEA.psm1') -Encoding UTF8

@'
@{
    Author = 'Your Name'
    CompanyName = 'VPP'
    Description = 'Constrained check functions exposed to the WinRM proxy service account.'

    VisibleFunctions = @('Get-RebootPendingStatus', 'Get-WuauservStatus', 'Get-W32timeStatus')
    VisibleCmdlets = @()
    VisibleAliases = @()
    VisibleExternalCommands = @()
    VisibleProviders = @()
}
'@ | Set-Content -Path (Join-Path $roleCapDir 'WinrmProbe.psrc') -Encoding UTF8

Write-Host "Done, no WinRM restart performed. Validate with a NEW session (existing sessions keep their old whitelist for their lifetime):"
Write-Host "  Enter-PSSession -ComputerName $env:COMPUTERNAME -ConfigurationName WinrmProbe"
Write-Host "  Get-Command                # expect Get-RebootPendingStatus, Get-WuauservStatus, Get-W32timeStatus"
Write-Host "  Get-WuauservStatus          # expect JSON output, a string like \"Running\""
Write-Host "  Get-W32timeStatus           # expect JSON output"
```

Then run `WINRM_JEA_SETUP.md` §7's validation checklist to confirm the constraint still holds.
