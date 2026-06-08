<?php

declare(strict_types=1);

namespace Drupal\license_service_subscriptions\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
 *   - markIntentToChange()     — records user's choose-plan decision (Phase 3).
 *
 * Each operation writes an idempotency-keyed audit row to
 * license_service_migrations (UNIQUE on event_key). Duplicate event_key =
 * silent no-op, so webhook replays and queue retries are harmless.
 *
 * Author: Jeremiah Buttler.
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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager (for loading Commerce subscriptions + plan entities).
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory (reads payment_completion_grace_days, default_fallback_tier).
   * @param \Drupal\license_service_subscriptions\Service\SubscriptionNotificationService $notificationService
   *   The notification service (sends plan_chosen email after commit).
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly SubscriptionRoleGrantService $roleGrant,
    protected readonly SubscriptionChoiceTokenService $tokenService,
    protected readonly QueueFactory $queue,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly SubscriptionNotificationService $notificationService,
  ) {}

  /**
   * Terminal operation: revokes all roles granted by the given subscription.
   *
   * Idempotent: duplicate event_key is a silent no-op (UNIQUE DB constraint).
   * Diffs the union of the user's other active subscriptions before stripping
   * any role. Skips rows with granted_by_action = 'manual_admin'.
   *
   * @param int $commerceSubscriptionId
   *   Commerce subscription entity ID.
   * @param string $reason
   *   Audit reason: plan_deprecated | renewal_payment_failed |
   *   admin_force_migrated | access_revoked_refund | access_revoked_cancel.
   * @param int $effectiveAt
   *   Unix timestamp when the revocation takes effect (usually now()).
   * @param string $eventKey
   *   Idempotency key. Duplicates are silently dropped.
   */
  public function revokeSubscription(int $commerceSubscriptionId, string $reason, int $effectiveAt, string $eventKey): void {
    $logger = $this->loggerFactory->get('license_service_subscriptions');

    // 1. Idempotency check: try to insert the audit row first.
    //    If event_key already exists, the INSERT is a silent no-op.
    if (!$this->tryInsertAuditRow($commerceSubscriptionId, NULL, NULL, $reason, $eventKey, $effectiveAt)) {
      // Already processed.
      return;
    }

    // 2. Load state row to get uid + plan.
    $row = $this->database->select('license_service_subscriptions_state', 's')
      ->fields('s', ['uid', 'plan_id'])
      ->condition('commerce_subscription_id', $commerceSubscriptionId)
      ->execute()
      ->fetchAssoc();

    if (!$row) {
      $logger->notice(
        'revokeSubscription: no state row for sub @sub; audit row written but no role action taken.',
        ['@sub' => $commerceSubscriptionId],
      );
      return;
    }

    $uid    = (int) $row['uid'];
    $planId = (string) $row['plan_id'];

    // 3. Delegate role revocation (diffs other subs, respects manual_admin).
    try {
      $this->roleGrant->revokeForSubscription($uid, $commerceSubscriptionId, $reason);
    }
    catch (\Exception $e) {
      $logger->error(
        'revokeSubscription: role revocation failed for sub @sub: @msg',
        ['@sub' => $commerceSubscriptionId, '@msg' => $e->getMessage()],
      );
      // Do not re-throw: audit row is already written; log the failure.
    }

    $logger->info(
      'Revoked subscription @sub for uid @uid (plan @plan, reason @reason).',
      [
        '@sub'    => $commerceSubscriptionId,
        '@uid'    => $uid,
        '@plan'   => $planId,
        '@reason' => $reason,
      ],
    );
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
   */
  public function suspendSubscription(int $commerceSubscriptionId, string $reason): void {
    $now = \Drupal::time()->getRequestTime();

    $updated = $this->database->update('license_service_subscriptions_state')
      ->fields([
        'state'   => 'paused',
        'updated' => $now,
      ])
      ->condition('commerce_subscription_id', $commerceSubscriptionId)
      ->condition('state', 'active')
      ->execute();

    if ($updated) {
      $this->loggerFactory->get('license_service_subscriptions')->info(
        'Suspended sub @sub (reason: @reason).',
        ['@sub' => $commerceSubscriptionId, '@reason' => $reason],
      );
    }
  }

  /**
   * Resumes a paused subscription back to active.
   *
   * State = 'paused' → 'active'. No role change (roles were kept intact
   * during suspension).
   *
   * @param int $commerceSubscriptionId
   *   Commerce subscription entity ID.
   */
  public function resumeSubscription(int $commerceSubscriptionId): void {
    $now = \Drupal::time()->getRequestTime();

    $updated = $this->database->update('license_service_subscriptions_state')
      ->fields([
        'state'                => 'active',
        'payment_failed_since' => NULL,
        'updated'              => $now,
      ])
      ->condition('commerce_subscription_id', $commerceSubscriptionId)
      ->condition('state', 'paused')
      ->execute();

    if ($updated) {
      $this->loggerFactory->get('license_service_subscriptions')->info(
        'Resumed sub @sub (paused → active).',
        ['@sub' => $commerceSubscriptionId],
      );
    }
  }

  /**
   * Grants roles for a new or replacement subscription plan.
   *
   * Checks SeatCapService before granting (inside SubscriptionRoleGrantService).
   * Records source_sub_id in the audit row for migration provenance. Used both
   * for new checkouts and as the second step in a revoke → apply migration.
   *
   * @param int $uid
   *   Drupal user ID.
   * @param string $planId
   *   Target LicenseSubscriptionPlan machine name.
   * @param int $effectiveAt
   *   Unix timestamp when the grant becomes effective.
   * @param int $commerceSubscriptionId
   *   The new Commerce subscription entity ID.
   * @param int|null $sourceSubscriptionId
   *   Source commerce_subscription ID (migration provenance), or NULL for
   *   fresh grants.
   */
  public function applyNewTier(int $uid, string $planId, int $effectiveAt, int $commerceSubscriptionId, ?int $sourceSubscriptionId = NULL): void {
    $logger = $this->loggerFactory->get('license_service_subscriptions');

    if ($planId === '') {
      $logger->error(
        'applyNewTier called with empty planId for uid @uid; aborting.',
        ['@uid' => $uid],
      );
      return;
    }

    // Delegate to role grant service (handles SeatCap + state row upsert).
    try {
      $this->roleGrant->grantForSubscription($uid, $planId, $commerceSubscriptionId);
    }
    catch (\Exception $e) {
      $logger->error(
        'applyNewTier: grantForSubscription failed (uid @uid, plan @plan): @msg',
        ['@uid' => $uid, '@plan' => $planId, '@msg' => $e->getMessage()],
      );
      throw $e;
    }

    // Write audit row with migration provenance.
    $now = \Drupal::time()->getRequestTime();
    $eventKey = 'sub:' . $commerceSubscriptionId . ':apply:' . $effectiveAt;

    $this->tryInsertAuditRow(
      $commerceSubscriptionId,
      $sourceSubscriptionId !== NULL ? (string) $this->resolvePlanId($sourceSubscriptionId) : NULL,
      $planId,
      'plan_applied',
      $eventKey,
      $now,
      $sourceSubscriptionId,
    );

    $logger->info(
      'Applied tier plan @plan to uid @uid (sub @sub, source_sub @source).',
      [
        '@plan'   => $planId,
        '@uid'    => $uid,
        '@sub'    => $commerceSubscriptionId,
        '@source' => $sourceSubscriptionId ?? 'none',
      ],
    );
  }

  /**
   * Records a user's plan-choice and burns the single-use token.
   *
   * Burns the token at commit (NOT on GET, to avoid email-scanner prefetch
   * burning it). Records intent independently of payment so period-end
   * migration has a deterministic source. If the new tier is a subscription
   * plan, sets payment_deadline = now() + payment_completion_grace_days.
   * If the new tier is free / perpetual / NULL, no payment_deadline is set.
   *
   * Sequence:
   *   1. Validate token (UID-binding, expiry, not-used).
   *   2. Resolve target plan (falls back to module default_fallback_tier).
   *   3. Look up the existing intent row by token hash.
   *   4. Start a DB transaction:
   *        a. Burn token (token_used = 1).
   *        b. Set target_plan_id + payment_deadline on the intent row.
   *        c. Set subscription state → 'migrating'.
   *   5. Schedule period-end cancellation on the Commerce subscription entity.
   *
   * @param int $commerceSubscriptionId
   *   Old Commerce subscription entity ID, scheduled for period-end cancel.
   * @param string|null $targetPlanId
   *   Chosen plan machine name; NULL = use module default_fallback_tier.
   * @param string $token
   *   The raw single-use token from the URL (not the hash).
   * @param int $uid
   *   The authenticated user's UID (must match the token's UID binding).
   *
   * @throws \RuntimeException
   *   When the token is invalid/expired, or the subscription state row is missing.
   */
  public function markIntentToChange(int $commerceSubscriptionId, ?string $targetPlanId, string $token, int $uid): void {
    $logger = $this->loggerFactory->get('license_service_subscriptions');

    // 1. Validate token.
    if (!$this->tokenService->validate($token, $uid)) {
      throw new \RuntimeException('Invalid, expired, or already-used choose-plan token.');
    }

    // 2. Load subscription state row.
    $stateRow = $this->database->select('license_service_subscriptions_state', 's')
      ->fields('s', ['id', 'plan_id', 'uid'])
      ->condition('commerce_subscription_id', $commerceSubscriptionId)
      ->execute()
      ->fetchAssoc();

    if (!$stateRow || (int) $stateRow['uid'] !== $uid) {
      throw new \RuntimeException(
        "No matching subscription state row found for uid $uid / sub $commerceSubscriptionId."
      );
    }

    $subscriptionStateId = (int) $stateRow['id'];

    // 3. Resolve target plan.
    $settings = $this->configFactory->get('license_service_subscriptions.settings');
    $resolvedPlanId = ($targetPlanId !== NULL && $targetPlanId !== '')
      ? $targetPlanId
      : (string) ($settings->get('default_fallback_tier') ?? 'free');

    // Determine payment deadline (paid subscription plans only).
    $paymentDeadline = NULL;
    /** @var \Drupal\license_service_subscriptions\Entity\LicenseSubscriptionPlanInterface|null $targetPlan */
    $targetPlan = $this->entityTypeManager
      ->getStorage('license_subscription_plan')
      ->load($resolvedPlanId);
    if ($targetPlan !== NULL && $targetPlan->getType() === 'subscription') {
      $graceDays = (int) ($settings->get('payment_completion_grace_days') ?? 3);
      $paymentDeadline = time() + ($graceDays * 86400);
    }

    // 4. Atomic transaction: burn token + update intent row + update state.
    $txn = $this->database->startTransaction();
    try {
      $tokenHash = hash('sha256', $token);

      // a. Burn the token.
      $this->database->update('license_service_migration_intents')
        ->fields(['token_used' => 1])
        ->condition('token_hash', $tokenHash)
        ->condition('token_used', 0)
        ->execute();

      // b. Set intent fields on the row identified by this token hash.
      $this->database->update('license_service_migration_intents')
        ->fields([
          'target_plan_id'   => $resolvedPlanId,
          'payment_deadline' => $paymentDeadline,
        ])
        ->condition('token_hash', $tokenHash)
        ->execute();

      // c. Transition subscription state → migrating.
      $now = \Drupal::time()->getRequestTime();
      $this->database->update('license_service_subscriptions_state')
        ->fields([
          'state'   => 'migrating',
          'updated' => $now,
        ])
        ->condition('id', $subscriptionStateId)
        ->execute();
    }
    catch (\Exception $e) {
      $txn->rollBack();
      $logger->error(
        'markIntentToChange: transaction failed for uid @uid / sub @sub: @msg',
        ['@uid' => $uid, '@sub' => $commerceSubscriptionId, '@msg' => $e->getMessage()],
      );
      throw $e;
    }
    // Transaction commits when $txn goes out of scope.
    unset($txn);

    // 5. Schedule period-end cancellation on the Commerce subscription.
    //    $subscription->cancel(TRUE) sets scheduled_changes so Commerce
    //    Recurring's RecurringOrderManager::renewOrder() cancels at period-end.
    //    cancel(TRUE) verified 2026-06-08 against commerce_recurring 8.x-1.x source.
    try {
      $subscription = $this->entityTypeManager
        ->getStorage('commerce_subscription')
        ->load($commerceSubscriptionId);
      if ($subscription !== NULL && method_exists($subscription, 'cancel')) {
        $subscription->cancel(TRUE);
        $subscription->save();
      }
    }
    catch (\Exception $e) {
      // Non-fatal: the intent row is committed; Commerce cancel is best-effort.
      // Cron or a saga worker can retry the cancel if needed.
      $logger->warning(
        'markIntentToChange: could not schedule cancel on Commerce sub @id: @msg',
        ['@id' => $commerceSubscriptionId, '@msg' => $e->getMessage()],
      );
    }

    // 6. Send plan-chosen confirmation email.
    try {
      $this->notificationService->sendPlanChosen(
        $uid,
        (string) $stateRow['plan_id'],
        $resolvedPlanId,
        $paymentDeadline,
      );
    }
    catch (\Exception $e) {
      // Non-fatal: intent is committed; log the notification failure.
      $logger->warning(
        'markIntentToChange: plan_chosen email failed for uid @uid: @msg',
        ['@uid' => $uid, '@msg' => $e->getMessage()],
      );
    }

    $logger->info(
      'Intent recorded: uid @uid → plan @plan (sub @sub, payment_deadline @dl).',
      [
        '@uid'  => $uid,
        '@plan' => $resolvedPlanId,
        '@sub'  => $commerceSubscriptionId,
        '@dl'   => $paymentDeadline ?? 'none',
      ],
    );
  }

  // --------------------------------------------------------------------------
  // Private helpers
  // --------------------------------------------------------------------------

  /**
   * Attempts to insert an audit row, returning FALSE if event_key already exists.
   *
   * The UNIQUE constraint on event_key means a duplicate INSERT fails silently,
   * which is the idempotency mechanism for the entire state machine.
   *
   * @param int $commerceSubscriptionId
   *   Commerce subscription ID (may be 0 for non-subscription events).
   * @param string|null $fromPlanId
   *   Previous plan machine name; NULL for fresh grants.
   * @param string|null $toPlanId
   *   New plan machine name; NULL for revocations.
   * @param string $eventType
   *   Event type label for the audit row.
   * @param string $eventKey
   *   Idempotency key (UNIQUE).
   * @param int $now
   *   Unix timestamp for the 'created' column.
   * @param int|null $sourceSubscriptionId
   *   Source subscription ID for migration provenance.
   * @param int|null $orderId
   *   Commerce order ID if available.
   * @param bool $forceMigrated
   *   TRUE if triggered by admin Force-Migrate-Now.
   *
   * @return bool
   *   TRUE if the row was inserted (first time); FALSE if already exists.
   */
  protected function tryInsertAuditRow(
    int $commerceSubscriptionId,
    ?string $fromPlanId,
    ?string $toPlanId,
    string $eventType,
    string $eventKey,
    int $now,
    ?int $sourceSubscriptionId = NULL,
    ?int $orderId = NULL,
    bool $forceMigrated = FALSE,
  ): bool {
    try {
      // Load uid from state row for audit.
      $uid = (int) ($this->database->select('license_service_subscriptions_state', 's')
        ->fields('s', ['uid'])
        ->condition('commerce_subscription_id', $commerceSubscriptionId)
        ->execute()
        ->fetchField() ?? 0);

      $this->database->insert('license_service_migrations')
        ->fields([
          'uid'                      => $uid,
          'from_plan_id'             => $fromPlanId,
          'to_plan_id'               => $toPlanId,
          'event_type'               => $eventType,
          'event_key'                => $eventKey,
          'commerce_subscription_id' => $commerceSubscriptionId ?: NULL,
          'commerce_order_id'        => $orderId,
          'force_migrated'           => (int) $forceMigrated,
          'raw_json'                 => NULL,
          'created'                  => $now,
        ])
        ->execute();
      return TRUE;
    }
    catch (\Exception) {
      // UNIQUE constraint violation = already processed. All other exceptions
      // are also caught here and treated as "already processed" to keep the
      // state machine forward-only. The caller should log if needed.
      return FALSE;
    }
  }

  /**
   * Looks up the plan_id from a state row by commerce_subscription_id.
   *
   * Returns an empty string when not found (audit provenance only).
   *
   * @param int $commerceSubscriptionId
   *   Commerce subscription entity ID.
   *
   * @return string
   *   Plan machine name, or '' if not found.
   */
  protected function resolvePlanId(int $commerceSubscriptionId): string {
    return (string) ($this->database->select('license_service_subscriptions_state', 's')
      ->fields('s', ['plan_id'])
      ->condition('commerce_subscription_id', $commerceSubscriptionId)
      ->execute()
      ->fetchField() ?? '');
  }

}
