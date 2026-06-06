<?php

declare(strict_types=1);

namespace Drupal\license_service;

use Drupal\Core\Session\AccountInterface;

/**
 * Contract for the cross-module license entitlement facade.
 *
 * Companion modules (e.g. License Service: Token Limits) type-hint on this
 * interface rather than the concrete LicenseFeatureProvider so they can mock
 * the facade in unit tests without depending on final-class mocking.
 *
 * Author: Jeremiah Buttler.
 */
interface LicenseFeatureProviderInterface {

  /**
   * Returns TRUE if the site holds an active, valid license.
   *
   * @return bool
   *   TRUE when the site is licensed.
   */
  public function isActive(): bool;

  /**
   * Returns TRUE if the signed entitlement grants the named feature.
   *
   * @param string $name
   *   Feature key, e.g. 'ai_token_counter'.
   *
   * @return bool
   *   TRUE when the feature is present in the entitlement.
   */
  public function hasFeature(string $name): bool;

  /**
   * Returns the value of a named feature from the signed entitlement.
   *
   * @param string $name
   *   Feature key.
   *
   * @return mixed
   *   The feature payload, or NULL when the feature is not granted.
   */
  public function getFeature(string $name): mixed;

  /**
   * Returns active license warnings (e.g. refresh failed, expiring soon).
   *
   * @return string[]
   *   A list of warning strings, possibly empty.
   */
  public function getWarnings(): array;

  /**
   * Resolves the effective license level for a Drupal account.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account whose effective license level is resolved.
   *
   * @return string
   *   Level name, e.g. 'free', 'standard', 'premium'.
   */
  public function getLevelForAccount(AccountInterface $account): string;

  /**
   * Returns the ordered list of known levels from config, lowest first.
   *
   * @return string[]
   *   Level names ordered from lowest to highest privilege.
   */
  public function getLevelOrder(): array;

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
  public function levelAtLeast(string $levelA, string $levelB): bool;

  /**
   * Returns the license entitlement envelope constraining what may be configured.
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
  public function getEnvelope(): array;

}
