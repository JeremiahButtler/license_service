<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\License;

use Drupal\Core\Session\AccountInterface;
use Drupal\license_service\LicenseFeatureProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves the site's license state for the Token Counter module.
 *
 * Binds directly to license_service.manager (LicenseFeatureProvider) via
 * constructor injection. The previous defensive service-container probing
 * (CANDIDATE_SERVICE_IDS) has been replaced by a hard dependency, because:
 *
 *  1. license_service is declared as a hard dependency in the module's
 *     .info.yml — Drupal will not enable this module without it.
 *  2. Concrete injection (rather than a narrow stub-able interface) makes the
 *     seam harder to replicate without license_service present. This is a
 *     deliberate anti-extraction design choice: any attempt to run
 *     license_service_token_counter standalone must reproduce the full
 *     LicenseFeatureProviderInterface surface backed by a real signed token.
 *
 * Token capture continues regardless of license state. Only cost estimation
 * and per-level quota enforcement are gated on an active license.
 *
 * Author: Jeremiah Buttler.
 */
final class LicenseBridge implements LicenseContextInterface {

  /**
   * The feature key that gates this module within the entitlement envelope.
   */
  private const FEATURE_KEY = 'license_service_token_counter';

  /**
   * Per-request memoized status.
   */
  private ?LicenseStatus $resolved = NULL;

  /**
   * Constructs a LicenseBridge.
   *
   * @param \Drupal\license_service\LicenseFeatureProviderInterface $licenseProvider
   *   The license_service.manager service. Injected directly (not probed) as a
   *   deliberate concrete dependency — see class-level docblock.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module's log channel.
   */
  public function __construct(
    private readonly LicenseFeatureProviderInterface $licenseProvider,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns the resolved license status for this request.
   *
   * Memoized per-request so multiple callers pay only one check.
   */
  public function status(): LicenseStatus {
    if ($this->resolved instanceof LicenseStatus) {
      return $this->resolved;
    }

    try {
      $active  = $this->licenseProvider->isActive()
               && $this->licenseProvider->hasFeature(self::FEATURE_KEY);
      $feature = $active ? $this->licenseProvider->getFeature(self::FEATURE_KEY) : NULL;
      $warnings = $this->normalizeWarnings($this->licenseProvider->getWarnings());

      return $this->resolved = new LicenseStatus($active, TRUE, $feature, $warnings);
    }
    catch (\Throwable $e) {
      // A misbehaving provider must never crash AI calls or the report page.
      $this->logger->warning('License Module check failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return $this->resolved = LicenseStatus::unavailable();
    }
  }

  /**
   * Convenience accessor: whether the module is licensed and active.
   */
  public function isActive(): bool {
    return $this->status()->isActive();
  }

  /**
   * Returns the effective license level for the given account.
   */
  public function getLevelForAccount(AccountInterface $account): string {
    try {
      return $this->licenseProvider->getLevelForAccount($account);
    }
    catch (\Throwable) {
      return 'free';
    }
  }

  /**
   * Coerces provider warnings into a clean list of strings.
   */
  private function normalizeWarnings(mixed $warnings): array {
    if (!is_array($warnings)) {
      return [];
    }

    $clean = [];
    foreach ($warnings as $warning) {
      if (is_string($warning) && $warning !== '') {
        $clean[] = $warning;
      }
      elseif (is_array($warning) && isset($warning['message']) && is_string($warning['message'])) {
        $clean[] = $warning['message'];
      }
    }
    return $clean;
  }

}
