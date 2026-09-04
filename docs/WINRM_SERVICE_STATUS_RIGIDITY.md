# Service-Status Rigidity — Fixed Functions vs. Configurable Service Names

**Contents**
- [The tension, stated plainly](#the-tension-stated-plainly)
- [Three shapes this could take, not two](#three-shapes-this-could-take-not-two)
- [Why option 3 over option 2's original rejection](#why-option-3-over-option-2s-original-rejection)
- [What doesn't change under any of these three](#what-doesnt-change-under-any-of-these-three)
- [Decision](#decision)
- [What this means going forward](#what-this-means-going-forward)

<a id="the-tension-stated-plainly"></a>

## The tension, stated plainly

Today, monitoring a new service means real ceremony: a new PowerShell function (`Get-<Service>Status`), a new `.psrc` whitelist entry, a new Python check file, a new `registry.py` entry, a new `WinrmPoller.php` `checks()` table row. That's the correct amount of ceremony for a *security-relevant* decision — but it's also genuinely rigid for what's often a low-stakes operational ask ("also watch the Spooler service on this one host"). Worth debating honestly whether that rigidity is buying enough to justify itself in every case, the same way the gMSA writeup (`docs/WINRM_DESIGN.md`'s "Kerberos credential aging & rotation" section) weighed real flexibility against real risk rather than picking a side by default.

<a id="three-shapes-this-could-take-not-two"></a>

## Three shapes this could take, not two

**1. Status quo — one function per service (current).** Maximum security, maximum friction. The `.psrc` file alone tells you everything reachable, with zero cross-referencing needed. Doesn't scale gracefully if the fleet ends up wanting to watch a dozen-plus services — that's a dozen-plus near-identical functions, whitelist entries, and Python files, mostly boilerplate.

**2. LibreNMS supplies a service name, matched by prefix/pattern ("begins with").** This is the one worth naming as a real security regression, not just a flexibility tradeoff — worth being as direct about this as the gMSA-retrieval-technique framing was. A fixed whitelist has a defining property: **the set of reachable things is known and fixed at deploy time**, reviewable by reading one file. Prefix/pattern matching breaks that property — the actual set of services a caller can query becomes whatever happens to match the pattern *on that host, at that moment*, which can silently grow over time (a Windows feature install adds a new service starting with `W32`, and it's now queryable without anyone having reviewed or approved it). That's not a smaller version of the current whitelist model, it's a structurally different, weaker one — closer in spirit to the SNMP-extend flexibility `docs/WINRM_EXTENSIBILITY_TRADEOFF.md` already explains why this project doesn't offer, just reintroduced at the service-name layer instead of the whole-check layer. Also worth naming plainly: service status is information disclosure (what's running, and its state) — not nothing, from a recon perspective, even though it's less severe than arbitrary execution.

**3. LibreNMS supplies a service name, matched against a fixed exact-match whitelist maintained on the JEA side.** This is the real middle ground, and it's worth presenting as the likely right answer rather than splitting the difference vaguely. Concretely: one JEA function (`Get-ServiceStatus`, `ValidateSet`-constrained to an explicit, code-reviewed list of exact service names — this is "Option B" from the original service-status design, already considered and set aside once, worth revisiting now that the actual pain point is clearer) accepts a service name **only from that fixed list**. The list itself still requires a deliberate, reviewed code change to extend — that part doesn't get more flexible, and shouldn't, since it's the actual security boundary (which services a shared, domain-wide-reaching credential can ever be asked about). **What does get more flexible: which of the already-approved services a given device's poller actually checks becomes a LibreNMS-side config decision**, not a new Python file + `registry.py` entry + `WinrmPoller.php` row per service. Adding `Spooler` to a specific device's monitoring, once `Spooler` is already on the approved list, is a config change, not a code change.

<a id="why-option-3-over-option-2s-original-rejection"></a>

## Why option 3 over option 2's original rejection

Worth being honest that this reopens a question already decided once (one-function-per-service, "Option A," was chosen over a `ValidateSet`-constrained single function specifically for auditability and to avoid the two-enforcement-layers risk — see `WINRM_JEA_WINDOWS.md`, `vpp/infra-bits`). What's different now: the original decision was framed as "one service" (`wuauserv` or `W32Time`, a binary choice), where the ceremony cost of Option A is trivial. The actual pain point surfacing now is a **fleet-wide, many-services** version of the same question, where Option A's ceremony compounds linearly and Option 3's two-layer-enforcement risk (function `ValidateSet` + JEA `Parameters` constraint needing to both be right) is a one-time cost to get right, not a recurring one. The tradeoff calculus is different at that scale — worth re-deciding with that framing, not just deferring to the earlier answer by default.

<a id="what-doesnt-change-under-any-of-these-three"></a>

## What doesn't change under any of these three

- Still no free-form script, ever, at any layer.
- Still zero-parameter functions for anything that isn't inherently parameterized (registry reads, COM calls) — this only applies to the "which named service" question specifically.
- Still a deliberate, reviewed code change to expand what's *possible* to query — only what's *actually configured per-device* becomes lighter-weight under option 3.

<a id="decision"></a>

## Decision

**Option 1 stands — one function per service, status quo.** Settled, not just leaning, per the user's own priority: minimizing blast radius in a Windows environment matters more than reducing per-service ceremony. Worth spelling out why this argument is stronger than "less to review": **the JEA whitelist isn't scoped per-device — it's shared across every host running the endpoint.** Growing the approved-services list to serve one team's use case doesn't just widen what's reachable on that one host, it widens what a compromised proxy could query on *any* JEA-enabled host fleet-wide, whether or not that particular monitoring was ever configured there. Minimal whitelist is a smaller blast radius by construction, and that gets stronger as the fleet grows, not weaker.

Option 2 (prefix/pattern matching) was already ruled out as a structural security regression. Option 3 (consolidated exact-match `ValidateSet`) is now ruled out too — not deprioritized, ruled out. The ceremony it would have saved *is* the cost of keeping blast radius minimal; that's an accepted, deliberate tradeoff, not an open problem to revisit later without a real re-justification (e.g., a concrete case where the fleet's service list has grown large enough that the security argument itself starts to strain — not just "this is tedious").

<a id="what-this-means-going-forward"></a>

## What this means going forward

Every new service to monitor gets its own function, its own whitelist entry, its own check file, same ceremony as `wuauserv`/`W32Time`/the pending-updates check. That's not friction to engineer away — it's the mechanism doing its job.
