# License Service

A Drupal 10/11 module that connects a Drupal website to the
[License Verification Server](https://www.licenseverificationserver.com) and controls
user access to content based on license level and user role.

Site administrators activate a single site license using an API key, map Drupal
user roles to license levels, and configure fine-grained content entitlements per
level. The module enforces those entitlements across all content types through
Drupal's native access system.

## Requirements

- Drupal 10 or 11 (`core_version_requirement: ^10 || ^11`)
- PHP 7.4+ with the `sodium` extension (bundled since PHP 7.2)
- Composer (recommended for dependency resolution)

## Installation

1. Copy the `license_service/` folder into your Drupal site's `modules/custom/` directory.
2. Enable the module via Drush:
   ```
   drush en license_service
   ```
   Or navigate to **Admin → Extend**, search for "License Service", and enable it.
3. Navigate to **Admin → Configuration → License Service → Settings** and enter
   your license key.
4. Click **Activate** to register this site with the License Verification Server.

## Configuration

### License key
Enter your API/license key at **Configuration → License Service → Settings**. Click
**Activate** to bind this Drupal environment as a licensed site. The status panel
shows your tier, expiry date, seats used, and any warnings (offline grace,
expiry approaching).

### Role → Level mapping
At **Configuration → License Service → Role Levels**, map each Drupal user role to a
license level (e.g. Free, Standard, Premium). Available levels are constrained by
your site license's tier and feature envelope.

### Content entitlements
At **Configuration → License Service → Content Rules**, configure per-level,
per-content-type rules:
- Which levels may view, create, edit, or delete nodes of each content type
- Create/edit quotas (max nodes a user may create/edit per type)
- Metered view limits (max premium views per period)
- Field-level access (which fields are visible at each level)
- File and media download gating

---

## Features

*This section is the canonical feature reference for the License Service module.
The same list is searchable in your Drupal admin at
**Configuration → License Service → Features**.*

### Site License Management
- **License activation** — Activate a license key and register this Drupal site as a licensed environment; each environment consumes one seat
- **Offline Ed25519 verification** — Verify the license offline using the bundled Ed25519 public key on every request; no network call needed until the token's refresh window passes
- **Online re-check on cron** — Automatically re-verify against the server when the token is past its `refresh_by` timestamp; no manual intervention needed
- **24-hour offline grace** — Continue operating for 24 hours after the server becomes unreachable before enforcing a lockout (configurable)
- **Expiry warnings** — Surface non-fatal banners when the license is expiring within the configured warning window (default 7 days)
- **Subscription support** — Honor `expires_at`; automatically downgrade the site to Free tier when a subscription lapses
- **Trial license support** — Run in full-featured mode during a trial period; auto-downgrade when the trial ends
- **Periodic heartbeat** — Send a lightweight heartbeat to the License Verification Server on every cron run to confirm the site is active
- **Deactivate on demand** — Release the site's seat from the settings panel (useful when migrating to a new server)

### Role-Based License Levels
- **Role → Level mapping** — Map any Drupal user role to a named license level (e.g. Anonymous → Free, Editor → Standard, Publisher → Premium)
- **Tiered site license** — Site license `tier` (e.g. Free / Pro / Enterprise) controls which levels and capabilities the admin is permitted to assign
- **License envelope** — All admin-configured levels, caps, and rules are validated against the license's `features` envelope from the server; out-of-envelope settings are blocked
- **Per-role seat caps** — Enforce a maximum number of users allowed in premium-mapped roles, driven by `features.max_premium_users` in the signed license token
- **Seat-cap warnings** — Admin UI displays remaining premium seats and warns (or blocks) new role assignments that would exceed the cap

### Content Access Enforcement
- **Premium-level view gating** — Tag content with a required access level (field or taxonomy); users may only view the content if their resolved level qualifies
- **Whole content-type access** — Restrict entire content types so they are only visible to users at or above a specific level
- **Field-level gating** — Control field visibility within a node based on the user's level (e.g. Free users see only the summary, Premium users see all fields and attachments)
- **Create/edit quotas** — Per level, cap the number of nodes a user may create or edit per content type (e.g. Standard: 50 articles, Premium: unlimited)
- **Metered period views** — Limit the number of premium content views a user may consume per period (e.g. 5 premium articles per month); resets on period rollover
- **Download/file gating** — Gate file field and media entity downloads by license level, independent of the parent node's view access
- **Drupal access API compliance** — All decisions use `AccessResult::forbidden()`, `::allowed()`, and `::neutral()` with correct cache contexts and tags, composing safely with core and other modules
- **Admin bypass permission** — Users with `bypass license gate` permission are always granted access, ensuring administrators are never locked out

### Admin Interface
- **Settings form** — Configure license key, server URL, offline grace hours, and expiry warning days; activate or deactivate in one click with immediate status feedback
- **Live status panel** — Displays tier, expiry date, seats used/available, trial state, grace status, and all active warnings at a glance
- **Role → Level matrix** — Visual form mapping every Drupal role to a license level, with envelope validation and seat-cap feedback
- **Content rules grid** — Per-level × per-content-type configuration covering view, create, edit, delete access; quotas; metered view limits; field gating; and file/download gating — all in one collapsible grid
- **Features page** — Searchable, filterable feature reference available to admins at **Configuration → License Service → Features**
- **Terms & Conditions page** — Full terms of service readable in the Drupal admin at **Configuration → License Service → Terms & Conditions**

### License Enforcement & Status Reporting
- **Site-wide enforcement subscriber** — Optional, admin-configurable behavior when the site license is missing or expired past grace: warn-only mode (admin nag banners) or enforcement mode (premium routes blocked; all users downgraded to Free)
- **Drupal Status Report integration** — License state appears on the core **Reports → Status report** page: error when unlicensed or expired past grace, warning when expiring soon or running on offline grace
- **`hook_requirements` checks** — Both install-time and runtime requirement checks surface the license state to site builders and automated monitoring tools

### AI Token Quota Enforcement (License Service: Token Limits sub-module)
*Requires the `license_service_token_limits` sub-module to be enabled along with `license_service_token_counter`.*

- **Per-level token quotas** — Configure a maximum token count and billing period (daily / weekly / monthly) for each license level; users whose cumulative AI token usage for the period meets or exceeds their level's quota are blocked from making further AI calls
- **Hard block enforcement** — Quota enforcement is always-block: no warn-only mode. When a user is over quota the AI request is aborted before the model call, with a user-visible warning message and a watchdog notice
- **Admin quota configuration form** — Manage per-level token limits at **Configuration → License Service → Token Limits** (gated by `administer license gate`); set token amounts and reset periods per configured license level
- **License-envelope aware** — Quota enforcement activates only when the signed license token grants the `quotas` feature bit; sites on tiers that do not include quota enforcement are transparently unaffected
- **Bypass-permission aware** — Users with `bypass ai token usage limits` or `bypass license gate` are always exempt from quota enforcement; anonymous users are never subject to per-level quotas
- **Event priority ordering** — Fires at priority 90 on `PreGenerateResponseEvent`, after the token counter's per-rule enforcement at 100, so explicit AI-rule token limits always take precedence over level quotas
- **Graceful degradation** — Subscribes to `PreGenerateResponseEvent` only when the `drupal/ai` module's event class is present; the sub-module loads cleanly even on AI module versions that predate the event

---

## Quota & metering reset semantics

The module tracks usage in **two separate database tables with deliberately
different reset behaviour.** Understanding the difference matters before you set a
quota in **Content Rules**, because one auto-resets and the other does not.

### Metered views — auto-reset per period (`license_service_meter`)

Recorded in `hook_node_view()` for genuine `full`/`default` renders only (not
teasers, search excerpts, REST/JSON:API, Views, or programmatic reads).

- **Primary key:** `(uid, content_type, period)`.
- The `period` column holds a **rotating period key** from
  `EntitlementResolver::getCurrentPeriodKey()`:
  - `daily` → `Y-m-d` (UTC)
  - `weekly` → `o-W` — the **ISO week-numbering year** (`o`), not the calendar
    year (`Y`), so the bucket does not split or collide across a 1 Jan / 31 Dec
    week boundary
  - `monthly` → `Y-m` (UTC)
- **How it resets:** when the period rolls over, the computed key changes, so the
  next view writes a **fresh row** and the count restarts at `0`. The limit
  therefore **auto-resets every period by design** — no cron job or cleanup task
  is required.
- **Old rows:** previous-period rows are never read again (the query always
  filters on the current period key) and simply linger harmlessly. If you want to
  reclaim the space you may delete rows whose `period` is older than the current
  key, but it is purely housekeeping and never affects enforcement.

### Create / edit quotas — lifetime / cumulative, never auto-reset (`license_service_quota`)

Recorded in `hook_node_insert()` (create) and `hook_node_update()` (edit), only
when a quota is actually configured for the user's level + content type.

- **Primary key:** `(uid, content_type, operation)` — **there is no `period`
  column.**
- **How it resets:** it **does not.** The count is **lifetime / cumulative** and
  accrues forever for that user + content type + operation. Once a user reaches
  the configured `create_quota` / `edit_quota`, they stay at the cap permanently
  unless the count is reset by hand.
- **Resetting a user's quota** currently requires a **manual database
  operation** — there is no admin UI button. To reset one user's create quota for
  one content type:

  ```sql
  -- Reset a single user's create quota for "article":
  DELETE FROM license_service_quota
   WHERE uid = :uid AND content_type = 'article' AND operation = 'create';

  -- Or zero it without deleting the row:
  UPDATE license_service_quota
     SET count = 0
   WHERE uid = :uid AND content_type = 'article' AND operation = 'create';
  ```

  Via Drush:

  ```bash
  drush sql:query "DELETE FROM license_service_quota WHERE uid = 42 AND content_type = 'article' AND operation = 'create';"
  ```

### Why the difference is intentional

Metered **views** model *consumption that should refresh* ("5 premium articles
per month"), so a rotating period key is the natural mechanism. Create/edit
**quotas** model *a lifetime allowance* ("this account may publish at most 50
articles"), so they accumulate and hold. They are not bugs in each other — they
are two different policies. The lifetime behaviour is the **documented "lifetime
quota" gotcha**: if you intend a quota to refresh monthly, that is **metered
views territory, not create/edit quotas**, and per-period quota support would
require adding a period column and key rotation to `license_service_quota`
(a possible future enhancement).

> **Bypass:** users with the `bypass license gate` permission never have views or
> quota usage recorded, and anonymous users (uid 0) are never metered.

---

## Licensing & Plans

| Plan | Type | What you get |
|---|---|---|
| **Free** | Perpetual | Module enabled; basic role→level mapping; limited levels; read-only content rules |
| **Pro** | Subscription | Full level assignment, content quotas, metered views, subscription enforcement |
| **Enterprise** | Subscription | All Pro features + per-role seat caps, field-level gating, file/download gating, multi-environment seats |

*Pricing and exact tier entitlements are configured on the License Verification
Server and reflected in the license token issued to your site.*

---

## Terms & Conditions

See [TERMS.md](TERMS.md) for the full Terms & Conditions governing use of this module.
The terms are also available in your Drupal admin at
**Configuration → License Service → Terms & Conditions**.

## License

GPL-2.0-or-later
