<?php

namespace Drupal\license_service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Central service for license status, role-to-level resolution, and envelope checks.
 *
 * Caches the license status to avoid repeated token verification on every
 * request. Exposes methods for resolving a user's effective license level and
 * checking what the license envelope (tier + features) permits.
 *
 * Author: Jeremiah Buttler
 */
class LicenseManagerService {

  /**
   * Cache lifetime for license status in seconds (5 minutes).
   *
   * Access checks use cache tags so they invalidate immediately on changes.
   */
  const STATUS_CACHE_TTL = 300;

  public function __construct(
    protected readonly LicenseClient $licenseClient,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly CacheBackendInterface $cache,
    protected readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Returns the full license status array (possibly cached).
   *
   * @return array
   *   License status as returned by LicenseClient::getStatus().
   */
  public function getStatus(): array {
    $cid    = 'license_service:status';
    $cached = $this->cache->get($cid);
    if ($cached !== FALSE) {
      return $cached->data;
    }
    $status = $this->licenseClient->getStatus();
    $this->cache->set($cid, $status, time() + self::STATUS_CACHE_TTL, ['license_service']);
    return $status;
  }

  /**
   * Returns TRUE if the site has an active, valid license.
   */
  public function isLicensed(): bool {
    return (bool) ($this->getStatus()['licensed'] ?? FALSE);
  }

  /**
   * Returns the site's license tier string (e.g. 'free', 'pro', 'enterprise').
   */
  public function getTier(): string {
    return (string) ($this->getStatus()['tier'] ?? 'free');
  }

  /**
   * Returns the value of a named feature flag from the license token.
   *
   * @param string $name
   *   Feature flag key.
   *
   * @return mixed
   *   The feature value, or NULL if not set.
   */
  public function getFeature(string $name): mixed {
    return $this->getStatus()['features'][$name] ?? NULL;
  }

  /**
   * Returns the license entitlement envelope.
   *
   * Tiers and feature flags are TENANT-defined (from local config, no server
   * constraint). The only server-derived value is authorized_users — the
   * maximum number of users the tenant's plan permits, as granted by the LVS.
   *
   * @return array{
   *   allowed_levels: string[],
   *   authorized_users: int,
   *   max_premium_users: int,
   *   field_gating: bool,
   *   download_gating: bool,
   *   metered_views: bool,
   *   quotas: bool,
   *   content_access: bool,
   *   }
   */
  public function getEnvelope(): array {
    $tiers = (array) ($this->configFactory->get('license_service.tiers')->get('tiers') ?? []);

    // Feature flags: TRUE when any configured tier enables the feature.
    $anyFeature = static function (string $key) use ($tiers): bool {
      foreach ($tiers as $tier) {
        if (!empty($tier['features'][$key])) {
          return TRUE;
        }
      }
      return FALSE;
    };

    // authorized_users is the ONE thing the LVS controls: the number of
    // non-free users the tenant's plan permits. Fall back to max_premium_users
    // for backward compatibility with older token payloads.
    $features = (array) ($this->getStatus()['features'] ?? []);
    $authorizedUsers = (int) ($features['authorized_users']
      ?? $features['max_premium_users']
      ?? 0);

    return [
      'allowed_levels'   => $this->getLevelOrder(),
      'authorized_users' => $authorizedUsers,
      'max_premium_users' => $authorizedUsers,  // backward-compat alias
      'field_gating'     => $anyFeature('field_gating'),
      'download_gating'  => $anyFeature('download_gating'),
      'metered_views'    => $anyFeature('metered_views'),
      'quotas'           => $anyFeature('quotas'),
      'content_access'   => $anyFeature('content_access'),
    ];
  }

  /**
   * Resolves the effective license level for a Drupal account.
   *
   * Checks all of the account's roles against the admin-configured role→level
   * mapping and returns the highest-privilege level. Falls back to 'free'.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account whose effective license level is resolved.
   *
   * @return string
   *   Level name, e.g. 'free', 'standard', 'premium'.
   */
  public function getLevelForAccount(AccountInterface $account): string {
    // Accounts with bypass permission always get the highest configured level.
    if ($account->hasPermission('bypass license gate')) {
      $order = $this->getLevelOrder();
      return !empty($order) ? end($order) : 'free';
    }

    $roleMap = $this->configFactory->get('license_service.role_levels')->get('role_levels') ?? [];
    if (!is_array($roleMap) || empty($roleMap)) {
      return 'free';
    }

    // Level → rank.
    $levelOrder = array_flip($this->getLevelOrder());
    $bestLevel  = 'free';
    $bestRank   = $levelOrder['free'] ?? 0;

    foreach ($account->getRoles() as $roleId) {
      $level = (string) ($roleMap[$roleId] ?? '');
      if ($level === '') {
        continue;
      }
      $rank = $levelOrder[$level] ?? NULL;
      if ($rank !== NULL && $rank > $bestRank) {
        $bestRank  = $rank;
        $bestLevel = $level;
      }
    }

    return $bestLevel;
  }

  /**
   * Returns the tenant-defined tier IDs in ascending privilege order.
   *
   * The order is driven entirely by the weight values set in the License Tiers
   * editor (license_service.tiers config). The LVS has no say in which tiers
   * exist or how they are ordered. 'free' is always present as the baseline.
   *
   * @return string[]
   *   Tier IDs sorted by weight (ascending), lowest privilege first.
   */
  public function getLevelOrder(): array {
    $tiers = (array) ($this->configFactory->get('license_service.tiers')->get('tiers') ?? []);

    if (empty($tiers)) {
      return ['free'];
    }

    // Sort by weight ascending.
    uasort($tiers, static fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));

    $levels = array_keys($tiers);

    // Guarantee 'free' is always present.
    if (!in_array('free', $levels, TRUE)) {
      array_unshift($levels, 'free');
    }

    return $levels;
  }

  /**
   * Returns TRUE if $levelA is greater than or equal to $levelB in the level order.
   */
  public function levelAtLeast(string $levelA, string $levelB): bool {
    $order = array_flip($this->getLevelOrder());
    return ($order[$levelA] ?? 0) >= ($order[$levelB] ?? 0);
  }

  /**
   * Invalidates the cached license status, forcing a fresh check on next access.
   */
  public function invalidateCache(): void {
    $this->cacheTagsInvalidator->invalidateTags(['license_service']);
  }

}

