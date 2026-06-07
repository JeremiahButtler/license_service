# License Service — Server Setup & First Activation

This document covers the one-time steps needed before site administrators can
activate a License Service license. It assumes the License Verification Server is
already running at `https://www.licenseverificationserver.com`.

---

## Step 1 — Register the `drupal_license` product on the server

This is a **one-time admin task** on the License Manager. You need the admin key
or an admin account on the License Verification Server.

### Via the web dashboard

1. Open a browser and go to `https://www.licenseverificationserver.com`.
2. Sign in with your admin credentials.
3. In the left menu, click **Products**.
4. Click **New Product**.
5. Fill in:
   - **Product ID:** `drupal_license` *(must be exactly this string — it's hardcoded in the module)*
   - **Name:** `License Service (Drupal Module)`
   - **Description:** `Drupal 10/11 content access control module`
6. Configure the tier/feature matrix to match what you want to offer:
   - Recommended tiers: `free`, `standard`, `pro`, `enterprise`
   - Recommended features (added as JSON flags on the license token):
     - `max_premium_users` (integer — seat cap for premium roles, 0 = unlimited)
     - `allowed_level_count` (integer — how many non-free levels the admin may configure)
     - `field_gating` (boolean — enables per-field access control)
     - `download_gating` (boolean — enables file/media download gating)
     - `metered_views` (boolean — enables time-windowed view counters)
     - `quotas` (boolean — enables create/edit quota enforcement)
7. Click **Save**.

### Via the desktop app (LicenseAdmin.exe)

1. Open `LicenseAdmin.exe`.
2. Sign in with your admin credentials.
3. Go to the **Products** tab.
4. Click **New Product** and fill in the same fields as above.
5. Click **Save**.

---

## Step 2 — Generate a test API key

The simplest path is to self-generate a key from the account portal (no admin
action needed):

1. Go to `https://www.licenseverificationserver.com/account` and register or log in.
2. Click **API Keys → Generate API key**.
3. Copy the raw key shown on the page — it is not displayed again.

**Alternative — admin-issue via the web dashboard:**

1. In the admin dashboard, go to **End Users**, select (or create) the user, and
   click **API Keys…**.
2. Click **Generate** — set name, tier, and product (`drupal_license`).
3. Copy the raw key from the dialog.

---

## Step 3 — Activate the module on a Drupal site

1. Install the `license_service` module on your Drupal site:
   ```
   drush en license_service
   ```
2. Navigate to **Admin → Configuration → License Service → Settings**.
3. Paste your API key into the **API key** field.
4. Confirm the **Server URL** is `https://www.licenseverificationserver.com`.
5. Click **Activate**.
6. The status panel should update to show **Licensed** with your tier and expiry.

---

## Step 4 — Verify the activation on the server

1. In the admin dashboard, go to **End Users → [user] → API Keys** and find your key.
2. Confirm the seat shows as **activated** with the Drupal site's machine ID.
3. The machine ID visible in the Drupal status panel
   (**Configuration → License Service → Status**) should match the server record.

---

## Troubleshooting

| Problem | Likely cause | Fix |
|---------|-------------|-----|
| "Server response did not include a license token" | Product ID mismatch | Confirm the product is registered as exactly `drupal_license` |
| "Cannot reach the licensing server" | Firewall / HTTPS issue | Confirm the Drupal server can make outbound HTTPS requests to `www.licenseverificationserver.com` |
| "License token is for a different product" | Wrong product registered | Delete the cached token in Drupal state (`drush state:del license_service.cached_token`) and re-activate |
| "Token signature verification failed" | Public key mismatch | The server's signing key may have rotated; clear `license_service.public_key` state and re-activate |
| Seat cap errors in the admin UI | `max_premium_users` feature not set on the license | Edit the license on the server and add `max_premium_users` as a feature flag |

---

## Rotating the server's signing key

If the License Verification Server's Ed25519 signing key is ever rotated:

1. All existing cached tokens will fail verification on the next cron run.
2. Each Drupal site will enter the **offline grace window** (default 24h).
3. During that window, site admins must go to **License Service → Settings** and
   click **Activate** to fetch a fresh token signed with the new key. The module
   auto-fetches the new public key from `GET /api/v1/pubkey` on each activation.
4. No code changes are needed in the module — the public key is always fetched
   from the server, never hardcoded.

---

*Author: Jeremiah Buttler*
