<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\License;

/**
 * Immutable snapshot of the site's license state for this module.
 *
 * Author: Jeremiah Buttler.
 */
final class LicenseStatus {

  /**
   * Constructs a LicenseStatus.
   *
   * @param bool $active
   *   Whether the license is active and the module feature is granted.
   * @param bool $providerPresent
   *   Whether a compatible License Module service was found at all.
   * @param mixed $feature
   *   The feature/entitlement payload returned by the License Module for this
   *   module's feature key (capability flags, limits, etc.), or NULL. Pricing is
   *   NOT carried here — the Cost Engine owns its pricing table locally.
   * @param array $warnings
   *   Non-fatal warnings to surface in the UI.
   */
  public function __construct(
    private readonly bool $active,
    private readonly bool $providerPresent,
    private readonly mixed $feature = NULL,
    private readonly array $warnings = [],
  ) {}

  /**
   * Creates a status representing "no compatible License Module present".
   */
  public static function unavailable(): self {
    return new self(FALSE, FALSE, NULL, []);
  }

  /**
   * Whether the module is licensed and active.
   */
  public function isActive(): bool {
    return $this->active;
  }

  /**
   * Whether a compatible License Module service was found.
   */
  public function isProviderPresent(): bool {
    return $this->providerPresent;
  }

  /**
   * The feature payload (e.g. the pricing table), or NULL.
   */
  public function getFeature(): mixed {
    return $this->feature;
  }

  /**
   * Non-fatal warnings to surface in the UI.
   */
  public function getWarnings(): array {
    return $this->warnings;
  }

}
