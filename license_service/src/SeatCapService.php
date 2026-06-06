<?php

namespace Drupal\license_service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Enforces per-role seat caps from the license envelope.
 *
 * The license token carries a `max_premium_users` feature flag. When the admin
 * tries to assign a premium role to a user (or when a user is created with one),
 * this service checks the current seat count against the cap.
 *
 * "Premium" here means any role that is mapped to a level above 'free' in the
 * role_levels config. If multiple roles are mapped to elevated levels, all of
 * them count toward the same cap (total elevated-level seat usage).
 *
 * Author: Jeremiah Buttler
 */
class SeatCapService {

  /**
   * Lock name serializing concurrent premium-role seat assignments.
   */
  const SEAT_LOCK = 'license_service_seat_assignment';

  /**
   * Cache bin used for per-user LVS grant decisions.
   *
   * Declared as 'lvs_user_grants' in services.yml; the key per entry is
   * "lvs_grant:{uid}:{kind}" so individual grants can be invalidated cheaply.
   */
  const GRANT_CACHE_BIN = 'lvs_user_grants';

  /**
   * Default TTL in seconds when the LVS grant_token expiry cannot be parsed.
   */
  const GRANT_CACHE_DEFAULT_TTL = 3600;

  public function __construct(
    protected readonly LicenseManagerService $licenseManager,
    protected readonly LicenseClient $licenseClient,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly LockBackendInterface $lock,
    protected readonly CacheBackendInterface $grantCache,
  ) {}

  /**
   * Acquires the seat-assignment lock, serializing the count-then-assign check.
   *
   * Two concurrent premium-role assignments could each read seat usage below
   * the cap and both proceed, overshooting it. Callers acquire this lock before
   * the seat-cap decision and release it only after the user save commits, so
   * the check and the write are atomic with respect to other assignments. The
   * lock auto-expires after 30s, so a crashed save self-heals.
   *
   * @return bool
   *   TRUE if the lock was acquired; FALSE if it could not be obtained.
   */
  public function acquireSeatLock(): bool {
    if ($this->lock->acquire(self::SEAT_LOCK, 30.0)) {
      return TRUE;
    }
    // Held by a concurrent assignment: wait briefly, then retry once.
    $this->lock->wait(self::SEAT_LOCK, 10);
    return $this->lock->acquire(self::SEAT_LOCK, 30.0);
  }

  /**
   * Releases the seat-assignment lock.
   */
  public function releaseSeatLock(): void {
    $this->lock->release(self::SEAT_LOCK);
  }

  /**
   * Returns TRUE if the given account may be assigned the given role.
   *
   * Checks the seat cap only when the role maps to a non-free license level.
   * Always returns TRUE when the cap is 0 (unlimited) or the role is 'free'.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user to check.
   * @param string $roleId
   *   The role being assigned.
   *
   * @return bool
   *   TRUE if the role may be assigned within the seat cap; FALSE otherwise.
   */
  public function mayAssignRole(AccountInterface $account, string $roleId): bool {
    if (!$this->isPremiumRole($roleId)) {
      return TRUE;
    }

    // Determine the grant kind: 'admin' for Drupal administrators, 'user'
    // for all other premium roles.
    $kind = $account->hasRole('administrator') ? 'admin' : 'user';
    $uid  = (string) $account->id();
    $cid  = 'lvs_grant:' . $uid . ':' . $kind;
    $tag  = 'lvs_grant:' . $uid;

    // 1. Check the grant cache (non-expired entries) first.
    $cached = $this->grantCache->get($cid);
    if ($cached !== FALSE) {
      return (bool) $cached->data;
    }

    // 2. Call LVS to authorize the user.
    $response = $this->licenseClient->authorizeUser($uid, $kind, $account->getEmail());
    $status   = $response['status'] ?? 'error';

    if ($status === 'error') {
      // LVS unreachable — apply offline grace: check for a stale cache entry,
      // ignoring expiry, so a briefly-offline server does not evict live grants.
      $stale = $this->grantCache->get($cid, TRUE);
      if ($stale !== FALSE) {
        return (bool) $stale->data;
      }
      // No cache at all — fail closed.
      return FALSE;
    }

    $granted = ($status === 'granted');

    // 3. Compute TTL from the grant_token's expires_at, or use the default.
    $ttl = self::GRANT_CACHE_DEFAULT_TTL;
    if ($granted && !empty($response['expires_at'])) {
      try {
        $exp = new \DateTime($response['expires_at'], new \DateTimeZone('UTC'));
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $ttl = max(60, $exp->getTimestamp() - $now->getTimestamp());
      }
      catch (\Exception) {
        // Use the default TTL.
      }
    }

    // 4. Cache the decision keyed and tagged for targeted invalidation.
    $this->grantCache->set($cid, $granted, time() + $ttl, [$tag]);

    return $granted;
  }

  /**
   * Clears the LVS grant cache entries for a specific user.
   *
   * Should be called after revokeUser() so the next mayAssignRole() call
   * goes back to LVS rather than reading a now-stale granted entry.
   *
   * @param string $uid
   *   The Drupal UID as a string.
   */
  public function clearGrantCache(string $uid): void {
    $this->grantCache->invalidateTags(['lvs_grant:' . $uid]);
  }

  /**
   * Returns the number of premium seats currently in use.
   *
   * @param int $excludeUid
   *   Optional UID to exclude (e.g. the user being re-assigned).
   *
   * @return int
   *   The number of premium seats currently in use.
   */
  public function countPremiumUsers(int $excludeUid = 0): int {
    $premiumRoles = $this->getPremiumRoles();
    if (empty($premiumRoles)) {
      return 0;
    }

    try {
      $query = $this->entityTypeManager->getStorage('user')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('roles', $premiumRoles, 'IN')
        ->condition('status', 1);

      if ($excludeUid > 0) {
        $query->condition('uid', $excludeUid, '<>');
      }

      // COUNT is cheaper than loading entities.
      return (int) $query->count()->execute();
    }
    catch (\Exception) {
      return 0;
    }
  }

  /**
   * Returns the current cap and usage for the admin status page.
   *
   * @return array
   *   Summary array with keys: cap (int), used (int), unlimited (bool).
   */
  public function getSeatUsageSummary(): array {
    $cap = $this->licenseManager->getEnvelope()['max_premium_users'];
    return [
      'cap'       => $cap,
      'used'      => $this->countPremiumUsers(),
      'unlimited' => ($cap <= 0),
    ];
  }

  /**
   * Returns TRUE if the given role ID maps to any level above 'free'.
   */
  public function isPremiumRole(string $roleId): bool {
    $roleMap = $this->configFactory->get('license_service.role_levels')->get('role_levels') ?? [];
    $level   = (string) ($roleMap[$roleId] ?? '');
    if ($level === '' || $level === 'free') {
      return FALSE;
    }
    // Anything above 'free' in the level order counts as premium.
    return $this->licenseManager->levelAtLeast($level, 'free')
      && $level !== 'free';
  }

  /**
   * Returns all role IDs that map to a non-free license level.
   *
   * @return string[]
   *   Role IDs that map to a non-free license level.
   */
  public function getPremiumRoles(): array {
    $roleMap = $this->configFactory->get('license_service.role_levels')->get('role_levels') ?? [];
    if (!is_array($roleMap)) {
      return [];
    }

    $premium = [];
    foreach ($roleMap as $roleId => $level) {
      if ((string) $level !== '' && (string) $level !== 'free') {
        $premium[] = (string) $roleId;
      }
    }
    return $premium;
  }

}
