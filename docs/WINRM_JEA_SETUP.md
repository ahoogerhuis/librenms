# Building the Windows-Side JEA Endpoint

A generic, standalone guide to building the JEA (Just Enough Administration) endpoint this module's proxy daemon talks to — the piece that actually constrains what the proxy can do on each monitored Windows host. `WINRM_DESIGN.md` covers the overall architecture and the Linux-side proxy; this doc is the third leg, and the one every adopter has to build for themselves since it lives entirely inside their own AD environment.

Every example below uses a fictional placeholder org — company **Virtual Pooper Plumbers (VPP)**, domain **VPP.LOCAL**, service account `svc-winrmproxy@VPP.LOCAL`, example hosts `winrm-proxy.vpp.local`/`winrm-test01.vpp.local`. Substitute your own realm/accounts/hostnames throughout; nothing here depends on this specific naming.

**What this doesn't cover:** Ansible or any other configuration-management tooling — this project deliberately keeps that out of scope (see `WINRM_DESIGN.md`'s "Explicitly out of scope for v1"), so this guide covers the manual/scripted PowerShell + GPO path only. It also doesn't describe any specific organization's real AD structure (OU layout, GPO names, security groups) — only the generic *pattern*, which you adapt to whatever your AD already looks like.

## Contents
- [1. The service account](#1-the-service-account)
- [2. Module structure and the whitelist design principle](#2-module-structure-and-the-whitelist-design-principle)
- [3. Representative check functions](#3-representative-check-functions)
- [4. Session configuration](#4-session-configuration)
- [5. Hot-reload vs. re-registration](#5-hot-reload-vs-re-registration)
- [6. Deployment: one target now, GPO/SYSVOL later](#6-deployment-one-target-now-gposysvol-later)
- [7. Validating the endpoint is actually constrained](#7-validating-the-endpoint-is-actually-constrained)
- [8. Lessons worth not re-learning](#8-lessons-worth-not-re-learning)

## 1. The service account

The proxy authenticates to each JEA endpoint as a **plain domain user** — no local admin rights, no special AD group membership anywhere. Every bit of access control lives in the JEA role definition (§4), not in anything granted to the account itself.

```powershell
New-ADUser -Name "svc-winrmproxy" `
    -UserPrincipalName "svc-winrmproxy@VPP.LOCAL" `
    -AccountPassword (Read-Host -AsSecureString "Password") `
    -Enabled $true `
    -PasswordNeverExpires $true `
    -CannotChangePassword $true
```

`-CannotChangePassword $true` is load-bearing, not boilerplate — this account authenticates via a Kerberos keytab (generated with `ktpass`, which bakes in a specific KVNO), and a self-service password change would break that keytab immediately for no operational benefit, since the account never logs in interactively.

Generate the keytab:
```
ktpass -princ svc-winrmproxy@VPP.LOCAL -mapuser VPP\svc-winrmproxy -crypto AES256-SHA1 -ptype KRB5_NT_PRINCIPAL +rndpass -out svc-winrmproxy.keytab
```
Expect a benign `Failed to set property 'servicePrincipalName'` warning — `ktpass` always tries to write an SPN, but SPNs resolve a *service being connected to* (host-based), and this account is the *client* identity the proxy authenticates *as*, so there's nothing valid to write. What matters is `Password successfully set!` and the keytab dump showing `etype 0x12 (AES256-SHA1)`.

**Keytab/password coupling, worth flagging to whoever runs credential-rotation policy at your org**: nothing enforces that the keytab and the AD account's password stay in sync. If the password is ever reset through anything other than re-running `ktpass` — a GUI reset, a bulk service-account rotation sweep — the AD-side KVNO moves forward, the keytab's doesn't, and Kerberos auth fails hard (`KRB_AP_ERR_MODIFIED`) with no advance warning. Exclude this account from automated rotation sweeps; rotate only via `ktpass`, redeploying the new keytab to the proxy in the same step.

## 2. Module structure and the whitelist design principle

```
WinrmProbeJEA\
├── WinrmProbeJEA.psd1              # module manifest
├── WinrmProbeJEA.psm1              # all check functions live here
├── .pssc-version                   # bump only on structural .pssc changes (see §5)
└── RoleCapabilities\
    └── WinrmProbe.psrc             # VisibleFunctions whitelist -- this is what hot-reloads
```

**Design principle: whitelist by function name, not by cmdlet + parameter pattern.** JEA can constrain a cmdlet directly via `VisibleCmdlets` with `ValidatePattern`/`ValidateSet` parameter rules, but that's fiddly and error-prone — a slightly-too-loose pattern silently widens what's allowed. Instead, wrap each check in a **custom function that takes zero parameters**, with the actual target (a registry path, a WMI class, whatever) hardcoded inside the function body. Whitelist only the function name, with `VisibleCmdlets = @()` — nothing built-in is reachable at all, not even something as innocuous-seeming as `Get-ItemProperty`. No parameter surface exists for a caller to manipulate, and the `.psrc` file alone tells you everything reachable — no cross-referencing a separate constraint list needed.

This also shapes the proxy-to-JEA protocol: the proxy's executor primitive is `invoke_function(host, function_name)` — a bare function name, zero arguments — matching this whitelist shape exactly rather than something that would need to pass caller-supplied parameters through.

**One function per distinct check, not one parameterized function covering several.** Considered and rejected a single `Get-ServiceStatus` function taking a service-name parameter, constrained via `ValidateSet`, instead of one function per service. The parameterized version's safety depends on correctly implementing the constraint in *two* places — the function's own `ValidateSet` and a matching `VisibleFunctions`/parameter entry in the `.psrc` — and skipping the second layer is an easy, silent mistake, since the function still works correctly for every allowed value either way. One function per check has no parameter-handling code to get wrong at all.

Every function returns compact JSON directly — `ConvertTo-Json` runs *inside* the whitelisted function body, which is fine: `VisibleFunctions`/`VisibleCmdlets` only constrain what the *caller* can invoke directly in the session, not what an already-whitelisted function does internally. This keeps the consuming side's parsing trivial (plain `json.loads`/equivalent) rather than needing to deserialize a full PSRP-serialized object graph.

## 3. Representative check functions

Three examples, chosen to cover the real range of patterns rather than mechanically listing every check this project ended up building — enough to build a tenth check of your own from the pattern that fits it.

### A simple zero-parameter scalar/boolean check

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
The simplest possible shape: no external dependency beyond the registry, no array-serialization concerns, no optional-data handling. A good template to start from for any new boolean/scalar check.

### A multi-instance, array-returning check

```powershell
function Get-LocalDiskSpace {
    [CmdletBinding()]
    param()  # deliberately no parameters

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
```
`DriveType=3` filters to local fixed disks only (excludes removable/network/CD-ROM/RAM-disk). The `@(...)` wrapper plus `-InputObject` (not a pipe) is not decorative — see §8 for why that specific combination is required on Windows PowerShell 5.1.

### A check with graceful, expected-absent optional data

```powershell
function Get-ExampleOptionalData {
    [CmdletBinding()]
    param()  # deliberately no parameters

    # Absent entirely is the expected common case here, not a
    # degraded/error state -- most hosts will never have this
    # configured. -ErrorAction SilentlyContinue plus an explicit
    # null-check, not a bare Get-ItemProperty call that would throw.
    $key = Get-ItemProperty -Path "HKLM:\SOFTWARE\YourProduct" -ErrorAction SilentlyContinue

    $contact = $null
    if ($key -and $key.Contact) {
        $contact = $key.Contact
    }

    [pscustomobject]@{
        Contact = $contact
    } | ConvertTo-Json -Compress
}
```
Most checks in this project fail loud on missing/malformed data, because the underlying data is always supposed to be there (a disk either exists or the host has no disks at all — either way `Get-LocalDiskSpace` above returns a valid, if possibly empty, array). This pattern is for the different, real case: data that's genuinely optional and expected to be absent on most hosts (admin-populated configuration, not anything derivable from the OS itself). Whatever consumes this on the other end needs to treat "absent" and "present but null" identically as a legitimate "don't know"/"not configured" state, not an error to retry or alert on.

## 4. Session configuration

```powershell
New-PSSessionConfigurationFile -Path .\WinrmProbe.pssc `
    -SessionType RestrictedRemoteServer `
    -RunAsVirtualAccount `
    -RoleDefinitions @{ 'VPP\svc-winrmproxy' = @{ RoleCapabilities = 'WinrmProbe' } } `
    -TranscriptDirectory 'C:\ProgramData\WinrmProbeJEA\Transcripts'   # audit trail, optional but recommended

Register-PSSessionConfiguration -Path .\WinrmProbe.pssc -Name 'WinrmProbe' -Force
```

**Why `-RunAsVirtualAccount`:** the session runs under a temporary, local-admin-equivalent virtual account that exists only for the session's lifetime — no stored run-as credential needed anywhere, and the connecting account (`svc-winrmproxy`) itself never needs any real local rights on the target. This is the Microsoft-recommended JEA pattern specifically because it decouples **who connects** (a low-privilege domain user) from **what's technically possible in the session** (broad, via the virtual account) — the role capability's `VisibleFunctions` whitelist is what's *actually* enforced, not the connecting account's own privilege level. Getting this backwards — granting `svc-winrmproxy` real local rights instead — would mean the whitelist is the only thing standing between a compromised proxy credential and full local access, rather than a defense-in-depth layer on top of an account that has nothing to lose in the first place.

`-TranscriptDirectory` gives a local audit log of every JEA session on that host — worth keeping at least through initial rollout, to verify in practice that nothing outside the whitelist ever gets invoked.

The `-Name` here (`WinrmProbe`) is whatever configuration name the proxy's PSRP client connects to — make sure it matches the proxy's own configuration on the other end.

## 5. Hot-reload vs. re-registration

Two files change on very different cadences, and conflating them costs you unnecessary WinRM restarts:

- **`.psrc` (the role capability / function whitelist) and `.psm1` (the module itself) hot-reload.** PowerShell reads both fresh per session — adding a check is: add the function to `.psm1`, add its name to `VisibleFunctions` in `.psrc`. No re-registration, no WinRM restart, existing sessions keep their old whitelist for their remaining lifetime but any new session picks up the change immediately. Confirmed for real in this project, not just claimed from documentation.
- **`.pssc` (the session configuration itself — session type, role definitions, transcript settings) needs `Register-PSSessionConfiguration -Force`, which restarts WinRM for that endpoint.** This only changes when something structural changes — a new role mapping, a session-type change — not on routine check additions.

Track this distinction explicitly in your deployment tooling (a version marker file works well, see §6) so the common case — adding one more check — never triggers a restart.

## 6. Deployment: one target now, GPO/SYSVOL later

**For a single pilot target**, this is simple enough to script end-to-end without touching a domain controller or SYSVOL at all — directory/file creation plus one registration command, run elevated directly on the target. Manual-first is the right starting point; automate distribution once there's more than one or two real targets.

### First-time deployment script

Builds the module, whitelist, and session configuration from nothing — run this once on a fresh target to get a working endpoint with the three representative checks from §3. Every check's own per-check doc (see the project's `WINRM_*_CHECK.md` docs) has its own "Deploying this check" script that assumes this has already run and adds one more function/whitelist entry on top — a genuinely incremental add, not a from-scratch rebuild each time.

```powershell
# Install-WinrmProbeJEA.ps1 -- first-time setup on a fresh target
if ($env:COMPUTERNAME -ne 'WINRM-TEST01') {
    throw "This script must run on WINRM-TEST01, not $env:COMPUTERNAME. Aborting."
}

$moduleRoot = 'C:\Program Files\WindowsPowerShell\Modules\WinrmProbeJEA'
$roleCapDir = Join-Path $moduleRoot 'RoleCapabilities'

New-Item -ItemType Directory -Path $moduleRoot -Force | Out-Null
New-Item -ItemType Directory -Path $roleCapDir -Force | Out-Null

@'
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

function Get-LocalDiskSpace {
    [CmdletBinding()]
    param()  # deliberately no parameters

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

function Get-ExampleOptionalData {
    [CmdletBinding()]
    param()  # deliberately no parameters

    $key = Get-ItemProperty -Path "HKLM:\SOFTWARE\YourProduct" -ErrorAction SilentlyContinue

    $contact = $null
    if ($key -and $key.Contact) {
        $contact = $key.Contact
    }

    [pscustomobject]@{
        Contact = $contact
    } | ConvertTo-Json -Compress
}

Export-ModuleMember -Function Get-RebootPendingStatus, Get-LocalDiskSpace, Get-ExampleOptionalData
'@ | Set-Content -Path (Join-Path $moduleRoot 'WinrmProbeJEA.psm1') -Encoding UTF8

@'
@{
    Author = 'Your Name'
    CompanyName = 'VPP'
    Description = 'Constrained check functions exposed to the WinRM proxy service account.'

    VisibleFunctions = @('Get-RebootPendingStatus', 'Get-LocalDiskSpace', 'Get-ExampleOptionalData')
    VisibleCmdlets = @()
    VisibleAliases = @()
    VisibleExternalCommands = @()
    VisibleProviders = @()
}
'@ | Set-Content -Path (Join-Path $roleCapDir 'WinrmProbe.psrc') -Encoding UTF8

New-PSSessionConfigurationFile -Path (Join-Path $moduleRoot 'WinrmProbe.pssc') `
    -SessionType RestrictedRemoteServer `
    -RunAsVirtualAccount `
    -RoleDefinitions @{ 'VPP\svc-winrmproxy' = @{ RoleCapabilities = 'WinrmProbe' } } `
    -TranscriptDirectory 'C:\ProgramData\WinrmProbeJEA\Transcripts'

Register-PSSessionConfiguration -Path (Join-Path $moduleRoot 'WinrmProbe.pssc') -Name 'WinrmProbe' -Force

Write-Host "Done. Validate with a NEW session:"
Write-Host "  Enter-PSSession -ComputerName $env:COMPUTERNAME -ConfigurationName WinrmProbe"
Write-Host "  Get-Command                  # expect Get-RebootPendingStatus, Get-LocalDiskSpace, Get-ExampleOptionalData"
Write-Host "  Get-RebootPendingStatus       # expect JSON output"
```

`Register-PSSessionConfiguration -Force` restarts WinRM for this endpoint — expected and fine for a first-time setup, but this is the operation §5 warned needs a version-gate once it moves into GPO (below), so it doesn't re-run on every policy refresh.

Then run the §7 validation checklist before trusting the endpoint.

### Scaling to GPO/SYSVOL

**Once distribution needs to scale past a handful of manually-managed hosts**, GPO/SYSVOL is a natural fit — split into the same two independent mechanisms as §5, so a routine check-addition never triggers a WinRM restart via GPO either:

**A — File copy (every policy refresh, no side effects):** GPO → Computer Configuration → Preferences → Windows Settings → Files, source `\\<yourdomain>\SYSVOL\<yourdomain>\scripts\WinrmProbeJEA\`, target `C:\Program Files\WindowsPowerShell\Modules\WinrmProbeJEA\`, action **Replace**. Sufficient on its own for any `.psrc`-only change — the next session on each target picks it up automatically.

**B — Registration (only when `.pssc` structurally changes):** a GPO startup script, gated by a version marker so it doesn't re-run `-Force` on every policy cycle:

```powershell
# Deploy-WinrmProbeJEA.ps1 -- GPO computer startup script
$sysvolVersionFile = '\\<yourdomain>\SYSVOL\<yourdomain>\scripts\WinrmProbeJEA\.pssc-version'
$localMarker        = 'C:\ProgramData\WinrmProbeJEA\.pssc-version'

$moduleVersion = Get-Content $sysvolVersionFile -ErrorAction SilentlyContinue
$currentLocal  = if (Test-Path $localMarker) { Get-Content $localMarker } else { '' }

if ($moduleVersion -and ($moduleVersion -ne $currentLocal)) {
    Register-PSSessionConfiguration `
        -Path 'C:\Program Files\WindowsPowerShell\Modules\WinrmProbeJEA\WinrmProbe.pssc' `
        -Name 'WinrmProbe' `
        -Force

    New-Item -ItemType Directory -Path (Split-Path $localMarker) -Force | Out-Null
    Set-Content -Path $localMarker -Value $moduleVersion
}
```
Bump `.pssc-version` only when the session config structurally changes — never on a routine function addition.

**Scoping which hosts get the GPO**, once it's in play: a security group scoped via GPO Security Filtering (remove `Authenticated Users`, add a dedicated group like `GG-WinRM-JEA-Targets`) is lower-friction than moving computer objects between OUs, especially during a pilot — adding or removing a target becomes a group-membership change, not an object move, and the same approach scales cleanly from a two-host pilot to a full rollout. The specific OU/GPO/group-naming structure is yours to design around your existing AD conventions; nothing about this module requires a particular shape.

## 7. Validating the endpoint is actually constrained

The proof isn't that the whitelisted function works — it's that **everything else is absent**:

```powershell
Enter-PSSession -ComputerName winrm-test01.vpp.local -ConfigurationName WinrmProbe

Get-Command
# Expected: ONLY the whitelisted function(s) listed

Get-RebootPendingStatus
# Expected: works, returns the JSON string

Get-ItemProperty -Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion'
# Expected: FAILS -- not visible in this session. This is the actual proof
# the constraint holds, not just that the allowed path works.

Get-Process
# Expected: FAILS -- confirms no built-in cmdlets leaked through
```

Run this on every pilot target before considering a rollout ready, and again after any `.pssc` structural change (session type, role definitions) — those are the changes most likely to accidentally widen the surface.

## 8. Lessons worth not re-learning

Real findings from building this project's own checks, generalized past this specific deployment:

**Per-target deployment scripts should verify their own target, not trust the operator to be on the right box.** Hit for real once: an update script intended for a specific JEA target got run on a different domain-joined host instead (a domain controller, in the same admin-session window as an unrelated task) — harmless only by luck, since the expected module directory happened not to exist there, causing a loud failure rather than a silent wrong-host misconfiguration. A one-line guard at the top of any such script closes this for good, turning "fails quietly/incidentally if you're on the wrong host" into "fails loudly and immediately, always":
```powershell
if ($env:COMPUTERNAME -ne 'WINRM-TEST01') {
    throw "This script must run on WINRM-TEST01, not $env:COMPUTERNAME. Aborting."
}
```

**`ConvertTo-Json` on a bare enum value serializes it as its underlying integer, not its name.** `(Get-Service -Name X).Status` is a `ServiceControllerStatus` enum; piping it straight into `ConvertTo-Json -Compress` produces `1`/`4` — semantically correct, but very likely the wrong wire shape for whatever's parsing the JSON on the other end, which almost certainly expects a string name like `Running`/`Stopped`. Fix: call `.ToString()` before the pipe, forcing the string name. Generalizes to any PowerShell enum value going into a JEA function's JSON output — don't assume `ConvertTo-Json` will pick the "obviously more useful" representation.

**`Get-CimInstance` is unreachable inside a `RestrictedRemoteServer` session; `Get-WmiObject` isn't — this is a module-autoloading effect, not a JEA-authorization difference.** `RestrictedRemoteServer` disables module auto-loading, so only cmdlets from modules already imported at session startup exist *at all* in that runspace — for anyone, including inside an already-whitelisted function's own body. `Get-ItemProperty`/`Get-Service`/`New-Object` all work because they live in `Microsoft.PowerShell.Management`/`Microsoft.PowerShell.Utility`, modules PowerShell always has loaded. `Get-CimInstance` lives in `CimCmdlets`, a separate module normally pulled in by auto-loading on first use — exactly what JEA disables — so calling it fails with `The term 'Get-CimInstance' is not recognized`, a genuine "this cmdlet doesn't exist here" error, not an authorization rejection. `Get-WmiObject` lives in that same always-loaded core module and, for classic CIMV2 WMI classes, queries identical data — so it works with no `.pssc` change needed (the alternative, adding `ModulesToImport = @('CimCmdlets')` to the `.pssc`, is a structural change requiring the restart described in §5). `Get-WmiObject` is legacy (removed in PowerShell 7+/Core) but fully present on Windows PowerShell 5.1, which is what most currently-supported Windows Server releases actually run under WinRM by default — confirm your own target's PowerShell version before assuming this applies, but it's the right default to reach for first. **Generalizes: any JEA function needing WMI/CIM-backed data should default to `Get-WmiObject`, falling back to explicit `ModulesToImport` only if something genuinely needs `CimCmdlets` specifically** (and accepting the registration-restart cost that comes with it).

**`ConvertTo-Json` collapses a single-element array to a bare object — build the array explicitly and pass it via `-InputObject`, not a pipe.** Something that looks correct against a multi-instance test host (multiple disks, multiple network adapters) can silently produce the wrong JSON shape against a single-instance one — an array-typed field becomes a bare object instead, breaking any consumer expecting a JSON array unconditionally. `-AsArray` (PowerShell 6.0+) isn't available on Windows PowerShell 5.1, so it's not a fix if that's your target. What works on 5.1: build the collection explicitly with `@(...)`, then bind it via `ConvertTo-Json -InputObject $collection`, not `$collection | ConvertTo-Json`. Piping still unrolls the array back to individual objects regardless of how it was constructed — binding via `-InputObject` is what makes `ConvertTo-Json` see the whole collection as a single argument and correctly serialize it as a JSON array for 0, 1, or many elements alike.
