# LibreNMS WinRM Windows Monitoring — Architecture

**Contents** (explicit HTML anchors, not relying on any particular renderer's heading-slug behavior — several headers below have punctuation/symbols that slug inconsistently):
- [Status](#status)
- [Problem](#problem)
- [Architecture](#architecture)
- [Module](#module)
- [Development convention: match the SNMP-based equivalent's shape](#dev-convention)
- [Poller ↔ Proxy protocol](#poller-proxy-protocol)
- [Proxy → Windows host (Kerberos + JEA)](#proxy-windows-host)
- [Kerberos credential aging & rotation](#kerberos-credential-rotation)
- [IPv6 readiness](#ipv6-readiness)
- [Service-status check — lessons from building a second check](#service-status-lessons)
- [Provisioning and proxy source](#provisioning-proxy-source)
- [VM plan](#vm-plan)
- [Firewall requirements](#firewall-requirements)
- [Explicitly out of scope for v1](#out-of-scope-v1)
- [Implementation status & operational notes](#implementation-status)
- [Next step](#next-step)

<a id="status"></a>

## Status

**Phase A implemented, tested, and running** (2026-08-08) — everything not requiring a real Windows JEA target. See "Implementation status & operational notes" below for what's actually built/verified vs. still blocked. Not yet public: nothing has been posted to `librenms/librenms` (no discussion/issue/PR) — deliberately deferred until there's more than Phase A to show.

<a id="problem"></a>

## Problem
WMI is being deprecated. LibreNMS has no native PowerShell/WinRM capability — Windows monitoring is SNMP-only. This adds a clean way to run constrained PowerShell checks against Windows servers from LibreNMS.

<a id="architecture"></a>

## Architecture

```
LibreNMS poller
     │  HTTPS (token or mTLS)
     ▼
WinRM proxy daemon (Python/pypsrp, containerized -- see "Containerization" below)
     │  WinRM/HTTPS, Kerberos
     ▼
Target's own local JEA endpoint (per-host, not centralized)
     │  constrained PSSession
     ▼
Get-ItemProperty (reboot-pending registry keys)
```

The proxy is an **authenticated relay only**. It never holds broad execution rights — the actual security boundary is the JEA endpoint on each target host.

<a id="module"></a>

## Module

- `LibreNMS/Modules/WinrmPoller.php`, implementing the `Module` interface — modern class-based pattern, same as `Nac.php`/`Xdsl.php` (not `NtpProbe` — that class doesn't actually exist in this codebase, an earlier draft of this doc assumed it did without checking).
- Dev repo: `alexh/librenms-fork` on `git.vpp.local`
- Branch: `feature/winrm-poller-claude` (may fold to `feature/winrm-poller` at PR-prep time)
- First check: reboot-pending detection via:
  - `HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired`
  - `HKLM\SYSTEM\CurrentControlSet\Control\Session Manager\PendingFileRenameOperations`
- Result storage: the `sensors` table (`sensor_class = 'state'`, `poller_type = 'winrm'`), not a device-attrib/bespoke-RRD approach — chosen deliberately for native Alert Rule builder / Health-Sensors UI integration. See "Implementation status" for what that cost.

<a id="dev-convention"></a>

## Development convention: match the SNMP-based equivalent's shape

**Before designing the `WinrmPoller.php` side of any new check, find and read the SNMP-based equivalent's real code first — don't default to a WinRM-specific pattern just because it's easier to bolt on.**

For any new WinRM check, before writing `discover()`/`poll()` logic:
1. Identify what SNMP-based mechanism (if any) already monitors the analogous data on a real device — a specific model (`Storage`, `Mempool`, `Processor`, `Port`, `EntPhysical`), a specific `LibreNMS\Modules\*` class, a specific overview panel.
2. Read that mechanism's real discover/poll/alerting code directly — not assumed from the model name alone. `App\Models\Processor`'s real machinery turned out to be a legacy non-Eloquent path (`LibreNMS\Device\Processor`, `LibreNMS\Model::sync()`) very different from `Storage`/`Mempool`'s modern class-based `Module` pattern, only discovered by actually reading the source before building `cpu-usage`.
3. Match its shape unless there's a concrete, checked reason it doesn't fit — e.g. `entPhysicalIndex` being a bare SNMP-index concept (no type/namespace column, unlike `Storage`/`Mempool`) was a real, checked concern weighed before committing to `EntPhysical` for `hardware-inventory`, not a blanket assumption it wouldn't fit.
4. Only fall back to a WinRM-specific pattern (bespoke `Sensor` rows, a new table, `dump()`-only visibility) when the SNMP equivalent genuinely doesn't apply — and say so explicitly in that check's own doc, the way `hardware-inventory`'s doc names why `EntPhysical` was chosen over the alternatives it considered.

**Why this is a standing rule, not just a per-check reminder:** `network-traffic` was originally built with both a `Port` row (correct — matches how SNMP interfaces work) *and* a `Sensor` pair, added specifically for "Health-tab visibility" and "sensor-limits alerting". That `Sensor` pair had to be ripped out later (2026-08-10, see `WINRM_NETWORK_TRAFFIC_CHECK.md`'s "Sensor pair removed" section) once the SNMP precedent was actually checked: no SNMP-based device shows interface traffic via a `Sensor` entry at all, and `resources/definitions/alert_rules.json` ships real, default, `Port`-table-native alert rules (`"Port status up/down"`, `"Port utilisation over threshold"`) that already cover exactly what the `Sensor` pair was supposedly needed for — a real rework cost that checking the SNMP precedent *first* would have avoided.

<a id="poller-proxy-protocol"></a>

## Poller ↔ Proxy protocol

- JSON only: `{host, check_name}` — **never** free-form script. Proxy validates `check_name` against a whitelist client-side before doing anything.
- This is a real network hop (proxy runs in its own container), not localhost — needs its own auth.

### Auth modes (selectable per proxy entry via `auth` field)

**TOKEN mode:**
- Static pre-shared bearer token, opaque random 32+ bytes, config on both sides, constant-time compare
- No JWT/OAuth/expiry — disproportionate for a small fixed set of internal callers
- Proxy's TLS server cert pinned by file path on the poller side (`tls_verify` => path to cert) — **never** skip-verify; unverified TLS lets anyone on-path impersonate the proxy and harvest the token

**MTLS mode:**
- `client_cert`/`client_key` on poller, proxy validates the client cert
- CA pinning by **SHA-256 fingerprint** of the CA cert, not file-path/Issuer-string trust — `remote_ca`+`remote_ca_fingerprint` on the poller side, `allowed_client_ca`+`allowed_client_ca_fingerprint` on the proxy side. A swapped file at the same path must fail validation, not silently be trusted, **on both sides of the connection** — the poller-side fingerprint check was initially missed (only the proxy side was built at first) and caught in a later security self-review; both are now symmetric.
- ~~`remote_san` (expected SAN of peer cert), independently settable to a real value or `-` to skip~~ **Dropped during implementation.** Neither Guzzle/Laravel (poller side) nor uvicorn (proxy side) has a primitive for pinning a SAN independent of hostname-based verification — implementing it for real would mean custom post-handshake certificate inspection, disproportionate for a v1 with one proxy and one poller once CA-fingerprint pinning already closes the threat model it was meant to cover (a cert issued by a legitimately-trusted-but-wrong-purpose CA). mTLS enforcement is instead delegated entirely to the TLS layer itself: the proxy's server (uvicorn) is configured with `ssl_cert_reqs=CERT_REQUIRED` + `ssl_ca_certs` pinned to the fingerprint-verified CA — a connection presenting no cert, or one not signed by that CA, never completes its handshake, confirmed with real certs over real sockets (see "Implementation status").
- The `insecure_dev_mode` gate this implies (encrypted-but-unauthenticated if `allowed_client_ca` is unset) is **code-enforced**, not just documented: the proxy refuses to start in `mtls` mode with no CA configured unless `insecure_dev_mode: true` is explicitly set.

### Example config

```php
$config['winrm']['proxies'] = [
    'default' => [
        'url'  => 'https://winrm-proxy.vpp.local:8443',
        'auth' => 'token', // or 'mtls'

        // token mode:
        'token' => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'tls_verify' => '/opt/librenms/.winrm/proxy-cert.pem',

        // mtls mode:
        // 'client_cert' => '/opt/librenms/.winrm/lnms.crt',
        // 'client_key'  => '/opt/librenms/.winrm/lnms.key',
        // 'remote_ca'   => '/opt/librenms/.winrm/proxy-ca.crt',
        // 'remote_ca_fingerprint' => 'aa:bb:...',  // SHA-256, `openssl x509 -in proxy-ca.crt -noout -fingerprint -sha256`
    ],
    // optional overrides, keyed arbitrarily, assigned per device_group — NOT site-based
    // 'proxy-b' => [ ... ],
];
```

Generalized on purpose: supports single-proxy SME deployments and larger deployments (e.g. a known 100-site target likely running only 1-2 proxies) on the same schema — no rearchitecture needed to shard later.

**HA/failover explicitly deferred to v2.**

<a id="proxy-windows-host"></a>

## Proxy → Windows host (Kerberos + JEA)

**Revised during implementation — no full AD domain-join needed.** The proxy only needs a Kerberos ticket to authenticate outbound WinRM calls: `krb5.conf` pointing at the realm/KDC, a keytab for a **plain service account** (not a computer account — no machine trust relationship), and `kinit -kt` at startup. `realmd`/`sssd-ad`/`adcli` exist for NSS/PAM interactive-login resolution, which this daemon never does. Full detail and rationale in `CONTAINERIZATION.md` (repo location below).

- Service account is a **plain domain user** — no local admin, no special AD group membership. Access is gated purely by the JEA endpoint's security descriptor (least-privilege).
- No persistent machine identity is tied to the proxy (no computer account) — it can be rebuilt/recreated/moved freely without any AD-side cleanup.

### JEA specifics

- **Per-host, not centralized** — every monitored Windows server gets its own constrained `PSSessionConfiguration`. The proxy relays; it never itself holds broad rights.
- Two artifacts per host:
  1. **Role Capability file (`.psrc`)** — the actual command whitelist (e.g. `Get-ItemProperty` scoped to the two reboot-pending registry paths). Changes every time a check is added. PowerShell reads it fresh per session — **hot-reloads**, no re-registration or WinRM restart needed.
  2. **Session Configuration (`.pssc`)** — registers the endpoint itself (name/run-as/security descriptor). Rarely changes. Requires `Register-PSSessionConfiguration -Force`, which **does** restart WinRM for that endpoint — gate behind a version-check so routine `.psrc`-only pushes don't trigger it.
- **Distribution: GPO + SYSVOL**, not Ansible. Ansible is pull/one-shot — wrong fit for pushing whitelist updates to every domain-joined host over time. GPO reaches all of them via normal policy refresh, using SYSVOL replication that already exists across current DCs (e.g. `dc1`) — no new DC, no new domain needed.
- Module folder (`WinrmProbeJEA\RoleCapabilities\WinrmProbe.psrc`) staged in SYSVOL; GPO copies it to `C:\Program Files\WindowsPowerShell\Modules\` on target hosts.
- **Version floor:** JEA needs PowerShell 5.0+/WMF 5.0+ — native on Server 2016+, Server 2019, Windows 10 1607+. Fleet is standardized on Server 2019+ only (no 2016), so fully compatible with no upgrade steps.

<a id="kerberos-credential-rotation"></a>

## Kerberos credential aging & rotation (2026-08-09)

**Ticket lifetime: already handled correctly.** `entrypoint.sh` re-`kinit`s on a 30-minute background loop, comfortably inside the typical ~10-hour AD Kerberos ticket lifetime. This was an open item in the original containerization doc; confirmed resolved by reading the actual shipped script, not assumed.

**What actually can age out — the keytab/password coupling.** `svc-winrmproxy` has `-PasswordNeverExpires $true`, so nothing expires on a schedule. The real risk: **the keytab file and the AD account's password must always change together, and nothing enforces that.** Every AD account has a KVNO (key version number) that increments on every password change; the keytab has a specific KVNO baked in at generation time. If the account's password is ever reset through anything other than re-running `ktpass` — a GUI reset in ADUC, a bulk service-account rotation compliance script, `Set-ADAccountPassword` — the AD-side KVNO moves forward, the keytab's doesn't, and Kerberos auth starts failing (`KRB_AP_ERR_MODIFIED` or similar) with no advance warning. It's a hard failure, not a slow drift. Operational discipline for this (exclude from rotation sweeps, rotate only via `ktpass` + redeploy) is documented in `WINRM_JEA_WINDOWS.md` §0.

### The structural fix: gMSA — deliberately not built yet

A **gMSA** (Group Managed Service Account) solves the coupling problem structurally: AD auto-rotates the password on a schedule (default 30 days, via `msDS-ManagedPasswordInterval`), and nothing needs manual re-`ktpass`-ing ever again.

**Retrieval is not literally Windows-only at the protocol level, though Microsoft only ships tooling for Windows.** The current (and previous, for graceful rollover) password lives in a constructed LDAP attribute, `msDS-ManagedPassword`, gated by the `PrincipalsAllowedToRetrieveManagedPassword` ACL — an ACL, not a Windows-specific mechanism. Any Kerberos-authenticated LDAP client with read access, including a Linux client, can request it in principle; this is how community tools (e.g. `gMSADumper.py`, built for security-assessment use) retrieve gMSA passwords from Linux today. Not Microsoft-supported, but not cryptographically Windows-only either. The value is a binary structure (`MSDS-MANAGEDPASSWORD_BLOB`, MS-ADTS §2.2.20) — versioning fields plus current/previous password as UTF-16LE strings.

**If this ever gets built:**
1. Authenticate to AD over LDAPS with an existing Kerberos identity — ideally a **separate, narrowly-scoped identity** whose only right is reading this one attribute on this one gMSA object (not reusing `svc-winrmproxy` itself), per least-privilege.
2. Read `msDS-ManagedPassword`, parse per MS-ADTS §2.2.20, extract the current password (UTF-16LE decode, no cryptographic derivation needed at this step).
3. **Use the raw password directly with password-based `kinit`, not keytab-based.** Building a valid keytab from the raw password would mean reimplementing AD's exact salt/string2key derivation to match what AD itself computed — real room for subtle bugs. Password-based `kinit` sidesteps that entirely; it does the AS-REQ pre-auth itself.
4. Re-fetch on a schedule well inside the rotation window (e.g. daily, against a 30-day rotation) — same "don't rely on a single fetch" principle already applied to ticket renewal.

**The real tradeoff.** This is the same retrieval technique used for gMSA credential theft when the ACL is too permissive. Building this bridge makes `PrincipalsAllowedToRetrieveManagedPassword` the *entire* security boundary for a live, self-rotating credential — arguably more sensitive than today's static keytab file, since compromising the reading identity gives an attacker standing, automatically-current access rather than a snapshot that goes stale. It also means writing and trusting custom binary-parsing code for a security-sensitive structure — worth adapting a well-reviewed existing implementation (e.g. referencing `gMSADumper`'s parsing logic) rather than a from-scratch blob decoder.

**Decision: not building this now.** Static-keytab-plus-documented-KVNO-discipline is proportionate at current scale (one proxy, one service account; manual rotation is rare and now documented). Revisit if either (a) compliance requires provably-automatic credential rotation, or (b) this scales to enough proxies/domains that manual `ktpass` rotation becomes a real operational burden rather than a rare event. Deliberately deferred, not a silent gap.

<a id="ipv6-readiness"></a>

## IPv6 readiness (code-level, 2026-08-09)

Not "build full IPv6 test coverage now" — everything here is IPv4-only today (Phase B testing is entirely on `192.0.2.0/26`). This is three cheap-to-fix-now code items, kept correct from the start rather than retrofitted later:

1. **`CheckRequest.host` validation, when built.** No format validation exists on `host` yet, and it's still fine to defer building it — but when it is built, it needs to accept IPv4, IPv6 literals, and hostnames from the first version, not an IPv4-shaped regex retrofitted later.
2. **Docker networking.** Docker's default bridge network does not enable IPv6 unless explicitly configured (`enable_ipv6: true` + an IPv6 subnet in `docker-compose.yml`). If the proxy's subnet ever goes dual-stack, this needs turning on explicitly — confirm current state when that happens, don't assume it's already there.
3. **WSMan URL construction for IPv6 literals.** If `host` is ever a literal IPv6 address, WSMan URLs need bracket notation (`http://[<addr>]:5985/wsman`). Confirm `pypsrp` handles this correctly rather than assuming, when it's actually needed — same "test before trusting" standard as the pypsrp-vs-pywinrm JEA finding earlier in this project.

**Real addressing decisions for the dev/test subnet** (VLAN allocation, ULA vs. GUA choice, DNS/firewall open items) are site-specific internal network detail and documented in `infra-bits:librenms-winrm-stack/WINRM_IPV6_NOTES.md` instead of here, same reasoning as `WINRM_JEA_WINDOWS.md` staying out of this public-eligible repo.

<a id="service-status-lessons"></a>

## Service-status check — lessons from building a second check (2026-08-09)

Added `service-status-wuauserv`/`service-status-w32time` (proxy: `alexh/librenms-bits:winrm-proxy`; JEA build: `infra-bits:librenms-winrm-stack/WINRM_JEA_WINDOWS.md`, kept private for site-specific detail) — the second real check after `reboot-pending`, and the first real test of a couple of things this doc had only claimed until now:

**`ConvertTo-Json` on a bare enum serializes as its integer, not its name.** `(Get-Service -Name X).Status` is a `ServiceControllerStatus` enum; piping it straight to `ConvertTo-Json -Compress` produced `1`/`4` (semantically correct — those are real enum values — but the wrong wire shape for a check whose proxy-side parser expects a JSON string). Fix: `.Status.ToString()` before the pipe, forcing the string name. Generalizes beyond this one check: **any PowerShell enum value going into a JEA check function's JSON output needs an explicit `.ToString()`** — don't assume `ConvertTo-Json` picks the "obviously more useful" representation.

**Per-target deployment/update scripts should verify their own target, not trust the operator to be on the right box.** Hit for real: an update script intended for the JEA target got run on a different domain-joined host instead (harmless here — the script failed on a missing directory rather than silently misconfiguring the wrong machine, but that was luck, not design). A one-line guard at the top of any such script closes this for good:
```powershell
if ($env:COMPUTERNAME -ne $expectedComputerName) {
    throw "This script must run on $expectedComputerName, not $env:COMPUTERNAME. Aborting."
}
```
Cheap, and turns "fails quietly/incidentally if you're on the wrong host" into "fails loudly and immediately, always."

**Hot-reload confirmed for real, not just claimed.** This doc has said since its first version that `.psrc`/`.psm1`-only changes hot-reload with no `Register-PSSessionConfiguration -Force` and no WinRM restart — that had never actually been tested until adding these two checks to an already-deployed target. Confirmed: new functions were reachable in a fresh session with zero registration/restart step.

<a id="provisioning-proxy-source"></a>

## Provisioning and proxy source (kept OUT of the upstream PR — core docs link out, same precedent as `reboot-required`)

**Repo split, 2026-08-08:** the proxy daemon moved out of `vpp/infra-bits` into its own repo, `alexh/librenms-bits` (`winrm-proxy/`, branch `public/winrm-proxy`) on `git.vpp.local`, with a matching public destination `github.com/ahoogerhuis/librenms-bits` planned for once this is ready to go public. Reason: unlike the rest of `infra-bits` (deployment-specific Ansible/ops tooling, correctly always private), the proxy is a **required runtime dependency of `WinrmPoller.php`** — the eventual upstream PR can't ask other LibreNMS users to depend on a component they have no way to get, so it needs to live somewhere that's allowed to go public. History preserved via `git subtree split`/`git subtree add`, not a flat copy — the commit trail (including the security self-review fix commits) is intact in the new repo.

**Stays in `vpp/infra-bits` under `librenms-winrm-stack`, unchanged:** the Ansible playbooks (still a TODO, not yet written) and `WINRM_JEA_WINDOWS.md` (the Windows-side JEA/GPO build doc) — both have real internal details (AD domain/OU/DC references, deployment-specific inventory) that don't belong in a public repo, same category as the rest of `infra-bits`.

1. **AD-side, one-time:** create the service account, generate a keytab for it (`ktpass` on a DC, or `net ads keytab` from a domain-joined Linux box). No `realm join`, no computer account.
2. **Proxy deployment: build image, deploy via `docker-compose`.** Container built and pushed to `git.vpp.local/vpp/librenms-winrm-proxy` (Forgejo's container registry, unchanged by the repo split — no reason for the registry path to match the source repo's name/location) — see "Implementation status" below.

<a id="vm-plan"></a>

## VM plan

- **Dev/prototyping proxy (now):** `winrm-proxy.vpp.local` — runs the containerized proxy via `docker-compose` (Docker installed 2026-08-08). Not domain-joined — doesn't need to be, per the revised Kerberos-only approach above.
- **Production (later):** central proxy is the default topology, no per-site VM sprawl. With containerization, "one compose service per domain" replaces "one VM per domain" for the eventual multi-domain (v2) case — see `CONTAINERIZATION.md`.
- **Test topology, JEA target:** provisioned and validated end-to-end (Phase B complete, see "Implementation status" below) — a Windows Server 2019 VM, plain AD member (not DC), in a scoped OU with a security-filtered GPO applying only there, on an isolated test LAN. Site-specific network detail (firewall, VLAN, addressing) is intentionally kept out of this public-eligible doc — see `infra-bits:librenms-winrm-stack` for that.

<a id="firewall-requirements"></a>

## Firewall requirements (to document in the eventual PR)

| From | To | Port | Purpose |
|---|---|---|---|
| Proxy | DC | 88 tcp+udp | Kerberos |
| Proxy | DC | 53 | DNS (KDC SRV record discovery) |
| Proxy | Windows hosts | 5985 tcp | WinRM/HTTP — confirmed correct against a real target, see note below |
| Poller(s) | Proxy | 8443 (or chosen) | HTTPS, token/mTLS |

~~Proxy → DC 464 (kpasswd), 389/636 (LDAP/S for sssd-ad)~~ — dropped along with the full domain-join requirement; a plain keytab + `kinit` needs neither.

**5985 (HTTP), not 5986 (HTTPS)**, confirmed by real end-to-end testing against `winrm-test01.vpp.local` (2026-08-09) — the WinRM listener there is plain HTTP; Kerberos encrypts the whole WSMan payload independent of TLS, so an HTTP-transport listener is not a plaintext-on-the-wire concern here the way it would be for password auth. Don't open 5986 unless a target's WinRM listener is specifically configured for HTTPS. Full mechanism (GSS-API message encryption, verified against `pypsrp`'s actual source) in `docs/WINRM_TRANSPORT_SECURITY.md` — worth reading before flagging this as a missing-TLS gap.

**ICMP is not required and is not opened by default** — Windows Firewall blocks ICMPv4 Echo by default, and `WinrmPoller::isApplicable()` deliberately ignores `ConnectivityHelper`/ping status (ping and Kerberos/WinRM reachability are unrelated). A `winrm-poller`-only device (`snmp_disable`, no ICMP) will correctly discover/poll/populate its sensor and RRD while LibreNMS's CLI tools print `Device was down, unable to poll` / `unable to discover` — that message refers only to the generic ping-based liveness check, not this module, and can be ignored. The practical downside: LibreNMS's dashboard/generic down-alerting also uses that same ping-based status, so a `winrm-poller`-only device will show as persistently "down" there even though it's being monitored correctly.

**Troubleshooting note (2026-08-09):** during the first real end-to-end test against `winrm-test01.vpp.local`, `device:discover`/`device:poll` both printed `Device was down` (the generic ping-status message above) because Windows Firewall was blocking ICMP by default — confirmed by a 100% packet-loss ping from `lnms-poller.vpp.local`. To rule this out as a variable while validating the WinRM/JEA path itself, the user disabled Windows Firewall on the target entirely, temporarily. The WinRM/JEA path worked identically with the firewall on or off (as expected — `winrm-poller` doesn't touch ping), confirming ICMP was never actually blocking anything real. **The firewall should be re-enabled afterward with scoped rules, not left disabled:**

```powershell
# Re-enable the firewall (all profiles)
Set-NetFirewallProfile -All -Enabled True

# Allow WinRM (5985/tcp) inbound, scoped to just the proxy host
New-NetFirewallRule -DisplayName "WinRM Proxy (5985 from winrm-proxy)" `
    -Direction Inbound -Protocol TCP -LocalPort 5985 `
    -RemoteAddress 192.0.2.20 -Action Allow

# Optional: ICMPv4 Echo Request inbound, scoped to the LibreNMS poller
# host -- only needed so the dashboard/generic ping-based status shows
# this device as "up"; winrm-poller itself doesn't use this at all.
New-NetFirewallRule -DisplayName "ICMPv4 Echo from lnms-poller.vpp.local" `
    -Direction Inbound -Protocol ICMPv4 -IcmpType 8 `
    -RemoteAddress 192.0.2.50 -Action Allow
```

Both rules are scoped by `-RemoteAddress` to the specific host that needs them, rather than opening the built-in "File and Printer Sharing (Echo Request - ICMPv4-In)" rule (which allows ICMP from any source) — consistent with this project's existing least-privilege pattern (CA-fingerprint pinning, JEA function-name whitelisting). `192.0.2.20`/`192.0.2.50` are `winrm-proxy`/`lnms-poller.vpp.local`'s addresses as of the 2026-08 subnet move (`192.0.2.0/26`) — reconfirm if either host's IP changes.

<a id="out-of-scope-v1"></a>

## Explicitly out of scope for v1

- HA/failover (deferred to v2)
- Per-site proxy sharding (schema supports it, not needed yet)
- Ansible playbooks in the upstream PR (docs link out instead)

<a id="implementation-status"></a>

## Implementation status & operational notes (2026-08-08)

**Built and verified for real** (not just unit-tested — see individual commit messages on both branches for full detail):
- Proxy daemon (`alexh/librenms-bits:winrm-proxy`, `public/winrm-proxy` — moved from `infra-bits:librenms-winrm-stack/proxy` 2026-08-08, see "Provisioning and proxy source" above): auth (token + mTLS), JSON protocol/whitelist enforcement, the `reboot-pending` check. 31 pytest tests. Real mTLS enforcement confirmed over actual TLS sockets (no-cert rejected, right-CA accepted, wrong-CA rejected).
- `WinrmPoller.php` + `WinrmProxy`/`WinrmCheckResult` API client (`librenms-fork`, `feature/winrm-poller-claude`): 13 tests against a real DB-backed install, phpstan clean.
- Containerized: `git.vpp.local/vpp/librenms-winrm-proxy:latest`, running on `winrm-proxy` via `docker-compose`, confirmed as a drop-in replacement for the earlier bare-metal deployment.

**Two real bugs found only by actually running `discover()`/`poll()` end-to-end** (neither a unit test would have caught):
1. Sensor discovery only stages sensors in memory (`App\Discovery\Sensor::discover()` just pushes to a collection) — persisting requires an explicit `app('sensor-discovery')->sync(sensor_class:, poller_type:)` call, which the first version of `WinrmPoller::discover()` was missing.
2. `record_sensor_data()`'s state-change-logging branch calls the legacy `dbFetchRows()`, which needs `includes/dbFacile.php` loaded. `LegacyModule::poll()` guarantees this for old-style `.inc.php` pollers; the framework gives no such guarantee to a modern class-based `Module`. Crashed (silently — exception swallowed by the poller framework) on every genuine sensor value transition until `poll()` explicitly `require_once`s that file.

**Two more real issues found in a later security self-review** (structured review process: candidate findings → independent false-positive filtering pass → act only on what survives ≥8/10 confidence — plus a second, independent review that caught what the first missed):
1. **Fixed:** the containerized proxy's deployed `secrets/` directory (real TLS private key, real bearer token) was `chmod 644` on the Docker host — world-readable to any local user, not just the container's own process. The documented "fix" for a `docker-compose` secret-permissions quirk was itself wrong. Corrected to `chown`-to-container-uid + `600`/`640`, with the daemon's UID now pinned explicitly in the Dockerfile (was previously left to automatic allocation, not guaranteed stable across rebuilds). Full detail in `CONTAINERIZATION.md` (moved with the proxy, now at `alexh/librenms-bits:winrm-proxy/CONTAINERIZATION.md`).
2. **Fixed:** mTLS server-cert validation was asymmetric — the proxy fingerprint-pinned the poller's client CA (`config.py`), but the poller's validation of the *proxy's* server CA (`remote_ca`) was only standard chain validation, no fingerprint check of that CA file's own content. Added `remote_ca_fingerprint` (mirrors `allowed_client_ca_fingerprint` on the proxy side) and a `verifyRemoteCaFingerprint()` check in `WinrmProxy.php`, using PHP's `openssl_x509_fingerprint()` — self-contained in this module, no changes needed to the shared `LibreNMS\Util\Http` wrapper.

**Enabling this module on a LibreNMS instance — two config keys, not one, easy to miss:**
```bash
./lnms config:set poller_modules.winrm-poller true --ignore-checks
./lnms config:set discovery_modules.winrm-poller true --ignore-checks
```
Both are required — they're separate `ModuleList` namespaces (poller vs. discovery), and `config:set`'s own built-in validation doesn't recognize a brand-new module name yet (hence `--ignore-checks`), independent of whether the module actually works at runtime.

**Setting up a test device** (works without a real Windows host — `snmp_disable`-capable devices are a real, supported LibreNMS device type):
```bash
./lnms device:add <any-pingable-hostname> --ping-only --os=windows -d "WinRM Test Device"
./lnms device:discover <id> -v   # creates the sensor row -- requires discovery_modules key above
./lnms device:poll <id> -m winrm-poller -v
```
The proxy's `--executor stub` mode ignores whatever hostname is actually sent, so any real, pingable host works as the test device target — it doesn't need to run Windows or WinRM at all while testing against the stub.

**Phase B started (2026-08-08):** the Windows test target (`winrm-test01.vpp.local`) exists now. Real executor implemented as `winrm_executor_pypsrp.py`, not `winrm_executor_pywinrm.py` as originally planned — `pywinrm` turned out unable to target a named JEA `PSSessionConfiguration` at all (confirmed against its actual source: it hardcodes the generic WinRS shell resource URI and has no real PSRP pipeline support), which would have silently bypassed JEA entirely. `pypsrp` (jborean93/pypsrp) implements genuine PSRP with `configuration_name` support, confirmed against its installed source. See `winrm_executor_pypsrp.py`'s docstring (now at `alexh/librenms-bits:winrm-proxy/app/`, see "Provisioning and proxy source" above) for the full rationale.

Also revised the JEA whitelist model: **whitelist by PowerShell function name with zero parameters**, not by cmdlet + `ValidateSet`-constrained parameters as this doc originally implied. A purpose-built function (`Get-RebootPendingStatus`) with the registry paths hardcoded in its body; `VisibleFunctions` lists only that name, `VisibleCmdlets = @()` — nothing built-in reachable at all, not even `Get-ItemProperty` itself. No parameter surface exists for a caller to manipulate. Generic Windows-side build guide (module, role capability, session configuration, GPO distribution, validation checklist — written for any adopter, no org-specific detail): `docs/WINRM_JEA_SETUP.md`. This deployment's own real AD/GPO record (real domain/OU/DC references, kept out of the public-eligible repos on purpose): `infra-bits:librenms-winrm-stack/WINRM_JEA_WINDOWS.md` (private).

Still needed before a real end-to-end test: the AD service account (`svc-winrmproxy`) + Kerberos keytab (not yet created), and JEA registration on `winrm-test01.vpp.local` itself (script ready in `WINRM_JEA_WINDOWS.md`, not yet run).

<a id="next-step"></a>

## Next step

~~Open a GitHub discussion on `librenms/librenms` before writing any implementation code.~~ **Superseded 2026-08-08:** nothing public on GitHub until the software is fully built and tested privately. Phase A now qualifies as "working software," but going public is still deferred pending explicit go-ahead — not automatic just because this phase is done.
