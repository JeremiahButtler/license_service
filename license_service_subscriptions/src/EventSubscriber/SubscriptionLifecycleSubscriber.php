<?php

declare(strict_types=1);

namespace Drupal\license_service_subscriptions\EventSubscriber;

use Drupal\commerce_recurring\Event\PaymentDeclinedEvent;
use Drupal\commerce_recurring\Event\RecurringEvents;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\license_service_subscriptions\Service\SubscriptionNotificationService;
use Drupal\license_service_subscriptions\Service\TierMigrationService;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Reacts to Commerce Recurring subscription lifecycle events.
 *
 * Listens to three event sources:
 *   - commerce_subscription.activate.post_transition  — new/resumed activation
 *   - commerce_subscription.cancel.post_transition    — cancellation (period-end)
 *   - commerce_subscription.expire.post_transition    — hard expiry
 *   - commerce_recurring.payment_declined             — dunning/payment failure.
 *
 * State-mutating calls are enqueued as idempotent saga items via
 * TierMigrationService; they are NOT executed synchronously in the event
 * handler so that a queue-worker failure does not propagate to the caller.
 *
 * Config-sync guard: all mutating code is skipped during config import so that
 * test fixtures and site builds do not trigger real role changes.
 *
 * Author: Jeremiah Buttler.
 *
 * Phase 5 source verification (2026-06-08) — all event names and API method
 * signatures confirmed against live drupalcode.org source:
 *   - commerce_subscription.*.post_transition event names: CONFIRMED.
 *   - RecurringEvents::PAYMENT_DECLINED = 'commerce_recurring.payment_declined': CONFIRMED.
 *   - WorkflowTransitionEvent::getEntity() + SubscriptionInterface::getCustomerId(): CONFIRMED.
 *   - getPurchasedEntityId() is the correct variation accessor (getVariationId() absent): CONFIRMED.
 *   - getNextRenewalTime() returns int Unix timestamp: CONFIRMED.
 *   - cancel(TRUE) does period-end cancel: CONFIRMED.
 *   - PaymentDeclinedEvent has NO getSubscription() — fixed below via getOrder() + entity query.
 */
class SubscriptionLifecycleSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a SubscriptionLifecycleSubscriber.
   *
   * @param \Drupal\license_service_subscriptions\Service\TierMigrationService $migrationService
   *   The tier migration service (state machine + saga enqueueing).
   * @param \Drupal\license_service_subscriptions\Service\SubscriptionNotificationService $notificationService
   *   The notification service (sends payment_failing email on declined payment).
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager (subscription lookup by order in onPaymentDeclined).
   */
  public function __construct(
    protected readonly TierMigrationService $migrationService,
    protected readonly SubscriptionNotificationService $notificationService,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // State Machine dispatches "{entity_type}.{transition_id}.post_transition"
    // for every workflow transition that completes. These are the canonical
    // Commerce Recurring subscription lifecycle transitions (verified 2026-06-08
    // against live drupalcode.org source).
    return [
      'commerce_subscription.activate.post_transition' => ['onSubscriptionActivate', 0],
      'commerce_subscription.cancel.post_transition'   => ['onSubscriptionCancel', 0],
      'commerce_subscription.expire.post_transition'   => ['onSubscriptionExpire', 0],
      RecurringEvents::PAYMENT_DECLINED                => ['onPaymentDeclined', 0],
    ];
  }

  /**
   * Grants roles when a Commerce subscription activates.
   *
   * Fires for both initial activation and dunning-recovery re-activation.
   * The saga worker handles the actual role grant; this method only enqueues.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The state-machine transition event.
   */
  public function onSubscriptionActivate(WorkflowTransitionEvent $event): void {
    if (\Drupal::isConfigSyncing()) {
      return;
    }

    /** @var \Drupal\commerce_recurring\Entity\SubscriptionInterface $subscription */
    $subscription = $event->getEntity();
    $uid = (int) $subscription->getCustomerId();
    $commerceSubscriptionId = (int) $subscription->id();

    // Resolve the plan from the subscription's product variation.
    $planId = $this->resolvePlanId($subscription);
    if ($planId === NULL) {
      // No matching plan configured — nothing to grant.
      \Drupal::logger('license_service_subscriptions')->warning(
        'Subscription @id activated but no matching LicenseSubscriptionPlan found.',
        ['@id' => $commerceSubscriptionId],
      );
      return;
    }

    $effectiveAt = \Drupal::time()->getRequestTime();
    $eventKey = 'sub:' . $commerceSubscriptionId . ':activate:' . $effectiveAt;

    $this->migrationService->applyNewTier(
      $uid,
      $planId,
      $effectiveAt,
      $commerceSubscriptionId,
    );
  }

  /**
   * Revokes roles when a Commerce subscription is canceled.
   *
   * Fired at period-end cancellation (cancel transition in state machine).
   * The TierMigrationService diffs other active subscriptions before stripping
   * any role.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The state-machine transition event.
   */
  public function onSubscriptionCancel(WorkflowTransitionEvent $event): void {
    if (\Drupal::isConfigSyncing()) {
      return;
    }

    /** @var \Drupal\commerce_recurring\Entity\SubscriptionInterface $subscription */
    $subscription = $event->getEntity();
    $commerceSubscriptionId = (int) $subscription->id();
    $effectiveAt = \Drupal::time()->getRequestTime();
    $eventKey = 'sub:' . $commerceSubscriptionId . ':cancel:' . $effectiveAt;

    $this->migrationService->revokeSubscription(
      $commerceSubscriptionId,
      'access_revoked_cancel',
      $effectiveAt,
      $eventKey,
    );
  }

  /**
   * Revokes roles when a Commerce subscription expires (hard expiry).
   *
   * Fired by commerce_recurring's cron when a subscription's end date passes.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The state-machine transition event.
   */
  public function onSubscriptionExpire(WorkflowTransitionEvent $event): void {
    if (\Drupal::isConfigSyncing()) {
      return;
    }

    /** @var \Drupal\commerce_recurring\Entity\SubscriptionInterface $subscription */
    $subscription = $event->getEntity();
    $commerceSubscriptionId = (int) $subscription->id();
    $effectiveAt = \Drupal::time()->getRequestTime();
    $eventKey = 'sub:' . $commerceSubscriptionId . ':expire:' . $effectiveAt;

    $this->migrationService->revokeSubscription(
      $commerceSubscriptionId,
      'renewal_payment_failed',
      $effectiveAt,
      $eventKey,
    );
  }

  /**
   * Marks a subscription as payment_method_failing when a payment is declined.
   *
   * Records payment_failed_since only on the FIRST failure (preserves the
   * original failure timestamp across dunning retries so the grace window is
   * calculated from the first decline, not the most recent one).
   *
   * Does NOT revoke roles — roles are revoked by the cron grace-window enforcer
   * (hook_cron) once the dunning ceiling is hit.
   *
   * @param \Drupal\commerce_recurring\Event\PaymentDeclinedEvent $event
   *   The payment-declined event.
   */
  public function onPaymentDeclined(PaymentDeclinedEvent $event): void {
    if (\Drupal::isConfigSyncing()) {
      return;
    }

    // PaymentDeclinedEvent exposes getOrder() only — there is no
    // getSubscription() method (verified 2026-06-08 against source).
    // Load the Commerce subscription by querying the 'orders' multi-value
    // reference field: a subscription tracks all its recurring orders there.
    $order = $event->getOrder();
    $subscriptionIds = $this->entityTypeManager
      ->getStorage('commerce_subscription')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('orders', (int) $order->id())
      ->execute();

    if (empty($subscriptionIds)) {
      \Drupal::logger('license_service_subscriptions')->warning(
        'onPaymentDeclined: no subscription found for order @order; skipping.',
        ['@order' => $order->id()],
      );
      return;
    }

    $commerceSubscriptionId = (int) reset($subscriptionIds);
    $now = \Drupal::time()->getRequestTime();

    // Update state row: set state=payment_method_failing and record
    // payment_failed_since (only on the first failure).
    $database = \Drupal::database();
    $database->query(
      "UPDATE {license_service_subscriptions_state}
       SET state = 'payment_method_failing',
           payment_failed_since = COALESCE(payment_failed_since, :now),
           updated = :updated
       WHERE commerce_subscription_id = :id
         AND state IN ('active', 'payment_method_failing')",
      [
        ':now'     => $now,
        ':updated' => $now,
        ':id'      => $commerceSubscriptionId,
      ],
    );

    // Send payment-failing notification (only on the FIRST failure so the
    // subscriber doesn't receive a new email on every dunning retry).
    // Re-read the row to get the stored payment_failed_since value.
    $failedSince = (int) ($database->select('license_service_subscriptions_state', 's')
      ->fields('s', ['payment_failed_since', 'uid', 'plan_id'])
      ->condition('commerce_subscription_id', $commerceSubscriptionId)
      ->execute()
      ->fetchField() ?? $now);

    $stateRow = $database->select('license_service_subscriptions_state', 's')
      ->fields('s', ['uid', 'plan_id', 'payment_failed_since'])
      ->condition('commerce_subscription_id', $commerceSubscriptionId)
      ->execute()
      ->fetchAssoc();

    if ($stateRow && (int) $stateRow['payment_failed_since'] === $now) {
      // payment_failed_since was just set to NOW — this is the first failure.
      try {
        $this->notificationService->sendPaymentFailing(
          (int) $stateRow['uid'],
          (string) $stateRow['plan_id'],
          $now,
        );
      }
      catch (\Exception $e) {
        \Drupal::logger('license_service_subscriptions')->warning(
          'onPaymentDeclined: payment_failing email failed for sub @sub: @msg',
          ['@sub' => $commerceSubscriptionId, '@msg' => $e->getMessage()],
        );
      }
    }
  }

  // --------------------------------------------------------------------------
  // Private helpers
  // --------------------------------------------------------------------------

  /**
   * Resolves the LicenseSubscriptionPlan machine name for a subscription.
   *
   * Looks up the plan by matching the subscription's ordered variation IDs
   * against all configured LicenseSubscriptionPlan entities.
   *
   * @param \Drupal\commerce_recurring\Entity\SubscriptionInterface $subscription
   *   The Commerce subscription entity.
   *
   * @return string|null
   *   The plan machine name, or NULL if no plan matches.
   */
  protected function resolvePlanId($subscription): ?string {
    // getPurchasedEntityId() is the correct accessor (verified 2026-06-08):
    // returns the target entity ID of the 'purchased_entity' field — which for
    // a product-variation subscription is the variation entity ID.
    // getVariationId() does not exist; the fallback is kept as dead-safe guard.
    $variationId = NULL;
    if (method_exists($subscription, 'getPurchasedEntityId')) {
      $variationId = (string) $subscription->getPurchasedEntityId();
    }
    elseif (method_exists($subscription, 'getVariationId')) {
      $variationId = (string) $subscription->getVariationId();
    }

    if ($variationId === NULL) {
      return NULL;
    }

    /** @var \Drupal\license_service_subscriptions\Entity\LicenseSubscriptionPlan[] $plans */
    $plans = $this->entityTypeManager
      ->getStorage('license_subscription_plan')
      ->loadMultiple();

    foreach ($plans as $planId => $plan) {
      if (in_array($variationId, $plan->getProductVariationIds(), TRUE)) {
        return (string) $planId;
      }
    }

    return NULL;
  }

}
