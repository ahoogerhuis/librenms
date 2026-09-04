# Why This WinRM Monitoring Approach Is Safe to Approve

**Contents**
- [For the skeptical Windows admin](#for-the-skeptical-windows-admin)
- [The short version](#the-short-version)
- [What the account can actually do — the complete list](#what-the-account-can-actually-do-the-complete-list)
- [What the account explicitly does NOT have](#what-the-account-explicitly-does-not-have)
- [How the enforcement actually works — not a policy, a mechanism](#how-the-enforcement-actually-works-not-a-policy-a-mechanism)
- [Per-server enforcement, but a fleet-wide credential — the honest blast-radius picture](#per-server-enforcement-but-a-fleet-wide-credential-the-honest-blast-radius-picture)
- [Read-only, provably — not a claim, a property of what's on the list](#read-only-provably-not-a-claim-a-property-of-whats-on-the-list)
- [Encrypted in transit, always](#encrypted-in-transit-always)
- [This account was deliberately kept narrower than convenience would suggest](#this-account-was-deliberately-kept-narrower-than-convenience-would-suggest)
- [What this replaces](#what-this-replaces)
- [Known limitations — stated plainly, not hidden](#known-limitations-stated-plainly-not-hidden)
- [If you still want to verify this yourself](#if-you-still-want-to-verify-this-yourself)

<a id="for-the-skeptical-windows-admin"></a>

## For the skeptical Windows admin

If your instinct on hearing "a Linux service account remotely runs PowerShell against my Windows servers" is to say no — that's the correct instinct to have about most things that ask for that. This document exists because that instinct deserves a real answer, not a request to trust us. Everything below describes an actual, specific mechanism, not a policy promise. You should be able to verify every claim here yourself, because the design was built around that requirement from the start: **anyone should be able to read one file and know the complete list of everything this can ever do.**

<a id="the-short-version"></a>

## The short version

This isn't "give a service account remote access to Windows servers." It's **Just Enough Administration (JEA)** — a Microsoft-built, Microsoft-recommended PowerShell feature designed for exactly this concern. The monitoring account can run a small number of specific, pre-approved, read-only operations, and nothing else that PowerShell's own constrained-session enforcement can prevent — on every single server independently.

<a id="what-the-account-can-actually-do-the-complete-list"></a>

## What the account can actually do — the complete list

This isn't a summary. This is genuinely the whole thing:

- Check whether a reboot is pending (reads two registry values)
- Check whether a named Windows service is running or stopped
- Check the count of pending Windows updates
- Check free space on local disks

That's it. No remote command execution, no ability to install anything, change anything, start or stop anything, modify the registry, or touch a file — by design, and enforced at the PowerShell session level for anyone using this account, including if its credentials were fully compromised.

<a id="what-the-account-explicitly-does-not-have"></a>

## What the account explicitly does NOT have

- **No local administrator rights**, on any server
- **No membership in any privileged AD group** — it's a plain domain user
- **No ability to log in interactively** — it's a service account, gated entirely by Kerberos, never used for console/RDP access
- **No stored password** in any script, config file, or environment variable — authentication uses a Kerberos keytab, the same mechanism used for any properly-configured Linux-to-AD service integration
- **No designed path to arbitrary code execution.** This is a constrained PowerShell session (`RestrictedRemoteServer`), not a sandboxing/hypervisor-level guarantee — the honest claim is that this removes every *intended* path to broader access and has been directly tested to confirm nothing off the whitelist is reachable, not that no PowerShell language-mode vulnerability could ever exist. That distinction matters to a security-literate reviewer, and eliding it would undermine trust in the rest of this document.

<a id="how-the-enforcement-actually-works-not-a-policy-a-mechanism"></a>

## How the enforcement actually works — not a policy, a mechanism

This is the part worth reading carefully, because it's the actual answer to "what stops this from being abused."

**Every operation is a specific, named PowerShell function, explicitly whitelisted by name, on each server independently.** Nothing about how this account connects lets it choose *what* to run — it can only invoke functions that are individually listed, by name, in a configuration file sitting on that server. Each function does exactly one narrow thing:

```powershell
function Get-RebootPendingStatus {
    param()  # no input accepted at all
    # reads two specific registry values, returns yes/no
}
```

There is no function that accepts a parameter telling it what to check, what to run, or what path to touch. Every function is hardcoded to do one specific, reviewed thing. **This is a deliberate design choice, not an oversight** — a version that accepted "which service to check" as an input was considered and explicitly rejected, specifically because it would mean trusting parameter validation to stay correct forever, rather than having no parameter to get wrong in the first place. The whitelist file is the entire security boundary, and it's short enough to read end to end in under a minute:

```
VisibleFunctions = @('Get-RebootPendingStatus', 'Get-WuauservStatus',
                      'Get-W32timeStatus', 'Get-PendingUpdateStatus',
                      'Get-LocalDiskSpace')
VisibleCmdlets = @()
VisibleExternalCommands = @()
```

Everything else — `Get-Process`, `Get-ItemProperty`, `Invoke-Expression`, running an `.exe`, anything at all outside that list — is **not reachable in the session, at the PowerShell language level**, regardless of what the connecting account's underlying Windows permissions would otherwise allow. This has been tested directly, not just configured and assumed: connecting with this account and attempting to run anything off the whitelist fails immediately with "not recognized," confirmed as part of validating every function that gets added — including, in one case, a legitimate read-only cmdlet (`Get-CimInstance`) that turned out not to be reachable either, for an unrelated reason (module auto-loading is disabled in this kind of session) caught during real testing before it ever shipped.

<a id="per-server-enforcement-but-a-fleet-wide-credential-the-honest-blast-radius-picture"></a>

## Per-server enforcement, but a fleet-wide credential — the honest blast-radius picture

Each server has its own independent copy of the whitelist — no single JEA endpoint, if compromised, grants reach across the whole fleet. **Worth being precise about what that does and doesn't buy, though:** the Kerberos keytab authenticating this account is a single credential, and if it were stolen, it could authenticate against *every* JEA-enabled server, not just one. The blast radius isn't zero-risk-beyond-one-host — it's **bounded by what the whitelist allows**, fleet-wide. A stolen keytab lets an attacker run the same handful of read-only checks against every server this account can reach — a real capability, but a narrow and non-destructive one, not a foothold for lateral movement, privilege escalation, or persistence. That's the actual guarantee: not "compromise doesn't matter," but "compromise's ceiling stays low and is fully enumerable in advance," which is a meaningfully different and more honest claim.

<a id="read-only-provably-not-a-claim-a-property-of-whats-on-the-list"></a>

## Read-only, provably — not a claim, a property of what's on the list

Look at the list again: a registry read, a service-status read, an update-count read, a disk-space read. **None of them write anything.** There is no install function, no configuration-change function, no remote-command function on the list, and adding one would require the same explicit, reviewed process as everything above — it doesn't get easier or more casual to add a write operation than a read one. Every future check follows the identical pattern: one narrow, named, zero-input function, reviewed and whitelisted individually.

<a id="encrypted-in-transit-always"></a>

## Encrypted in transit, always

Every request is Kerberos-authenticated, and Kerberos provides its own message-level encryption independent of whether the connection uses HTTPS — this isn't a workaround, it's how Windows Remote Management has supported Kerberos since it was introduced, specifically so authenticated sessions don't need a separate TLS layer to avoid exposing anything in the clear.

<a id="this-account-was-deliberately-kept-narrower-than-convenience-would-suggest"></a>

## This account was deliberately kept narrower than convenience would suggest

A few real examples of options that were available and turned down, specifically to keep this account's reach as small as possible:

- **A "supply the service name and we'll check it" design was rejected** in favor of one narrow function per service, specifically because the whitelist for one server applies to every server running this configuration — expanding it once expands it everywhere, so it was kept as small and explicit as possible rather than generalized for convenience.
- **Pattern-matching or wildcard service names were rejected outright** — a fixed, exact list of what's reachable was judged worth the extra step of adding new entries explicitly, over a more flexible design that could silently grow to include things nobody reviewed.
- **This is not a replacement for the Windows Update Agent, WSUS, or any patch-management tooling** — it counts pending updates for visibility, nothing more.

<a id="what-this-replaces"></a>

## What this replaces

The status quo it's replacing is a mix of WMI-based and SNMP-based Windows monitoring — both have a materially worse security story than this design:

- **WMI** is an older Windows management technology being phased out, with broad, harder-to-constrain access and no equivalent of a per-operation whitelist — historically one of the more common paths used in real-world lateral movement precisely because of how much reach it typically grants.
- **SNMP** (v1/v2c specifically, the versions most commonly deployed for this kind of monitoring) authenticates with a community string sent in cleartext, functions as a shared "password" rather than a per-account credential, and grants read access to whatever the agent exposes — there's no equivalent to a whitelist naming the specific handful of operations allowed. SNMPv3 improves the authentication story but still doesn't offer anything like a reviewed, per-operation whitelist.

This approach is a narrowing of what monitoring access has traditionally required, not a widening — replacing both a broad management interface and a weakly-authenticated read protocol with a small number of individually reviewed, read-only operations.

<a id="known-limitations-stated-plainly-not-hidden"></a>

## Known limitations — stated plainly, not hidden

No design is without tradeoffs, and a document that claimed otherwise wouldn't deserve to be trusted on the parts it gets right either.

- **The service account's password does not rotate automatically.** It's set to never expire and can only be changed by an administrator (not the account itself), which is deliberate — but that means rotation is a manual process (regenerating the Kerberos keytab), not an automatic one. If the account's password is ever reset through a different path than the documented rotation procedure, authentication breaks until it's caught and corrected — a real operational risk, mitigated by documentation and by excluding this account from any automated password-rotation sweep, not eliminated.
- **A fully automatic-rotation alternative (a Group Managed Service Account) was considered and deliberately not adopted** — not because it's infeasible, but because retrieving a gMSA's password from a non-Windows host isn't a standard, Microsoft-supported path, and building that bridge would concentrate a new, sensitive capability into custom code to solve a rotation problem that's currently rare. Worth revisiting if that calculus changes.

<a id="if-you-still-want-to-verify-this-yourself"></a>

## If you still want to verify this yourself

Every claim above is checkable directly, not just assertable:

1. Ask to see the whitelist file (`.psrc`) on any server running this — it's a few lines, and it's the complete list.
2. Ask to see the account's AD group memberships — it will show plain domain user, nothing else.
3. Attempt to run anything not on the list, using this account, against a real server — it will fail, immediately, with an unambiguous "not recognized" error.

If any of those three checks don't match what's described here, that's a real finding worth escalating — this document is meant to be falsifiable, not just reassuring.
