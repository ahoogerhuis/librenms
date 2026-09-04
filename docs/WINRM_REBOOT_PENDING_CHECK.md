# WinRM Check — Reboot Pending (`reboot-pending`)

**Contents**
- [Status](#status)
- [Goal](#goal)
- [JEA function](#jea-function)
- [Proxy side](#proxy-side)
- [`WinrmPoller.php` side](#winrmpollerphp-side)
- [Deploying this check](#deploying-this-check)

<a id="status"></a>

## Status

**Built, deployed, and confirmed working end-to-end against the real target.** This was the first check this project built — the one that established the JEA whitelist-by-function-name pattern, the poller-to-proxy JSON protocol, and the `Sensor`-backed (`sensor_class: 'state'`) storage shape every later state-based check (`service-status-wuauserv`/`service-status-w32time`) reused directly.

<a id="goal"></a>

## Goal

Whether a Windows host is waiting on a reboot to finish applying pending changes — the same signal Windows' own "you need to restart" prompt is based on, exposed as a LibreNMS sensor instead of something only visible at the console.

<a id="jea-function"></a>

## JEA function

```powershell
function Get-RebootPendingStatus {
    [CmdletBinding()]
    param()  # deliberately no parameters

    $wuPath  = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired'
    $pfroKey = 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager'

    [pscustomobject]@{
        RebootRequired = Test-Path $wuPath
        PendingFileRenameOperations = $null -ne (
            Get-ItemProperty -Path $pfroKey -Name PendingFileRenameOperations -ErrorAction SilentlyContinue
        )
    } | ConvertTo-Json -Compress
}
```

Two independent, well-known reboot-pending indicators, checked together rather than picking one:
- **`...WindowsUpdate\Auto Update\RebootRequired`** — the key Windows Update itself sets after installing updates that need a restart to take effect. `Test-Path` alone is enough; the key's mere existence is the signal, its value (if any) doesn't matter.
- **`...Session Manager\PendingFileRenameOperations`** — a broader signal, set whenever *any* pending file-move/delete operation is queued for the next boot (not just Windows Update — installers of all kinds use this mechanism via `MoveFileEx` with the delay-until-reboot flag). `Get-ItemProperty -ErrorAction SilentlyContinue` plus a null-check, since the value not existing at all is the normal "nothing pending" state, not an error.

Both are read-only registry checks — no state is changed, no service is touched, nothing this function does has any side effect on the target.

<a id="proxy-side"></a>

## Proxy side

`app/checks/reboot_pending.py` invokes `Get-RebootPendingStatus`, parses the two booleans, and collapses them with a plain OR into a single `{"reboot_pending": <bool>}` — either indicator alone is enough to mean "yes, this host is waiting on a reboot," so there's no meaningful distinction to preserve downstream between "Windows Update wants a restart" and "some other pending file operation wants one." `reboot_pending` is computed as `bool(...) or bool(...)`, tolerant of either registry key legitimately being absent (both collapse to `False`, the "nothing pending" case, not an error).

<a id="winrmpollerphp-side"></a>

## `WinrmPoller.php` side

`sensor_class: 'state'`, `sensor_type: 'winrm-reboot-pending'`, `value_key: 'reboot_pending'`, mapped through `fn ($raw) => $raw ? 1 : 0` against two discrete states (`0` = "No reboot pending", `1` = "Reboot pending") — a real `App\Models\Sensor` row (`poller_type: 'winrm'`), not a device-attrib or bespoke RRD, chosen specifically for native Alert Rule builder / Health-Sensors UI integration (see `WINRM_DESIGN.md`'s "Module" section for the full reasoning). Every later state-based check this project built (`service-status-wuauserv`/`service-status-w32time`) reuses this exact shape — `states`/`value_key`/`map` in one declarative table entry, no per-check bespoke code path.

<a id="deploying-this-check"></a>

## Deploying this check

The first real check — assumes only `WINRM_JEA_SETUP.md`'s first-time setup has run (module structure, session configuration). If you followed that guide's first-time script literally (using its three representative example functions), this replaces those placeholder functions with the real first check instead of building alongside them.

```powershell
# Update-WinrmProbeJEA-RebootPending.ps1
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

Export-ModuleMember -Function Get-RebootPendingStatus
'@ | Set-Content -Path (Join-Path $moduleRoot 'WinrmProbeJEA.psm1') -Encoding UTF8

@'
@{
    Author = 'Your Name'
    CompanyName = 'VPP'
    Description = 'Constrained check functions exposed to the WinRM proxy service account.'

    VisibleFunctions = @('Get-RebootPendingStatus')
    VisibleCmdlets = @()
    VisibleAliases = @()
    VisibleExternalCommands = @()
    VisibleProviders = @()
}
'@ | Set-Content -Path (Join-Path $roleCapDir 'WinrmProbe.psrc') -Encoding UTF8

Write-Host "Done, no WinRM restart performed (assuming session config already registered per WINRM_JEA_SETUP.md). Validate with a NEW session:"
Write-Host "  Enter-PSSession -ComputerName $env:COMPUTERNAME -ConfigurationName WinrmProbe"
Write-Host "  Get-Command                # expect ONLY Get-RebootPendingStatus"
Write-Host "  Get-RebootPendingStatus    # expect JSON output"
```

Then run `WINRM_JEA_SETUP.md` §7's validation checklist to confirm the constraint holds — this is the first real proof point that the whole endpoint (session config + module + whitelist) is wired correctly end-to-end, not just that the individual pieces exist.
