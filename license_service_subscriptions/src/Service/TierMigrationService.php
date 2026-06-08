<?php

declare(strict_types=1);

namespace Drupal\license_service_subscriptions\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;

/**
 * Implements the 5-operation plan-deprecation state machine.
 *
 * Operations (never a single migrate() — always explicit steps):
 *   - revokeSubscription()     — terminal; strips roles attributable to THIS sub.
 *   - suspendSubscription()    — non-terminal; state=paused; roles intact.
 *   - resumeSubscription()     — paused → active; no role change.
 *   - applyNewTier()           — grants roles for a new plan.
 *   - markIntentToChange()     — records user's choose-plan decision.
 *
 * Each operation is enqueued as an idempotent worker via the durability layer
 * (queue-based saga). The queue worker retries on failure. The audit table's
 * event_key UNIQUE constraint prevents duplicate processing from webhook
 * replays.
 *
 * Author: Jeremiah Buttler.
 *
 * @todo Phase 2: implement all five operations. Stubs only in Phase 1.
 */
class TierMigrationService {

  /**
   * Constructs a TierMigrationService.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection (for state + audit table writes).
   * @param \Drupal\license_service_subscriptions\Service\SubscriptionRoleGrantService $roleGrant
   *   The role-grant service.
   * @param \Drupal\license_service_subscriptions\Service\SubscriptionChoiceTokenService $tokenService
   *   The choose-plan token service.
   * @param \Drupal\Core\Queue\QueueFactory $queue
   *   The queue factory (for queuing saga worker steps).
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly SubscriptionRoleGrantService $roleGrant,
    protected readonly SubscriptionChoiceTokenService $tokenService,
    protected readonly QueueFactory $queue,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Terminal operation: revokes all roles granted by the given subscription.
   *
   * Diffs the union of the user's other active subscriptions before stripping
   * any role. Skips rows with granted_by_action = 'manual_admin'. Writes an
   * audit row with the supplied event_key (UNIQUE; duplicate = silent no-op).
   *
   * @param int $commerceSubscriptionId
   *   Commerce subscription entity ID.
   * @param string $reason
   *   Audit reason: plan_deprecated | renewal_payment_failed |
   *   admin_force_migrated | access_revoked_refund.
   * @param int $effectiveAt
   *   Unix timestamp when the revocation takes effect (usually now()).
   * @param string $eventKey
   *   Idempotency key. Duplicates are silently dropped.
   *
   * @todo Phase 2: implement.
   */
  public function revokeSubscription(int $commerceSubscriptionId, string $reason, int $effectiveAt, string $eventKey): void {
    // @todo Phase 2.
  }

  /**
   * Non-terminal: suspends (pauses) a subscription without stripping roles.
   *
   * Sets state = 'paused'. Roles remain intact. A cron job auto-revokes
   * after max_pause_days. Deprecated plans still migrate paused subscribers
   * at their migration deadline — pause does not grant immortality.
   *
   * @param int $commerceSubscriptionId
   *   Commerce subscription entity ID.
   * @param string $reason
   *   Human-readable reason for logging.
   *
   * @todo Phase 2: implement.
   */
  public function suspendSubscription(int $commerceSubscriptionId, string $reason): void {
    // @todo Phase 2.
  }

  /**
   * Resumes a paused subscription back to active.
   *
   * State = 'paused' → 'active'. No role change (roles were kept intact).
   *
   * @param int $commerceSubscriptionId
   *   Commerce subscription entity ID.
   *
   * @todo Phase 2: implement.
   */
  public function resumeSubscription(int $commerceSubscriptionId): void {
    // @todo Phase 2.
  }

  /**
   * Grants roles for a new or replacement subscription plan.
   *
   * Checks SeatCapService before granting. Records source_sub_id in the state
   * row for migration provenance. Used both for new checkouts and as the second
   * step in a revoke → apply migration.
   *
   * @param int $uid
   *   Drupal user ID.
   * @param string $planId
   *   Target LicenseSubscriptionPlan machine name.
   * @param int $effectiveAt
   *   Unix timestamp when the grant becomes effective.
   * @param int|null $sourceSubscriptionId
   *   Source commerce_subscription ID (migration provenance), or NULL for fresh grants.
   *
   * @todo Phase 2: implement.
   */
  public function applyNewTier(int $uid, string $planId, int $effectiveAt, ?int $sourceSubscriptionId = NULL): void {
    // @todo Phase 2.
  }

  /**
   * Records a user's plan-choice and burns the single-use token.
   *
   * Burns the token at commit (NOT on GET, to avoid email-scanner prefetch
   * burning it). Calls $subscription->cancel(TRUE) on the old sub. Records
   * intent independently of payment so period-end migration has a deterministic
   * source. If the new tier is free, records effective_at = period-end and no
   * payment_deadline. If the new tier is paid, sets payment_deadline =
   * now() + payment_completion_grace_days.
   *
   * @param int $commerceSubscriptionId
   *   Old subscription being scheduled for cancellation.
   * @param string|null $targetPlanId
   *   Chosen plan machine name; NULL = fallback.
   * @param string $token
   *   The raw single-use token from the URL.
   * @param int $uid
   *   The authenticated user's UID (must match the token's UID binding).
   *
   * @todo Phase 2: implement.
   */
  public function markIntentToChange(int $commerceSubscriptionId, ?string $targetPlanId, string $token, int $uid): void {
    // @todo Phase 2.
  }

}
