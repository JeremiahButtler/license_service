<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter_engine\Cost;

use Drupal\license_service_token_counter\Cost\CostCalculatorInterface;
use Drupal\license_service_token_counter\Cost\CostResult;
use Drupal\license_service_token_counter\Cost\UsageData;
use Drupal\license_service_token_counter\License\LicenseContextInterface;
use Drupal\license_service_token_counter_engine\Pricing\PricingResolver;

/**
 * Cost calculator powered by the module's local pricing tables.
 *
 * Computes estimated cost from the token counts and the module's own local
 * pricing tables (see PricingResolver). Returns "locked" when no active site
 * license is present, or "unpriced" when the license is valid but no pricing
 * table covers the provider/model pair. No server round-trip is made to
 * estimate cost — pricing is self-contained and local.
 *
 * License check is performed at runtime (in calculate()), not at container-
 * build time, so a license change takes effect on the next request without
 * requiring a cache rebuild.
 *
 * Author: Jeremiah Buttler.
 */
final class EngineCostCalculator implements CostCalculatorInterface {

  /**
   * Constructs the calculator.
   *
   * @param \Drupal\license_service_token_counter_engine\Pricing\PricingResolver $pricing
   *   Resolves per-provider/per-model pricing from enabled pricing tables.
   * @param \Drupal\license_service_token_counter\License\LicenseContextInterface $licenseContext
   *   The license bridge. Injected as a concrete dependency so cost estimation
   *   is genuinely license-gated and cannot be trivially extracted without
   *   a valid license_service provider behind it.
   */
  public function __construct(
    private readonly PricingResolver $pricing,
    private readonly LicenseContextInterface $licenseContext,
  ) {}

  /**
   * {@inheritdoc}
   *
   * Returns CostResult::locked() when the site license is not active, so the
   * UsageAggregator::getCostSummary() automatically reports 0 (it only sums
   * STATUS_COMPUTED rows). Token capture is unaffected by this check.
   */
  public function calculate(UsageData $usage): CostResult {
    // Gate: cost computation requires an active site license.
    if (!$this->licenseContext->isActive()) {
      return CostResult::locked();
    }

    $currency = $this->pricing->currency();
    $rates    = $this->pricing->rates($usage->providerId, $usage->modelId);
    if ($rates === NULL) {
      return CostResult::unpriced($currency);
    }

    // Non-cached input tokens are billed at the input rate; cached tokens at
    // the (usually lower) cached rate. Guard against double-counting when a
    // provider reports cached tokens as a subset of input tokens.
    $billable_input = max(0, $usage->inputTokens - $usage->cachedTokens);

    $amount =
      ($billable_input * $rates['input'])
      + ($usage->cachedTokens * $rates['cached'])
      + ($usage->outputTokens * $rates['output'])
      + ($usage->reasoningTokens * $rates['reasoning']);

    $amount = $amount / $rates['unit'];

    return CostResult::computed(round($amount, 8), $currency);
  }

}
