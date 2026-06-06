# Security Policy — License Service

**Author: Jeremiah Buttler**

---

## Reporting a vulnerability

If you discover a security vulnerability in this module, **do not open a public
GitHub issue**. Instead, contact the maintainer privately:

- Email: report the issue to the project maintainer with subject line
  `[license_service] Security vulnerability`
- Response target: within 72 hours

Please include:
- A description of the vulnerability and its impact
- Steps to reproduce
- Which version(s) are affected

Maintainers will coordinate a fix and a coordinated disclosure date.

---

## Security model

### License token trust

All license claims (tier, features, expiry, machine ID) are taken **only from
signed tokens**. Tokens are signed with Ed25519 by the License Verification
Server. The module never trusts Drupal config or state alone for access
decisions — the token signature is always verified offline using the server's
public key.

**What this means for security:**
- An attacker cannot gain higher access by editing Drupal config, state, or DB
  records — all changes are rejected when the token signature does not match.
- The public key itself is fetched on first activation and stored in state. Key
  rotation is supported: when the server rotates its signing key, cached tokens
  fail verification and the site enters the offline grace window until a fresh
  activation is performed.

### SSRF prevention

The admin-configurable server URL is validated before any outbound HTTP request:
- Must start with `https://` (HTTP is rejected; falls back to the default URL).
- The hostname is checked against all private and reserved IPv4/IPv6 ranges
  (`10.x`, `172.16-31.x`, `192.168.x`, `127.x`, `::1`), loopback aliases
  (`localhost`, `ip6-localhost`), and internal suffixes (`.local`, `.internal`).
- Any URL that fails validation silently falls back to the default production
  server, never to an attacker-controlled host.

### License key hygiene

- The license key is stored via one of three provider options: Key module
  (recommended for production), `settings.php`, or Drupal state (dev only).
- The key is **never stored in exported configuration** (CMI/config:export would
  expose it in version control).
- The key is never logged, never echoed back in form fields (uses
  `#type = 'password'`), and only the last four characters are shown masked in
  the admin UI.
- On module uninstall, the state-stored key is deleted. Key module keys are
  owned by the Key module and must be deleted there independently.

### Cryptographic operations

- Ed25519 signature verification uses PHP's libsodium (`ext-sodium`), which is
  a constant-time implementation immune to timing attacks.
- Base64url encoding/decoding uses the same library. No custom crypto.
- Token verification (`Ed25519Verifier::verify()`) always checks the signature
  before trusting any payload field. There is no path that returns payload data
  from an unverified token (except the explicit `decode()` method documented as
  unsafe for access decisions).

### Access control and cache leaks

Every method in `ContentAccessChecker` returns `AccessResult` objects carrying:
- Cache context `user.roles` — prevents render cache from serving
  role-A content to role-B users.
- Cache tag `license_service` — immediately invalidated on any license or rule
  change, preventing stale access grants from persisting in the render cache.
- Per-user quota/metered-view results additionally carry cache context `user` so
  they are never shared across users.

`AccessResult::neutral()` is returned (not `allowed()`) when checks pass,
so the module defers to Drupal core's node access system for final approval.
The module **never** returns `allowed()` — it can only deny or abstain.

### Route and permission protection

All admin routes (`/admin/config/license-service/*`) require the
`administer license gate` permission, which is marked `restrict access: true`.
All forms use Drupal's Form API (ConfigFormBase), which includes CSRF token
validation automatically. No routes use `_access: 'TRUE'`.

The `bypass license gate` permission is also marked `restrict access: true`
and is intended only for site administrators who must be exempt from all
content-level restrictions.

### Injection prevention

- All database queries use Drupal's query builder (parameterized) — no raw SQL.
- All user-visible output from token payloads (warnings, customer name, tier)
  that appears in `#markup` render arrays is passed through `htmlspecialchars()`
  before output. Plain string table cells are auto-escaped by Drupal's theme layer.
- Feature bullet items read from README.md are escaped with `htmlspecialchars()`
  before being placed in `#markup`.

### Seat cap enforcement

Premium role assignment is gated in `hook_user_presave()` against the
`max_premium_users` value from the signed token. An attacker cannot bypass this
by editing DB records — the seat cap value comes only from the verified token.

---

## Known limitations and accepted risks

| Area | Limitation | Mitigation |
|------|------------|------------|
| Offline grace | During the offline window, access decisions rely on the cached token, not a live server call. A revoked license may remain active for up to the grace period (default 24h). | Keep the grace period short for high-security deployments. Cron re-checks reduce the window. |
| Metered views | View counting uses `hook_node_view` (full/default view modes), not every access check. Multiple browser tabs or partial loads may not count. | Acceptable for soft metering; not suitable for hard DRM. |
| Quota reset | Create/edit quotas are not automatically reset per-period — they track lifetime usage unless manually cleared or the quota is managed server-side. | Per-period quotas should be coordinated with license renewal logic. |
| Key module dependency | When using the Key module for key storage, uninstalling `license_service` does NOT delete the Key module key entity. | Document this in the uninstall procedure; Key module keys should be deleted manually. |

---

## Supported versions

| Version | Supported |
|---------|-----------|
| 1.x     | Yes       |

---

## Security checklist for deployments

- [ ] Use the Key module or `settings.php` for license key storage in production
- [ ] Ensure `ext-sodium` is installed and enabled
- [ ] Confirm the server URL is the production `https://www.licenseverificationserver.com`
- [ ] Set the offline grace period to the minimum acceptable for your compliance requirements
- [ ] Grant `administer license gate` only to site administrators
- [ ] Grant `bypass license gate` only to trusted super-admins
- [ ] Enable Drupal's built-in TLS cert verification (the module sets `verify: TRUE` on all outbound requests)
