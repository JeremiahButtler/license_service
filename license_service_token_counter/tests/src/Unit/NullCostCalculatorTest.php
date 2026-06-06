<?php

declare(strict_types=1);

namespace Drupal\Tests\license_service_token_counter\Unit;

use Drupal\license_service_token_counter\Cost\CostResult;
use Drupal\license_service_token_counter\Cost\NullCostCalculator;
use Drupal\license_service_token_counter\Cost\UsageData;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\license_service_token_counter\Cost\NullCostCalculator
 * @group license_service_token_counter
 */
final class NullCostCalculatorTest extends UnitTestCase {

  /**
   * The shell calculator must never compute cost — it is always locked.
   *
   * @covers ::calculate
   */
  public function testAlwaysLocked(): void {
    $calculator = new NullCostCalculator();
    $usage = new UsageData('openai', 'gpt-4o', 'chat', 1000, 500, 0, 0, 1500);

    $result = $calculator->calculate($usage);

    $this->assertSame(CostResult::STATUS_LOCKED, $result->status);
    $this->assertFalse($result->isComputed());
    $this->assertNull($result->amount);
  }

}
