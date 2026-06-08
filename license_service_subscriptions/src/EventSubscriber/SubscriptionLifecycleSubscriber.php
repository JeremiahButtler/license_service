<?php

declare(strict_types=1);

namespace Drupal\license_service_subscriptions\EventSubscriber;

use Drupal\commerce_recurring\Event\PaymentDeclinedEvent;
use Drupal\commerce_recurring\Event\RecurringEvents;
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
 *   - commerce_recurring.payment_declined             — dunning/payment failure
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
 * @todo Phase 5: verify state-machine event names and WorkflowTransitionEvent
 *   method signatures against the installed Commerce / state_machine versions.
 * @todo Phase 5: verify RecurringEvents constants and PaymentDeclinedEvent
 *   method signatures against the installed commerce_recurring version.
 */
class SubscriptionLifecycleSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a SubscriptionLifecycleSubscriber.
   *
   * @param \Drupal\license_service_subscriptions\Service\TierMigrationService $migrationService
   *   The tier migration service (state machine + saga enqueueing).
   */
  public function __construct(
    protected readonly TierMigrationService $migrationService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // State Machine dispatches "{entity_type}.{transition_id}.post_transition"
    // for every workflow transition that completes. These are the canonical
    // Commerce Recurring subscription lifecycle transitions.
    //
    // @todo Phase 5: if commerce_recurring patches state names, update below.
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
    // @todo Phase 5: confirm getItems() or variation resolver for plan lookup.
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
   *
   * @todo Phase 5: verify PaymentDeclinedEvent::getSubscription() exists and
   *   getOrder() vs getPayment() for the failed order.
   */
  public function onPaymentDeclined(PaymentDeclinedEvent $event): void {
    if (\Drupal::isConfigSyncing()) {
      return;
    }

    $subscription = $event->getSubscription();
    $commerceSubscriptionId = (int) $subscription->id();
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
   *
   * @todo Phase 5: verify correct API for retrieving variation ID from
   *   SubscriptionInterface (getVariationId() vs getType() vs items).
   */
  protected function resolvePlanId($subscription): ?string {
    // @todo Phase 5: confirm the variation ID accessor on SubscriptionInterface.
    // Common candidates: $subscription->getPurchasedEntityId(),
    //   $subscription->getVariationId(), or via getItems().
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
    $plans = \Drupal::entityTypeManager()
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
