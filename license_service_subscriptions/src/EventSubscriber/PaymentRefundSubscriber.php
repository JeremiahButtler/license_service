<?php

declare(strict_types=1);

namespace Drupal\license_service_subscriptions\EventSubscriber;

use Drupal\license_service_subscriptions\Service\TierMigrationService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Reacts to Commerce payment refund events.
 *
 * A full refund triggers immediate role revocation (same as cancellation).
 * A partial refund is audit-only — no role change, but an audit row is written
 * so that admins can see the refund occurred.
 *
 * Config-sync guard: all mutating code is skipped during config import.
 *
 * Author: Jeremiah Buttler.
 *
 * @todo Phase 5: verify commerce_payment event names and PaymentEvent method
 *   signatures against the installed Commerce version. Commerce dispatches
 *   payment events via WorkflowTransitionEvent for state-machine transitions,
 *   or PaymentEvent for explicit payment events. Confirm which fires for refund.
 */
class PaymentRefundSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a PaymentRefundSubscriber.
   *
   * @param \Drupal\license_service_subscriptions\Service\TierMigrationService $migrationService
   *   The tier migration service.
   */
  public function __construct(
    protected readonly TierMigrationService $migrationService,
  ) {}

  /**
   * {@inheritdoc}
   *
   * @todo Phase 5: confirm the correct event name for Commerce payment refunds.
   *   Candidates:
   *   - 'commerce_payment.payment.presave' (inspect state diff)
   *   - 'commerce_payment.refund.post_transition' (state machine transition)
   *   - WorkflowTransitionEvent on payment entity 'refund' transition
   */
  public static function getSubscribedEvents(): array {
    return [
      // State Machine transition on commerce_payment entity.
      // @todo Phase 5: verify transition id is 'refund' in Commerce payment workflow.
      'commerce_payment.refund.post_transition' => ['onPaymentRefund', 0],
    ];
  }

  /**
   * Handles a payment refund transition.
   *
   * - Full refund (refunded_amount >= total_amount): revoke subscription roles.
   * - Partial refund: write audit row only; no role change.
   *
   * @param object $event
   *   The workflow transition event from state_machine.
   *   Type-hinted as object to avoid hard dependency on a specific event class
   *   until Phase 5 verifies the exact class.
   *
   * @todo Phase 5: replace `object` with the concrete event class once verified.
   */
  public function onPaymentRefund(object $event): void {
    if (\Drupal::isConfigSyncing()) {
      return;
    }

    // @todo Phase 5: verify entity accessor. WorkflowTransitionEvent has
    // getEntity(); PaymentEvent has getPayment().
    $payment = NULL;
    if (method_exists($event, 'getEntity')) {
      $payment = $event->getEntity();
    }
    elseif (method_exists($event, 'getPayment')) {
      $payment = $event->getPayment();
    }

    if ($payment === NULL) {
      return;
    }

    // Determine full vs partial refund.
    // @todo Phase 5: verify price accessor methods on commerce_payment entity.
    $isFullRefund = $this->isFullRefund($payment);

    if (!$isFullRefund) {
      // Partial refund: audit only.
      $this->writePartialRefundAudit($payment);
      return;
    }

    // Full refund: revoke the subscription tied to this payment.
    $order = $this->getOrderFromPayment($payment);
    if ($order === NULL) {
      \Drupal::logger('license_service_subscriptions')->warning(
        'Full refund on payment @id but could not resolve the Commerce order.',
        ['@id' => $payment->id()],
      );
      return;
    }

    $commerceSubscriptionId = $this->getSubscriptionIdFromOrder($order);
    if ($commerceSubscriptionId === NULL) {
      // Non-subscription order — nothing to revoke.
      return;
    }

    $effectiveAt = \Drupal::time()->getRequestTime();
    $eventKey = 'payment:' . $payment->id() . ':full_refund:' . $effectiveAt;

    $this->migrationService->revokeSubscription(
      $commerceSubscriptionId,
      'access_revoked_refund',
      $effectiveAt,
      $eventKey,
    );
  }

  // --------------------------------------------------------------------------
  // Private helpers
  // --------------------------------------------------------------------------

  /**
   * Returns TRUE if the payment represents a full refund.
   *
   * A payment is fully refunded when its state is 'refunded' and the refunded
   * amount equals the original payment amount.
   *
   * @param object $payment
   *   The commerce_payment entity.
   *
   * @return bool
   *   TRUE for a full refund.
   *
   * @todo Phase 5: verify refundedAmount vs getAmount() API on commerce_payment.
   */
  protected function isFullRefund(object $payment): bool {
    // @todo Phase 5: confirm these accessor names in the installed Commerce version.
    try {
      if (!method_exists($payment, 'getRefundedAmount') || !method_exists($payment, 'getAmount')) {
        // Can't determine — assume full refund on a payment in refunded state.
        return TRUE;
      }
      $refunded = $payment->getRefundedAmount();
      $total    = $payment->getAmount();
      // Price comparison: refunded >= total means full refund.
      return $refunded !== NULL && $refunded->compareTo($total) >= 0;
    }
    catch (\Exception) {
      return TRUE;
    }
  }

  /**
   * Returns the Commerce order from a payment entity.
   *
   * @param object $payment
   *   The commerce_payment entity.
   *
   * @return object|null
   *   The commerce_order entity, or NULL on failure.
   */
  protected function getOrderFromPayment(object $payment): ?object {
    try {
      if (method_exists($payment, 'getOrder')) {
        return $payment->getOrder();
      }
      if (method_exists($payment, 'getOrderId')) {
        $orderId = (int) $payment->getOrderId();
        return \Drupal::entityTypeManager()
          ->getStorage('commerce_order')
          ->load($orderId) ?: NULL;
      }
    }
    catch (\Exception) {
    }
    return NULL;
  }

  /**
   * Looks up the Commerce subscription ID from a recurring order.
   *
   * Commerce Recurring links orders to subscriptions via the subscription
   * reference field on the order or via the recurring order type.
   *
   * @param object $order
   *   The commerce_order entity.
   *
   * @return int|null
   *   The commerce_subscription entity ID, or NULL if not a recurring order.
   *
   * @todo Phase 5: verify the subscription reference field name on recurring
   *   orders. Candidates: 'subscription_id', 'subscriptions'.
   */
  protected function getSubscriptionIdFromOrder(object $order): ?int {
    try {
      // Commerce Recurring stores a 'subscription_id' field on the order.
      // @todo Phase 5: confirm field name via $order->getFieldDefinitions().
      foreach (['subscription_id', 'subscriptions'] as $fieldName) {
        if ($order->hasField($fieldName)) {
          $value = $order->get($fieldName)->getValue();
          if (!empty($value[0]['target_id'])) {
            return (int) $value[0]['target_id'];
          }
        }
      }
    }
    catch (\Exception) {
    }
    return NULL;
  }

  /**
   * Writes an audit row for a partial refund (no role change).
   *
   * @param object $payment
   *   The commerce_payment entity.
   */
  protected function writePartialRefundAudit(object $payment): void {
    try {
      $orderId = method_exists($payment, 'getOrderId') ? (int) $payment->getOrderId() : NULL;
      $now = \Drupal::time()->getRequestTime();

      \Drupal::database()->insert('license_service_migrations')
        ->fields([
          'uid'                      => 0,
          'from_plan_id'             => NULL,
          'to_plan_id'               => NULL,
          'event_type'               => 'payment_partially_refunded',
          'event_key'                => 'payment:' . $payment->id() . ':partial_refund:' . $now,
          'commerce_subscription_id' => NULL,
          'commerce_order_id'        => $orderId,
          'force_migrated'           => 0,
          'raw_json'                 => json_encode(['payment_id' => $payment->id()]),
          'created'                  => $now,
        ])
        ->execute();
    }
    catch (\Exception $e) {
      \Drupal::logger('license_service_subscriptions')->warning(
        'Failed to write partial-refund audit: @msg',
        ['@msg' => $e->getMessage()],
      );
    }
  }

}
