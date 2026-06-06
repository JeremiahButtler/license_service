<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\Cost;

/**
 * Default cost calculator for the shell module.
 *
 * Always returns a "locked" result: the portable shell deliberately contains no
 * pricing or cost-estimation logic. The licensed engine submodule overrides the
 * "license_service_token_counter.cost" service with a real calculator that requires an active
 * site license and uses the engine's own local pricing table.
 *
 * Author: Jeremiah Buttler.
 */
final class NullCostCalculator implements CostCalculatorInterface {

  /**
   * {@inheritdoc}
   */
  public function calculate(UsageData $usage): CostResult {
    return CostResult::locked();
  }

}
