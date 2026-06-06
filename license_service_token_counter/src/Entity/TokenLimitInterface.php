<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Interface for the token_limit config entity.
 *
 * Author: Jeremiah Buttler.
 */
interface TokenLimitInterface extends ConfigEntityInterface {

  /**
   * Scope: limit applies to a specific role (per user within that role).
   */
  public const SCOPE_ROLE = 'role';

  /**
   * Scope: limit applies to every authenticated user individually.
   */
  public const SCOPE_ALL_USERS = 'all_users';

  /**
   * Scope: limit is a single shared pool across the entire site.
   */
  public const SCOPE_SITE_TOTAL = 'site_total';

  /**
   * Returns human-readable labels for each scope type.
   *
   * @return array<string, string>
   *   Scope type => human-readable label.
   */
  public static function scopeLabels(): array;

  /**
   * Returns the scope type ('role', 'all_users', or 'site_total').
   */
  public function getScopeType(): string;

  /**
   * Returns the role machine name (only meaningful when scope is 'role').
   */
  public function getRoleId(): string;

  /**
   * Returns the token limit amount (0 indicates no upper bound).
   */
  public function getAmount(): int;

  /**
   * Returns the period key (day, week, month, year, or lifetime).
   */
  public function getPeriod(): string;

  /**
   * Returns the display weight used to order rules in the admin list.
   */
  public function getWeight(): int;

}
