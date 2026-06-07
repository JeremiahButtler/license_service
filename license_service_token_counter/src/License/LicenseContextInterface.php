<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\License;

use Drupal\Core\Session\AccountInterface;

/**
 * Read-only view of the site's license state for License Service Token Counter.
 *
 * Consumers (the cost engine, the report page, the capture subscriber) depend on
 * this abstraction rather than the concrete bridge, so they can be unit tested
 * with a fake license context.
 *
 * Author: Jeremiah Buttler.
 */
interface LicenseContextInterface {

  /**
   * Returns the resolved license status for this request.
   */
  public function status(): LicenseStatus;

  /**
   * Convenience accessor: whether the module is licensed and active.
   */
  public function isActive(): bool;

  /**
   * Returns the effective license level for the given account.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to resolve.
   *
   * @return string
   *   Level name (e.g. 'free', 'standard'). Returns 'free' when unavailable.
   */
  public function getLevelForAccount(AccountInterface $account): string;

}
