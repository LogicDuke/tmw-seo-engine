# Security Policy — TMW SEO Engine

<!-- TODO: Replace the placeholder contact address below with the real security
     contact before making this repository public or sharing it externally. -->

---

## Supported Versions

Only the most recent release receives security fixes. Older versions are not actively patched.

| Version | Supported |
|---------|-----------|
| 4.6.x (current) | ✅ Yes |
| < 4.6 | ❌ No — upgrade before reporting |

---

## Reporting a Vulnerability

**Do not open a public GitHub issue to report a security vulnerability.** Public disclosure before a fix is available puts all installations at risk.

### How to report

Send a private report to:

**security-contact@example.com**
*(TODO: replace with the real security contact before public release)*

### What to include

A useful report contains:

1. **Description** — what the vulnerability is and what an attacker could achieve
2. **Affected version(s)** — the plugin version where you observed it
3. **Reproduction steps** — the exact steps or code path needed to trigger it
4. **Impact assessment** — your estimate of severity (data exposure, privilege escalation, RCE, etc.)
5. **Suggested fix** — optional, but appreciated
6. **Environment** — PHP version, WordPress version, relevant server configuration

The more detail you provide, the faster we can assess and patch.

### What to expect

| Stage | Target timeline |
|---|---|
| Acknowledgement | Within 5 business days |
| Initial triage | Within 10 business days |
| Fix or mitigation | Depends on severity; critical issues are prioritised |
| Coordinated disclosure | Agreed with the reporter before any public announcement |

We will keep you informed throughout the process. If you do not receive an acknowledgement within 5 business days, please follow up.

---

## Secrets, Credentials, and API Keys

This plugin integrates with several external services. Operators must take care with credentials:

- **Never commit credentials to version control.** API keys for DataForSEO, OpenAI, Anthropic, Google, or any other provider must not appear in the repository.
- **Rotate keys immediately** if you suspect a credential has been exposed.
- **GSC OAuth tokens** are encrypted at rest using `sodium_crypto_secretbox` tied to the WordPress installation's auth keys. Exporting the database without the matching `wp-config.php` secrets will render the tokens unreadable — this is intentional.
- **Test credentials** in `tests/bootstrap/wordpress-stubs.php` (e.g. `AUTH_KEY`) are fixed test stubs and must never be reused in any real environment.

---

## Staging Safety

All security-sensitive changes — especially schema migrations, authentication/authorisation changes, and cron/worker behaviour — **must be validated on a staging environment before deployment to production**.

- Enable only the components you need via **TMW SEO → Staging Ops**
- The **Model Discovery Scraper** is disabled by default and marked `risky`; review the terms of service for any target platform before enabling it
- Database migrations run on activation and cannot be easily rolled back; test them on a copy of production data first

---

## Scope

The following are generally in scope for security reports:

- Authentication/authorisation bypasses in admin pages
- SQL injection via any user-controlled input
- Stored or reflected XSS in admin output
- Insecure direct object references (e.g. accessing other users' data)
- Nonce bypass or CSRF vulnerabilities
- Arbitrary file read/write via plugin functionality
- Sensitive data exposure (credentials, tokens, PII)
- Remote code execution

The following are generally **out of scope**:

- Issues in third-party plugins or WordPress core
- Issues requiring administrator-level access to exploit (by definition, administrators are trusted)
- Denial-of-service via legitimate admin operations
- Social engineering or phishing attacks
- Issues on hosts that have misconfigured PHP/WordPress outside this plugin's control

---

*Last updated: 2026-03*
