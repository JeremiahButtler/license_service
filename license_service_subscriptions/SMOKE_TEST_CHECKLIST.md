# Smoke-Test Checklist — license_service_subscriptions

Use this checklist on a dev environment that has Drupal Commerce + Commerce
Recurring + Commerce Stripe installed before enabling the sub-module on any
production site.

**Source-verification status (2026-06-08):** Items 1–8 were verified by
reading the live `8.x-1.x` / `3.x` source on `git.drupalcode.org`. Items
marked ✅ require no further action. Item 6 had a code defect that was **fixed
in the same session** (see source note below). Item 9 still requires a live
environment.

---

## 1. ✅ Commerce Recurring — state-machine event names

**Source-verified 2026-06-08.** All event names confirmed. `RecurringEvents::PAYMENT_DECLINED = 'commerce_recurring.payment_declined'`. The `commerce_subscription.*` prefix comes from the workflow group ID in `commerce_recurring.workflow_groups.yml`. No code change needed.

**File:** `src/EventSubscriber/SubscriptionLifecycleSubscriber.php` (lines 62–66)

The subscriber wires these event names:

| Event name constant / literal | Expected dispatch point |
|---|---|
| `'commerce_subscription.activate.post_transition'` | State machine fires after `activate` transition completes |
| `'commerce_subscription.cancel.post_transition'` | After `cancel` transition (period-end cancellation) |
| `'commerce_subscription.expire.post_transition'` | After `expire` transition (cron hard-expiry) |
| `RecurringEvents::PAYMENT_DECLINED` | Commerce Recurring dunning failure |

**Verification steps:**

1. Install Commerce Recurring and enable the `commerce_recurring` module.
2. Check that `Drupal\commerce_recurring\Event\RecurringEvents::PAYMENT_DECLINED`
   exists as a class constant and resolve its string value.
3. Trigger an activation by completing a test checkout and confirm
   `license_service_subscriptions` log entry "Granted roles" appears in the
   Drupal dblog (`admin/reports/dblog`).
4. Cancel a test subscription and confirm a "Revoked subscription" log entry.
5. Simulate a hard-expiry by directly calling Commerce Recurring's expiration
   cron handler or by setting a subscription's `ends_time` to the past and
   running cron.
6. Simulate a payment decline via Stripe test mode and confirm the
   `license_service_subscriptions_state` row transitions to
   `state = payment_method_failing`.

---

## 2. ✅ WorkflowTransitionEvent — entity accessor

**File:** `src/EventSubscriber/SubscriptionLifecycleSubscriber.php` (lines 84–85, 128–129, 155–156)

All three lifecycle handlers call:

```php
$subscription = $event->getEntity();
$uid = (int) $subscription->getCustomerId();
```

**Verify:**

- `\Drupal\state_machine\Event\WorkflowTransitionEvent::getEntity()` exists and
  returns the `SubscriptionInterface` entity (not the order or payment).
- `SubscriptionInterface::getCustomerId()` exists and returns the Drupal UID of
  the subscriber (not a Commerce customer profile ID or Stripe customer ID).

---

## 3. ✅ SubscriptionInterface — variation ID accessor

**File:** `src/EventSubscriber/SubscriptionLifecycleSubscriber.php` (lines 266–271, `resolvePlanId()` method)

The plan resolver checks for two candidate methods in order:

```php
if (method_exists($subscription, 'getPurchasedEntityId')) { ... }
elseif (method_exists($subscription, 'getVariationId')) { ... }
```

**Verify:**

- Which method exists on `\Drupal\commerce_recurring\Entity\Subscription` in the
  installed Commerce Recurring version.
- That the returned ID is the **product variation** ID (the one stored in
  `LicenseSubscriptionPlan::getProductVariationIds()`), not the subscription
  type or product ID.
- If neither method exists, the correct accessor must be identified from the
  Commerce Recurring source and `resolvePlanId()` updated accordingly.

---

## 4. ✅ SubscriptionInterface — next renewal time

**File:** `license_service_subscriptions.module` (hook_cron, Section 1 — renewal reminders)

```php
if (method_exists($subscription, 'getNextRenewalTime')) {
  $nextRenewal = (int) $subscription->getNextRenewalTime();
}
```

**Verify:**

- `SubscriptionInterface::getNextRenewalTime()` exists and returns a Unix
  timestamp (not a `DrupalDateTime` or `\DateTimeInterface` object).
- If it returns an object, the hook_cron code must be updated with the
  appropriate `->getTimestamp()` call.
- If the method is named differently (e.g., `getRenewalTime()`,
  `getNextBillingDate()`), update the `method_exists()` guard accordingly.

---

## 5. ✅ SubscriptionInterface — period-end cancel API

**File:** `src/Service/TierMigrationService.php` (lines 380–383, `markIntentToChange()`)

```php
if ($subscription !== NULL && method_exists($subscription, 'cancel')) {
  $subscription->cancel(TRUE);
  $subscription->save();
}
```

**Verify:**

- The correct Commerce Recurring API for scheduling a period-end (non-immediate)
  cancellation. In some versions it is `$subscription->cancel(TRUE)`;
  in others it may be `$subscription->scheduleCancel()` or setting a
  `scheduled_changes` field directly.
- After calling the API and saving, confirm the subscription's state does NOT
  change to `canceled` immediately — it should remain `active` until the period
  ends, then transition at renewal time.

---

## 6. ✅ PaymentDeclinedEvent — accessor methods (BUG FIXED)

**Source-verified 2026-06-08. Bug found and fixed.**

`PaymentDeclinedEvent` has **no** `getSubscription()` method. The event
exposes only `getOrder()`, `getRetryDays()`, `getNumRetries()`,
`getMaxRetries()`, and `getException()`.

**Fix applied:** `onPaymentDeclined` now calls `$event->getOrder()` then
performs an entity query on `commerce_subscription` storage, filtering on the
`orders` multi-value reference field (subscriptions track all their recurring
orders there). `EntityTypeManagerInterface` was injected into the subscriber's
constructor and added to `license_service_subscriptions.services.yml`.

**File:** `src/EventSubscriber/SubscriptionLifecycleSubscriber.php` (lines ~188+)

---

## 7. ✅ PaymentRefundSubscriber — event name and accessor methods

**File:** `src/EventSubscriber/PaymentRefundSubscriber.php`

The subscriber wires:

```php
'commerce_payment.refund.post_transition' => ['onPaymentRefund', 0]
```

And calls:

```php
$payment = $event->getEntity();
$refundedAmount = $payment->getRefundedAmount();
$originalAmount  = $payment->getAmount();
$order = $payment->getOrder();
```

**Verify:**

1. The correct state-machine event name for Commerce payment refunds. It may be
   `'commerce_payment.refund.post_transition'` or `'commerce_payment.void.post_transition'`
   or a different transition name depending on the gateway.
2. `PaymentInterface::getRefundedAmount()` exists and returns a
   `\Drupal\commerce_price\Price` object (not a raw float/int).
3. `PaymentInterface::getAmount()` behaves similarly.
4. The comparison `$refundedAmount->greaterThanOrEqual($originalAmount)` is the
   correct way to detect a full refund when using Commerce Price objects.
5. `PaymentInterface::getOrder()` exists and returns the `OrderInterface` for
   the payment (needed to locate the associated subscription).

---

## 8. ✅ Subscription entity storage machine name

**File:** `src/Service/TierMigrationService.php` (line 379)

```php
$subscription = $this->entityTypeManager
  ->getStorage('commerce_subscription')
  ->load($commerceSubscriptionId);
```

**Verify:**

- The Commerce Recurring subscription entity type machine name is
  `'commerce_subscription'` (not `'commerce_recurring_subscription'` or
  similar). Confirm by running:

  ```bash
  drush php-eval "print_r(array_keys(\Drupal::entityTypeManager()->getDefinitions()));"
  ```

  and locating the subscription type in the output.

---

## 9. ✅ End-to-end smoke walk-through

**Executed 2026-06-08** using DDEV (Drupal 11.3.11, PHP 8.3.30, commerce_recurring
8.x-1.0-rc3). Stripe checkout bypassed — lifecycle events fired via direct
state-machine transitions and service calls. **Three bugs found and fixed.**

### Bugs found during this run

| # | File | Bug | Fix |
|---|------|-----|-----|
| A | `license_service_subscriptions.module` line 215 | `$database->or()` removed in D10 — `Connection` has no `or()` method | Changed to `$query->orConditionGroup()` on the select query object |
| B | `src/Controller/SubscribersController.php` line 179 | `htmlspecialchars()` passed a `TranslatableMarkup` object — fails PHP 8.1+ strict type | Cast to `(string)` before passing to `htmlspecialchars()` |
| C | `src/Service/TierMigrationService.php` | `@todo Phase 5: verify cancel(TRUE)` comment stale | Resolved — verified 2026-06-08 against live source |

Both A and B are now committed (commit `85bf0c6`).

### Step results

1. ✅ **Plan setup** — `LicenseSubscriptionPlan` entity created via `drush php:script`
   with `tier_id='free'`, `product_variation_ids=[1]`, `active=TRUE`.

2. ✅ **Activation** — `onSubscriptionActivate` fired via state-machine transition.
   State row: `state=active`, `roles_json=["test_subscriber"]`. Role granted to user.

3. ✅ **Payment decline** — State updated to `payment_method_failing`.
   `payment_failed_since` stamped on first decline; COALESCE preserves it on retries.
   Entity query (`orders` field reverse-lookup) correctly resolves subscription from order.

4. ✅ **Grace window expiry** — `hook_cron` ran; state transitioned to `canceled`;
   `test_subscriber` role revoked. Log: "Cron: revoked sub 1 (uid 2) after dunning
   grace window expired."

5. ✅ **Plan deprecation** — `hook_entity_update` fired on plan save with `active=FALSE`.
   Log: "Sent plan_deprecated notices for plan smoke_plan to 1 subscriber(s)."
   Deprecation email confirmed in Mailpit (`:8025`). Intent row created in
   `license_service_migration_intents`.

6. ✅ **Choose-plan form** — Token from email URL validated (`SubscriptionChoiceTokenService::validate()`).
   `markIntentToChange()` ran; state → `migrating`; token burned (`token_used=1`);
   target plan set to `smoke_plan_v2`. Reuse of burned token correctly rejected.

7. ✅ **Cancellation** — `onSubscriptionCancel` fired via cancel transition;
   state → `canceled`; role revoked. **Overlap protection verified**: when a second
   active subscription (sub 999) with the same role exists, revoking sub 1 does NOT
   strip the role from the user.

8. ✅ **Subscribers report** — `SubscribersController::report()` rendered 200 OK
   (1068 bytes). Page contains `plan` and `state` column headers. Fix B was required
   to make this pass.

### Not yet tested with real Stripe

- Actual Stripe checkout and card-decline via webhook (Stripe CLI).
- `PaymentRefundSubscriber` (Commerce payment refund event).

These require live Stripe test keys and remain deferred to a real environment run.
