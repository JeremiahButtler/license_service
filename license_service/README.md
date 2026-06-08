# License Service

A Drupal 10/11 module that connects a Drupal website to the
[License Verification Server](https://www.licenseverificationserver.com) and controls
user access to content based on license level and user role.

Site administrators activate a single site license using an API key, define their own
license tiers locally, map Drupal user roles to those tiers, and configure fine-grained
content entitlements per level. The module enforces those entitlements across all content
types through Drupal's native access system.

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
3. Navigate to **Admin → Configuration → License Service → Settings**, accept the
   [terms and conditions](https://www.licenseverificationserver.com/terms), enter your
   license key, and click **Activate**.

## Configuration

### License key
Enter your API/license key at **Configuration → License Service → Settings**. You must
check the terms and conditions acceptance checkbox before activating. Click **Activate**
to bind this Drupal environment as a licensed site. The status panel shows your tier,
expiry date, seats used, and any warnings (offline grace, expiry approaching).

### License Tiers
At **Configuration → License Service → License Tiers**, define the tiers available on
your site — for example *Free*, *Standard*, *Premium*. Each tier has a weight (controls
ordering) and a set of feature flags that determine what is unlocked at that tier:
- Field-level content gating
- Download / file gating
- Metered (limited) content views
- AI token quota enforcement
- Content access control rules

Tiers are entirely local — the License Verification Server authorizes only a maximum
number of non-free users per plan; everything else is decided here.

### Role → Level mapping
At **Configuration → License Service → Role Levels**, map each Drupal user role to one
of your locally-defined tiers. A user's effective tier is the highest across all their
roles. The number of non-free users authorized by your LVS plan is shown for reference.

### Content entitlements
At **Configuration → License Service → Content Rules**, configure per-level,
per-content-type rules:
- Which levels may view, create, edit, or delete nodes of each content type
- Create/edit quotas (max nodes a user may create/edit per type — lifetime)
- Metered view limits (max content views per period — auto-resets each period)
- Field-level access (which fields are visible at each level)
- File and media download gating

---

## Features

*This section is the canonical feature reference for the License Service module.
The same list is searchable in your Drupal admin at
**Configuration → License Service → Features**.*

### Site License Management
- **License activation** — Activate a license key and register this Drupal site as a licensed environment; each environment consumes one seat
- **Terms acceptance** — A required terms-and-conditions checkbox must be checked before a key will be accepted, linking to the official LVS terms of service
- **Offline Ed25519 verification** — Verify the license offline using the bundled Ed25519 public key on every request; no network call needed until the token's refresh window passes
- **Online re-check on cron** — Automatically re-verify against the server when the token is past its `refresh_by` timestamp; no manual intervention needed
- **Offline grace window** — Continue operating for a server-configured grace period after the LVS becomes unreachable before enforcing a lockout
- **Expiry warnings** — Surface non-fatal banners when the license is expiring within the configured warning window (default 7 days)
- **Subscription support** — Honor `expires_at`; automatically downgrade the site to Free tier when a subscription lapses
- **Trial license support** — Run in full-featured mode during a trial period; auto-downgrade when the trial ends
- **Periodic heartbeat** — Send a lightweight heartbeat to the License Verification Server on every cron run to confirm the site is active
- **Deactivate on demand** — Release the site's seat from the settings panel (useful when migrating to a new server)

### Tenant-Defined License Tiers
- **Local tier editor** — Define any number of named tiers (e.g. Free, Standard, Premium) entirely within your Drupal configuration; no server interaction required to create or modify tiers
- **Feature flags per tier** — Each tier independently enables or disables field gating, download gating, metered views, AI quota enforcement, and content access control
- **Drag-and-drop ordering** — Tiers are ordered by weight using Drupal's tabledrag; the ordering determines privilege levels for the role-to-tier resolver
- **Free tier protection** — The *free* baseline tier is always present and cannot be deleted; all other tiers are freely created and removed
- **LVS authorized-user count** — The License Verification Server grants a maximum number of non-free users per plan; the module displays this cap for reference but does not restrict tier definitions or feature flags

### Role-Based License Levels
- **Role → Tier mapping** — Map any Drupal user role to a named license tier (e.g. Subscriber → Standard, Editor → Premium)
- **Highest-wins resolution** — A user's effective tier is the highest tier across all their roles, evaluated at access-check time
- **Admin bypass** — Users with `bypass license gate` always receive the highest configured tier regardless of their role mapping
- **Authorized-seat informational display** — The role-levels page shows the LVS-authorized seat count as a reference; role assignment itself is never blocked by the module

### Content Access Enforcement
- **Whole content-type access** — Restrict entire content types so they are only visible to users at or above a specific tier
- **Field-level gating** — Control field visibility within a node based on the user's tier (e.g. Free users see only the summary, Premium users see all fields and attachments)
- **Create/edit quotas** — Per tier, cap the number of nodes a user may create or edit per content type (e.g. Standard: 50 articles, Premium: unlimited); counts are lifetime-cumulative
- **Metered period views** — Limit the number of premium content views a user may consume per period (e.g. 5 premium articles per month); resets automatically each period
- **Download/file gating** — Gate file field and media entity downloads by license tier, independent of the parent node's view access
- **Drupal access API compliance** — All decisions use `AccessResult::forbidden()`, `::allowed()`, and `::neutral()` with correct cache contexts and tags, composing safely with core and other modules
- **Admin bypass permission** — Users with `bypass license gate` permission are always granted access, ensuring administrators are never locked out

### Admin Interface
- **Settings form** — Configure license key, server URL, and expiry warning days; accept terms of service; activate or deactivate in one click with immediate status feedback
- **License Tiers editor** — AJAX form for creating, reordering, and removing license tiers with per-tier feature flag checkboxes
- **Live status panel** — Displays tier, expiry date, seats used/available, trial state, grace status, and all active warnings at a glance
- **Role → Tier matrix** — Visual table mapping every Drupal role to a locally-defined tier, with an informational display of the LVS authorized-seat count
- **Content rules grid** — Per-tier × per-content-type configuration covering view, create, edit, delete access; quotas; metered view limits; field gating; and file/download gating — all in one collapsible grid
- **Features page** — Searchable, filterable feature reference available to admins at **Configuration → License Service → Features**

### License Enforcement & Status Reporting
- **Site-wide enforcement subscriber** — Optional, admin-configurable behavior when the site license is missing or expired past grace: warn-only mode (admin nag banners) or enforcement mode (premium routes blocked; all users downgraded to Free)
- **Drupal Status Report integration** — License state appears on the core **Reports → Status report** page: error when unlicensed or expired past grace, warning when expiring soon or running on offline grace
- **`hook_requirements` checks** — Both install-time and runtime requirement checks surface the license state to site builders and automated monitoring tools

### AI Token Quota Enforcement (License Service: Token Limits sub-module)
*Requires the `license_service_token_limits` sub-module to be enabled along with `license_service_token_counter`.*

- **Token Limits entity UI** — The token-limits admin page redirects to the full `TokenLimit` entity collection, providing add/edit/delete UI for granular per-scope limits (per-role, per-user, or site-total)
- **Unified token-counter hub** — Token Counter, Token Limits, Token Usage, Token Pricing, and Token Features are all accessible from the **Configuration → License Service** menu section
- **Hard block enforcement** — When a user is over quota the AI request is aborted before the model call, with a user-visible warning message and a watchdog notice
- **Bypass-permission aware** — Users with `bypass ai token usage limits` or `bypass license gate` are always exempt from quota enforcement; anonymous users are never subject to per-level quotas
- **Event priority ordering** — Fires at priority 90 on `PreGenerateResponseEvent`, after the token counter's per-rule enforcement at 100, so explicit AI-rule token limits always take precedence over level quotas
- **Graceful degradation** — Subscribes to `PreGenerateResponseEvent` only when the `drupal/ai` module's event class is present; the sub-module loads cleanly even on AI module versions that predate the event

---

## Subscription Management (license_service_subscriptions sub-module)

*Enable the `license_service_subscriptions` sub-module to bridge Commerce
Recurring subscriptions to the License Service role system.*

### Requirements

In addition to the core module requirements, this sub-module requires:

- `drupal/commerce_recurring` — subscription lifecycle events
- `drupal/commerce` — order and payment entities
- `drupal/state_machine` — workflow transition events

### Installation

1. Enable the parent module (`license_service`) first.
2. Enable the sub-module:
   ```
   drush en license_service_subscriptions
   ```
3. Run database updates:
   ```
   drush updb
   ```
4. Clear caches:
   ```
   drush cr
   ```

### Subscription Plans

Define **LicenseSubscriptionPlan** entities at
**Admin → Configuration → License Service → Plans**. Each plan links one or more
Commerce product variations to a local license tier. When a subscriber purchases
that variation, the module automatically grants the roles mapped to the plan's
tier.

### Settings

Configure the subscription enforcement behavior at
**Admin → Configuration → License Service → Plans → Settings**:

| Setting | Default | Description |
|---|---|---|
| Default fallback tier | `free` | Tier applied when a plan is deprecated and the user picks no replacement |
| Grace window (days) | 7 | Days after dunning ceiling before roles are revoked |
| Force-migrate grace (days) | 3 | Days after plan deprecation deadline before admin auto-migrates |
| Choice window (days) | 14 | How long a deprecation choose-plan link remains valid |
| Payment completion grace (days) | 3 | Extra time for a subscriber to complete payment after choosing a new plan |
| Renewal reminder (days) | 7 | How many days before renewal to send a reminder email |
| Max pause (days) | 90 | Maximum time a subscription may remain paused before auto-revocation |
| Notifications enabled | true | Global on/off switch for all subscription emails |

### Lifecycle events and role management

The sub-module wires into four Commerce Recurring / state-machine events:

| Event | Action |
|---|---|
| `commerce_subscription.activate.post_transition` | Grants roles mapped to the subscription's plan tier |
| `commerce_subscription.cancel.post_transition` | Revokes roles at period end; diffs other active subs before stripping any role |
| `commerce_subscription.expire.post_transition` | Hard-revokes roles when a subscription's end date passes |
| `commerce_recurring.payment_declined` | Marks `payment_method_failing`; stamps first-failure timestamp; sends dunning email on first decline only |

All mutating calls are **enqueued** as idempotent saga items via the
`license_service_subscriptions_saga` queue worker (30 s cron time). A queue-worker
failure does not propagate to the calling request.

Every state-mutating path checks `\Drupal::isConfigSyncing()` and skips during
config import to prevent test fixture and site build operations from triggering
real role changes.

### Plan deprecation workflow

When an administrator marks a `LicenseSubscriptionPlan` as inactive, the module:

1. Sends a **deprecation email** to every active and paused subscriber, with a
   single-use choose-plan URL (TTL = `choice_window_days`).
2. Subscribers click the link and pick a replacement plan on the
   **choose-plan form** (`/subscribe/choose/{token}`). The token is UID-bound and
   is burned at form submission, not on the GET (email-scanner safe).
3. On submission the sub-module records the intent (`migrating` state), schedules
   a period-end cancellation on the old Commerce subscription, and sends a
   **plan-chosen confirmation email**.

### Subscribers report

View all subscription state rows at
**Admin → Configuration → License Service → Plans → Subscribers**. The report
shows the current state badge (active / payment\_method\_failing / paused /
migrating / canceled), plan name, user account, and last-updated timestamp.

### Notifications

Four email keys are dispatched via Drupal's `MailManagerInterface`:

| Key | When sent |
|---|---|
| `plan_deprecated` | Administrator deactivates a plan; all affected subscribers |
| `renewal_reminder` | Cron: within `renewal_reminder_days` of a subscription's next renewal |
| `plan_chosen` | Subscriber submits the choose-plan form |
| `payment_failing` | First payment decline only (not on dunning retries) |

All emails are skipped when `notification_enabled = false` in the settings.

### Features (license_service_subscriptions)

- **Commerce Recurring integration** — Automatic role grant/revoke on subscription lifecycle transitions via state-machine events
- **Plan entity** — LicenseSubscriptionPlan config entities link product variations to license tiers; full CRUD admin UI
- **Seat cap enforcement** — SeatCapService::mayAssignRole() checked before every role grant; cache cleared after every state change
- **Manual-admin protection** — Subscriptions granted with `granted_by_action = manual_admin` are never auto-revoked
- **Idempotent saga queue** — All state-mutating operations are enqueued; duplicate event keys are silently dropped via UNIQUE constraint
- **Dunning grace window** — Payment failures set payment\_failed\_since once (COALESCE-guarded); cron revokes roles only after the grace window expires
- **Choose-plan tokens** — Single-use, UID-bound, SHA-256-hashed, TTL-configurable tokens for the deprecation email workflow
- **Plan deprecation workflow** — Deactivating a plan triggers deprecation emails to all active subscribers with choose-plan links
- **Subscribers report** — Admin report of all subscription state rows with state badges and user links
- **Subscription settings form** — All lifecycle timing, grace windows, and notification toggles in one admin form
- **Config-sync guard** — All state-mutating code is skipped during `drush cim` / config import

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

Tiers and their features are entirely defined by the site administrator in the
**License Tiers** configuration. The License Verification Server grants only a
maximum number of non-free authorized users per plan — billing, plan upgrades, and
seat-overage handling are managed on the
[LVS website](https://www.licenseverificationserver.com).

---

## Terms & Conditions

See [TERMS.md](TERMS.md) for the full Terms & Conditions governing use of this module.
You must accept the terms of service at
[licenseverificationserver.com/terms](https://www.licenseverificationserver.com/terms)
before activating a license key.

## License

GPL-2.0-or-later
