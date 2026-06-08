<?php

declare(strict_types=1);

namespace Drupal\license_service_subscriptions\Service;

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
 *
 * @todo Phase 2: implement grantForSubscription(), revokeForSubscription(),
 *   and getRolesForTier(). Stubs only in Phase 1.
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
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly LicenseManagerService $licenseManager,
    protected readonly SeatCapService $seatCap,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Grants the roles conferred by $planId to $uid.
   *
   * Checks SeatCap before granting. Clears the SeatCap cache after.
   * Records the grant in license_service_subscriptions_state via
   * granted_by_action = 'subscription' (or 'trial' when $fromTrial = TRUE).
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
   * @todo Implement in Phase 2.
   */
  public function grantForSubscription(int $uid, string $planId, int $commerceSubscriptionId, bool $fromTrial = FALSE): void {
    // @todo Phase 2: resolve tier from plan, get role set, check SeatCap,
    //   assign roles via user entity, write state row, clearGrantCache.
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
   *   Revocation reason for the audit row (e.g. 'plan_deprecated').
   *
   * @todo Implement in Phase 2.
   */
  public function revokeForSubscription(int $uid, int $commerceSubscriptionId, string $reason): void {
    // @todo Phase 2: load state row, diff union-of-other-subs roles, strip
    //   only un-shared roles, write audit row, clearGrantCache.
  }

  /**
   * Returns the Drupal role IDs conferred by a given tier.
   *
   * Reads role_levels config: every role whose level is >= $tierId in the
   * level hierarchy is considered granted by that tier.
   *
   * @param string $tierId
   *   License tier machine name.
   *
   * @return string[]
   *   Drupal role IDs.
   *
   * @todo Implement in Phase 2.
   */
  public function getRolesForTier(string $tierId): array {
    // @todo Phase 2.
    return [];
  }

}
