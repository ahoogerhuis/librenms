# WinRM Transport: Why Port 5985 (HTTP) Is Not a Plaintext Gap

**Contents**
- [The claim, stated plainly](#the-claim-stated-plainly)
- [The actual mechanism](#the-actual-mechanism)
- [What this means concretely](#what-this-means-concretely)
- [Hardening worth doing regardless](#hardening-worth-doing-regardless)
- [TL;DR for anyone reviewing the firewall table](#tldr-for-anyone-reviewing-the-firewall-table)

Flagging this explicitly because it's the kind of thing a security reviewer scanning for "no TLS" will reasonably flag on sight — worth having the answer already written down rather than re-litigating it every time someone new looks at the firewall table and sees `5985` instead of `5986`.

<a id="the-claim-stated-plainly"></a>

## The claim, stated plainly

**The proxy connects to WinRM over port 5985 (HTTP, no TLS at the transport layer) — and the payload is still encrypted.** This isn't a gap that got missed; it's how WinRM+Kerberos is designed to work.

<a id="the-actual-mechanism"></a>

## The actual mechanism

Kerberos authentication provides its own message-level confidentiality via GSS-API wrapping, independent of whatever's happening at the transport layer. WinRM has supported this for message encryption since its earliest versions specifically so that Kerberos/NTLM-authenticated sessions don't require HTTPS to avoid sending credentials or payload in the clear.

**Verified against `pypsrp`'s actual behavior, not assumed:** the `encryption` parameter on `WSMan` defaults to `"auto"` — which applies GSS-API message encryption automatically whenever the connection isn't over HTTPS. Confirmed via `pypsrp`'s own installed source (`inspect.signature`/`inspect.getsource` on `WSMan.__init__`, not just its docs): `encryption: str = "auto"`, docstring "Controls the encryption setting, default is auto but can be set to always or never." Getting a genuinely *unencrypted* HTTP connection requires explicitly passing `encryption="never"` — the default (what this proxy uses) does not do that. Nothing in this codebase disables it.

<a id="what-this-means-concretely"></a>

## What this means concretely

- Port 5985 + Kerberos auth: **payload is encrypted**, just not via TLS — via Kerberos's own message wrapping instead.
- Port 5986 + TLS: payload is encrypted via TLS instead (and `pypsrp`'s `encryption="auto"` correctly skips the redundant message-level encryption in that case, since TLS already covers it).
- Either way, nothing goes out in the clear. The choice between 5985 and 5986 here is not a security-vs-convenience tradeoff — it's a transport choice with equivalent confidentiality either way for this specific auth mode (Kerberos). It would be a real gap for Basic auth, which is why WinRM's `AllowUnencrypted` setting defaults to `false` server-side and rejects true-plaintext Basic auth over HTTP by default.

<a id="hardening-worth-doing-regardless"></a>

## Hardening worth doing regardless

Right now this security property is **implicit** — it depends on `pypsrp`'s default never changing and nobody ever adding `encryption="never"` later. Worth making it explicit in the code: state `encryption="auto"` outright (rather than relying on the unstated default) with a comment pointing at this doc for the reasoning. Costs nothing, and turns "this happens to be secure because of a library default" into "this is visibly, deliberately secure" — matching the project's existing fail-loud, don't-rely-on-implicit-behavior principle used elsewhere (e.g. the JEA endpoint validation approach, the CA fingerprint pinning on the proxy's own inbound TLS).

<a id="tldr-for-anyone-reviewing-the-firewall-table"></a>

## TL;DR for anyone reviewing the firewall table

Seeing `5985` (not `5986`) in the firewall requirements is expected and correct for this project's specific auth mode (Kerberos), not an oversight. If the auth mode ever changes to something without its own message-encryption story (Basic, for instance — which this project doesn't use), this reasoning would need revisiting. It doesn't need revisiting for Kerberos.
