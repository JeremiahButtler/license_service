<?php

namespace Drupal\license_service;

use Drupal\Core\Session\AccountInterface;

/**
 * Public cross-module license API for licensed add-on modules.
 *
 * Exposes a stable, minimal entitlement surface that any companion module can
 * consume to discover whether the site is licensed and what named features the
 * signed entitlement grants. It is a thin facade over LicenseManagerService so
 * the security-audited core service is not broadened with cross-module concerns.
 *
 * Registered under the service id 'license_service.manager' — the first id that
 * consumer modules (e.g. AI Token Counter's LicenseBridge) probe for. Method
 * names match the consumer contract exactly so duck-typed discovery succeeds
 * without either module depending on the other's classes.
 *
 * Author: Jeremiah Buttler
 */
final class LicenseFeatureProvider implements LicenseFeatureProviderInterface {

  public function __construct(
    private readonly LicenseManagerService $manager,
  ) {}

  /**
   * Returns TRUE if the site holds an active, valid license.
   */
  public function isActive(): bool {
    return $this->manager->isLicensed();
  }

  /**
   * Returns TRUE if the signed entitlement grants the named feature.
   *
   * @param string $name
   *   Feature key, e.g. 'ai_token_counter'.
   */
  public function hasFeature(string $name): bool {
    return $this->manager->getFeature($name) !== NULL;
  }

  /**
   * Returns the value of a named feature from the signed entitlement.
   *
   * @param string $name
   *   Feature key.
   *
   * @return mixed
   *   The feature payload (e.g. the AI Token Counter pricing map), or NULL.
   */
  public function getFeature(string $name): mixed {
    return $this->manager->getFeature($name);
  }

  /**
   * Returns active license warnings (e.g. refresh failed, expiring soon).
   *
   * @return array
   *   A list of warning strings, possibly empty.
   */
  public function getWarnings(): array {
    return (array) ($this->manager->getStatus()['warnings'] ?? []);
  }

  /**
   * Resolves the effective license level for a Drupal account.
   *
   * Delegates to LicenseManagerService::getLevelForAccount(). Accounts with
   * 'bypass license gate' always get the highest configured level.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account whose effective license level is resolved.
   *
   * @return string
   *   Level name, e.g. 'free', 'standard', 'premium'.
   */
  public function getLevelForAccount(AccountInterface $account): string {
    return $this->manager->getLevelForAccount($account);
  }

  /**
   * Returns the ordered list of known levels from config, lowest first.
   *
   * The list is derived from the admin-configured role→level mapping. 'free'
   * is always present and first.
   *
   * @return string[]
   *   Level names ordered from lowest to highest privilege.
   */
  public function getLevelOrder(): array {
    return $this->manager->getLevelOrder();
  }

  /**
   * Returns TRUE when level A is greater than or equal to level B in order.
   *
   * @param string $levelA
   *   The level to test.
   * @param string $levelB
   *   The minimum required level.
   *
   * @return bool
   *   TRUE when $levelA is at least as high as $levelB.
   */
  public function levelAtLeast(string $levelA, string $levelB): bool {
    return $this->manager->levelAtLeast($levelA, $levelB);
  }

  /**
   * Returns the license entitlement envelope constraining what may be configured.
   *
   * Keys include: allowed_levels, max_premium_users, field_gating,
   * download_gating, metered_views, quotas. Values are derived from the signed
   * token so they cannot be forged by editing config.
   *
   * @return array{
   *   allowed_levels: string[],
   *   max_premium_users: int,
   *   field_gating: bool,
   *   download_gating: bool,
   *   metered_views: bool,
   *   quotas: bool,
   *   }
   *   The entitlement envelope.
   */
  public function getEnvelope(): array {
    return $this->manager->getEnvelope();
  }

}
