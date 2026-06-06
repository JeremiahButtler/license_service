<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\Cost;

/**
 * Estimates the cost of an AI interaction from its token usage.
 *
 * The default (shell) implementation always returns a "locked" result. The
 * licensed engine submodule replaces the service behind this interface with an
 * implementation that computes real cost from its own local pricing table, and
 * only while an active license is present. There is intentionally no working
 * pricing logic in the portable shell module.
 *
 * Author: Jeremiah Buttler.
 */
interface CostCalculatorInterface {

  /**
   * Calculates the estimated cost for a single interaction.
   *
   * @param \Drupal\license_service_token_counter\Cost\UsageData $usage
   *   The captured token usage.
   *
   * @return \Drupal\license_service_token_counter\Cost\CostResult
   *   The cost result, including a status describing why a value may be absent.
   */
  public function calculate(UsageData $usage): CostResult;

}
