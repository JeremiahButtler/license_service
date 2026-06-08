<?php

declare(strict_types=1);

namespace Drupal\license_service_subscriptions\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\license_service\LicenseManagerService;
use Drupal\license_service\SeatCapService;

/**
 * Grants and revokes Drupal roles when a subscription's state changes.
 *
 * This service sits between Commerce Recurring lifecycle events and Drupal's
 * role system. It knows which roles correspond to a given plan (via the plan's
 * tier_id + license_service.role_levels config), diffs the union of the user's
 * other active subscriptions before stripping any role, and respects the
 * granted_by_action flag so manually-granted roles are never auto-revoked.
 *
 * All mutating methods call SeatCapService::mayAssignRole() before granting
 * and SeatCapService::clearGrantCache() after every state transition.
 *
 * Author: Jeremiah Buttler.
 */
class SubscriptionRoleGrantService {

  /**
   * Constructs a SubscriptionRoleGrantService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager (for loading user + role entities).
   * @param \Drupal\license_service\LicenseManagerService $licenseManager
   *   The license manager (for role-level resolution).
   * @param \Drupal\license_service\SeatCapService $seatCap
   *   The seat-cap service (must be checked before any upgrade-direction grant).
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory (reads license_service.role_levels).
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection (reads/writes subscription state rows).
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly LicenseManagerService $licenseManager,
    protected readonly SeatCapService $seatCap,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly Connection $database,
  ) {}

  /**
   * Grants the roles conferred by $planId to $uid.
   *
   * Checks SeatCap before granting. Clears the SeatCap cache after.
   * Upserts a row in license_service_subscriptions_state with:
   *   - granted_by_action = 'subscription' (or 'trial' when $fromTrial = TRUE)
   *   - state = 'active'
   *   - roles_json = JSON of the roles actually granted.
   *
   * @param int $uid
   *   Drupal user ID.
   * @param string $planId
   *   LicenseSubscriptionPlan machine name.
   * @param int $commerceSubscriptionId
   *   Commerce subscription entity ID.
   * @param bool $fromTrial
   *   TRUE when transitioning from trial state.
   *
   * @throws \RuntimeException
   *   When the plan entity cannot be loaded.
   */
  public function grantForSubscription(int $uid, string $planId, int $commerceSubscriptionId, bool $fromTrial = FALSE): void {
    $logger = $this->loggerFactory->get('license_service_subscriptions');

    // 1. Load plan → tier.
    /** @var \Drupal\license_service_subscriptions\Entity\LicenseSubscriptionPlanInterface|null $plan */
    $plan = $this->entityTypeManager
      ->getStorage('license_subscription_plan')
      ->load($planId);
    if ($plan === NULL) {
      $logger->error(
        'grantForSubscription: plan "@plan" not found; cannot grant roles to uid @uid.',
        ['@plan' => $planId, '@uid' => $uid],
      );
      throw new \RuntimeException("LicenseSubscriptionPlan '$planId' not found.");
    }

    $tierId = $plan->getTierId();
    $roleIds = $this->getRolesForTier($tierId);

    if (empty($roleIds)) {
      $logger->notice(
        'grantForSubscription: tier "@tier" has no mapped roles; state row written, no role changes.',
        ['@tier' => $tierId],
      );
    }

    // 2. Load user.
    /** @var \Drupal\user\UserInterface|null $user */
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if ($user === NULL) {
      $logger->error(
        'grantForSubscription: user @uid not found; cannot grant roles.',
        ['@uid' => $uid],
      );
      return;
    }

    // 3. Grant roles with SeatCap check.
    $grantedRoles = [];
    foreach ($roleIds as $roleId) {
      if ($user->hasRole($roleId)) {
        // Already has the role — track it without a redundant save.
        $grantedRoles[] = $roleId;
        continue;
      }
      if (!$this->seatCap->mayAssignRole($user, $roleId)) {
        $logger->warning(
          'grantForSubscription: seat cap denied role "@role" for uid @uid (plan @plan).',
          ['@role' => $roleId, '@uid' => $uid, '@plan' => $planId],
        );
        continue;
      }
      $user->addRole($roleId);
      $grantedRoles[] = $roleId;
    }

    if (!empty($grantedRoles) || !empty($roleIds)) {
      // Save even if no new roles were added (roles_json records effective state).
      $user->save();
    }

    // 4. Upsert subscription state row.
    $now = \Drupal::time()->getRequestTime();
    $grantedByAction = $fromTrial ? 'trial' : 'subscription';

    $existing = $this->database->select('license_service_subscriptions_state', 's')
      ->fields('s', ['id'])
      ->condition('commerce_subscription_id', $commerceSubscriptionId)
      ->execute()
      ->fetchField();

    if ($existing) {
      $this->database->update('license_service_subscriptions_state')
        ->fields([
          'uid'                    => $uid,
          'plan_id'                => $planId,
          'state'                  => 'active',
          'granted_by_action'      => $grantedByAction,
          'effective_from'         => $now,
          'effective_until'        => NULL,
          'payment_failed_since'   => NULL,
          'roles_json'             => json_encode($grantedRoles),
          'updated'                => $now,
        ])
        ->condition('commerce_subscription_id', $commerceSubscriptionId)
        ->execute();
    }
    else {
      $this->database->insert('license_service_subscriptions_state')
        ->fields([
          'uid'                    => $uid,
          'plan_id'                => $planId,
          'commerce_subscription_id' => $commerceSubscriptionId,
          'state'                  => 'active',
          'granted_by_action'      => $grantedByAction,
          'effective_from'         => $now,
          'effective_until'        => NULL,
          'payment_failed_since'   => NULL,
          'roles_json'             => json_encode($grantedRoles),
          'created'                => $now,
          'updated'                => $now,
        ])
        ->execute();
    }

    // 5. Clear SeatCap grant cache.
    $this->seatCap->clearGrantCache((string) $uid);

    $logger->info(
      'Granted roles [@roles] to uid @uid via plan @plan (sub @sub).',
      [
        '@roles' => implode(', ', $grantedRoles),
        '@uid'   => $uid,
        '@plan'  => $planId,
        '@sub'   => $commerceSubscriptionId,
      ],
    );
  }

  /**
   * Revokes roles granted by a specific subscription, avoiding shared roles.
   *
   * Before stripping any role, this method computes the union of roles that
   * other active subscriptions still confer to the user, and skips any role
   * that is still covered. It also skips rows with granted_by_action =
   * 'manual_admin'.
   *
   * @param int $uid
   *   Drupal user ID.
   * @param int $commerceSubscriptionId
   *   Commerce subscription entity ID whose grant rows should be revoked.
   * @param string $reason
   *   Revocation reason for logging.
   */
  public function revokeForSubscription(int $uid, int $commerceSubscriptionId, string $reason): void {
    $logger = $this->loggerFactory->get('license_service_subscriptions');

    // 1. Load the state row for this subscription.
    $row = $this->database->select('license_service_subscriptions_state', 's')
      ->fields('s', ['id', 'plan_id', 'granted_by_action', 'roles_json'])
      ->condition('commerce_subscription_id', $commerceSubscriptionId)
      ->execute()
      ->fetchAssoc();

    if (!$row) {
      // No state row — nothing to revoke.
      $logger->notice(
        'revokeForSubscription: no state row for sub @sub; nothing to revoke.',
        ['@sub' => $commerceSubscriptionId],
      );
      return;
    }

    // Respect manual_admin grants — never auto-revoke them.
    if ($row['granted_by_action'] === 'manual_admin') {
      $logger->notice(
        'revokeForSubscription: sub @sub is granted_by_action=manual_admin; skipping revocation.',
        ['@sub' => $commerceSubscriptionId],
      );
      return;
    }

    // 2. Compute roles this subscription granted (from stored roles_json).
    $subRoles = [];
    if (!empty($row['roles_json'])) {
      $decoded = json_decode($row['roles_json'], TRUE);
      if (is_array($decoded)) {
        $subRoles = $decoded;
      }
    }

    if (empty($subRoles)) {
      // Nothing was actually granted — update state row and exit.
      $this->markStateCanceled($commerceSubscriptionId);
      $this->seatCap->clearGrantCache((string) $uid);
      return;
    }

    // 3. Collect roles still covered by OTHER active subscriptions for this user.
    $coveredRoles = $this->getActiveSubscriptionRoles($uid, $commerceSubscriptionId);

    // 4. Load user, strip only un-covered roles.
    /** @var \Drupal\user\UserInterface|null $user */
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if ($user === NULL) {
      $logger->error(
        'revokeForSubscription: user @uid not found; marking state canceled without role removal.',
        ['@uid' => $uid],
      );
      $this->markStateCanceled($commerceSubscriptionId);
      return;
    }

    $revokedRoles = [];
    foreach ($subRoles as $roleId) {
      if (in_array($roleId, $coveredRoles, TRUE)) {
        // Still covered by another active subscription — leave it.
        continue;
      }
      if ($user->hasRole($roleId)) {
        $user->removeRole($roleId);
        $revokedRoles[] = $roleId;
      }
    }

    if (!empty($revokedRoles)) {
      $user->save();
    }

    // 5. Mark state row as canceled.
    $this->markStateCanceled($commerceSubscriptionId);

    // 6. Clear SeatCap grant cache.
    $this->seatCap->clearGrantCache((string) $uid);

    $logger->info(
      'Revoked roles [@roles] from uid @uid (reason: @reason, sub @sub).',
      [
        '@roles'  => implode(', ', $revokedRoles),
        '@uid'    => $uid,
        '@reason' => $reason,
        '@sub'    => $commerceSubscriptionId,
      ],
    );
  }

  /**
   * Returns the Drupal role IDs conferred by a given tier.
   *
   * Reads license_service.role_levels config. Returns all roles whose mapped
   * level exactly matches $tierId.
   *
   * @param string $tierId
   *   License tier machine name.
   *
   * @return string[]
   *   Drupal role IDs whose configured level matches $tierId exactly.
   */
  public function getRolesForTier(string $tierId): array {
    $roleMap = $this->configFactory->get('license_service.role_levels')->get('role_levels') ?? [];
    if (!is_array($roleMap)) {
      return [];
    }

    $roles = [];
    foreach ($roleMap as $roleId => $level) {
      if ((string) $level === $tierId) {
        $roles[] = (string) $roleId;
      }
    }
    return $roles;
  }

  // --------------------------------------------------------------------------
  // Private helpers
  // --------------------------------------------------------------------------

  /**
   * Returns the union of roles still active from OTHER subscriptions for a user.
   *
   * @param int $uid
   *   Drupal user ID.
   * @param int $excludeCommerceSubscriptionId
   *   Commerce subscription ID to exclude (the one being revoked).
   *
   * @return string[]
   *   Role IDs still covered by at least one other active subscription.
   */
  protected function getActiveSubscriptionRoles(int $uid, int $excludeCommerceSubscriptionId): array {
    $rows = $this->database->select('license_service_subscriptions_state', 's')
      ->fields('s', ['roles_json'])
      ->condition('uid', $uid)
      ->condition('state', 'active')
      ->condition('commerce_subscription_id', $excludeCommerceSubscriptionId, '<>')
      ->execute()
      ->fetchCol();

    $covered = [];
    foreach ($rows as $json) {
      if (!empty($json)) {
        $decoded = json_decode($json, TRUE);
        if (is_array($decoded)) {
          $covered = array_merge($covered, $decoded);
        }
      }
    }
    return array_unique($covered);
  }

  /**
   * Updates the subscription state row to 'canceled'.
   *
   * @param int $commerceSubscriptionId
   *   Commerce subscription entity ID.
   */
  protected function markStateCanceled(int $commerceSubscriptionId): void {
    $now = \Drupal::time()->getRequestTime();
    $this->database->update('license_service_subscriptions_state')
      ->fields([
        'state'          => 'canceled',
        'effective_until' => $now,
        'updated'        => $now,
      ])
      ->condition('commerce_subscription_id', $commerceSubscriptionId)
      ->execute();
  }

}
