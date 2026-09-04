# WinRM Checks vs. SNMP Extend — The Extensibility Tradeoff

## The gap, stated plainly

On Linux, adding a custom check to LibreNMS via SNMP extend requires **no code changes to LibreNMS itself**: a user writes a script matching the expected extend JSON output, drops it on the host, adds one line to `snmpd.conf`, and LibreNMS's generic extend-polling picks it up. Any LibreNMS operator can do this unilaterally, on their own infrastructure, with zero upstream involvement.

The WinRM/JEA architecture has no equivalent. Every check requires writing and shipping actual code across multiple layers — a PowerShell function, a `VisibleFunctions` whitelist entry, a proxy-side `registry.py` handler, and often `WinrmPoller.php`/LibreNMS-core changes (as seen with `disk-space` needing `Storage` model integration). There is no config-only path to a new check. This will be a fair, predictable question from maintainers: "why can't users just write their own extend-style script the way they can on Linux?"

## Why the gap exists — a forced tradeoff, not an oversight

The two mechanisms have fundamentally different trust models, and that difference is the actual reason, not an accident of scope:

**SNMP extend on Linux** runs a user's script **locally, on the one host being monitored**, under whatever local privileges `snmpd` already has on that machine. There's no shared credential and no cross-host reach — a bad or malicious extend script can only affect the single host it's defined on, a host the operator already has full control over via other means (SSH, console, etc.). The blast radius is inherently contained to "one box you already own."

**The WinRM proxy's service account has network-wide reach by design** — it's the single Kerberos identity authenticating to every monitored Windows server across the fleet. That's the entire point of JEA in this architecture: the account can be trusted with that broad reach specifically *because* JEA constrains what it's allowed to execute to a fixed, code-reviewed whitelist. If LibreNMS allowed users to supply arbitrary "extend-style" PowerShell that this shared, domain-wide account would execute, that isn't a Linux-extend equivalent — it's giving anyone who can edit a LibreNMS config file remote code execution against every Windows server in the fleet, through a single shared credential. The trust models aren't just different in degree, they're different in kind: one script runs in a sandbox of one, the other would run with reach into everything.

**So this isn't a shortcoming to apologize for in the eventual discussion post — it's the direct, necessary consequence of the security model this project deliberately chose (JEA constraint + no caller-supplied script, ever), and worth stating with that confidence rather than conceding it as a limitation.**

## What extensibility does still exist

Not zero — just shaped differently:

- **Extensible via code contribution, not config.** An organization can write and maintain their own additional `Get-CustomCheck` functions and JEA whitelist entries against their own fleet, under their own review process, without upstreaming anything — same pattern as maintaining a local patch. This preserves genuine local extensibility while keeping the "no dynamic script execution, ever" invariant intact.
- **Upstream PR path for common checks.** New checks that benefit the broader user base (the four already scoped — service-status, disk-space, ntp-sync-status, winupdate-pending — are exactly this) go through the same review discipline as any other core module code, same as how new SNMP OID support or new device drivers get added to LibreNMS today. It's slower than "drop a script and reload," but it's the same rigor already applied to everything else at this layer.
- **What's NOT being proposed as a mitigation:** no half-measure like "allow a curated but user-editable list of PowerShell snippets" — that would just be the SNMP extend security model wearing a JEA-shaped disguise, and would defeat the actual point.

## For the upstream discussion post

Worth raising this proactively rather than waiting to be asked — framing: *"Unlike SNMP extend on Linux, adding a WinRM check requires code changes rather than a drop-in script. This is a deliberate consequence of the security model (JEA-constrained execution via a single shared, domain-wide service account) rather than a scope limitation — happy to discuss whether that tradeoff lands right for the project."* Gets ahead of the objection and shows the reasoning was considered, not missed.
