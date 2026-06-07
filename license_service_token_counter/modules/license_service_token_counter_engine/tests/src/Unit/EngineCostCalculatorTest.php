<?php

declare(strict_types=1);

namespace Drupal\Tests\license_service_token_counter_engine\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\license_service_token_counter\Cost\CostResult;
use Drupal\license_service_token_counter\Cost\UsageData;
use Drupal\license_service_token_counter\License\LicenseContextInterface;
use Drupal\license_service_token_counter_engine\Cost\EngineCostCalculator;
use Drupal\license_service_token_counter_engine\Entity\PricingTableInterface;
use Drupal\license_service_token_counter_engine\Pricing\PricingResolver;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\license_service_token_counter_engine\Cost\EngineCostCalculator
 * @group license_service_token_counter
 */
final class EngineCostCalculatorTest extends UnitTestCase {

  /**
   * Builds a mock PricingTableInterface with the given rate data.
   */
  private function mockTable(
    string $provider,
    int $unit,
    array $rates,
    int $weight = 0,
  ): PricingTableInterface {
    $table = $this->createMock(PricingTableInterface::class);
    $table->method('getProvider')->willReturn($provider);
    $table->method('getUnit')->willReturn($unit);
    $table->method('getRates')->willReturn($rates);
    $table->method('getWeight')->willReturn($weight);
    return $table;
  }

  /**
   * Returns a PricingResolver backed by the given table entities.
   *
   * @param array<string, PricingTableInterface> $tables
   *   Map of pricing table id => entity.
   * @param string $currency
   *   The display_currency setting value.
   */
  private function pricing(array $tables, string $currency = 'USD'): PricingResolver {
    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('execute')->willReturn(array_keys($tables));

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn($tables);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('pricing_table')
      ->willReturn($storage);

    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(
      static fn (string $key) => $key === 'display_currency' ? $currency : NULL
    );
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($settings);

    return new PricingResolver($entityTypeManager, $factory);
  }

  /**
   * Builds a calculator with the given pricing tables.
   *
   * @param array<string, PricingTableInterface> $tables
   *   Map of pricing table id => entity.
   * @param string $currency
   *   The display_currency setting value.
   * @param bool $licenseActive
   *   Whether the mocked license context reports an active license.
   */
  private function calculator(array $tables, string $currency = 'USD', bool $licenseActive = TRUE): EngineCostCalculator {
    $license = $this->createMock(LicenseContextInterface::class);
    $license->method('isActive')->willReturn($licenseActive);
    return new EngineCostCalculator(
      $this->pricing($tables, $currency),
      $license,
    );
  }

  /**
   * A site with no matching price table yields "unpriced".
   *
   * @covers ::calculate
   */
  public function testUnpricedWhenNoRate(): void {
    $table = $this->mockTable('openai', 1000000, [
      ['model' => 'gpt-4o', 'input' => 2.5, 'output' => 10.0],
    ]);
    $calculator = $this->calculator(['openai' => $table]);
    $result = $calculator->calculate(new UsageData('mistral', 'unknown', 'chat', 100, 50));

    $this->assertSame(CostResult::STATUS_UNPRICED, $result->status);
    $this->assertSame('USD', $result->currency);
  }

  /**
   * Cost is computed from per-1M-token rates, billing cached tokens separately.
   *
   * @covers ::calculate
   */
  public function testComputesCost(): void {
    $table = $this->mockTable('openai', 1000000, [
      ['model' => 'gpt-4o', 'input' => 2.5, 'output' => 10.0, 'cached' => 1.25],
    ]);
    $calculator = $this->calculator(['openai' => $table]);

    // 800 billable input + 200 cached + 500 output, 0 reasoning.
    $usage  = new UsageData('openai', 'gpt-4o', 'chat', 1000, 500, 200, 0, 1500);
    $result = $calculator->calculate($usage);

    $this->assertSame(CostResult::STATUS_COMPUTED, $result->status);
    $this->assertSame('USD', $result->currency);
    // 800*2.5 + 200*1.25 + 500*10 = 7250 ; /1e6 = 0.00725.
    $this->assertEqualsWithDelta(0.00725, $result->amount, 0.0000001);
  }

  /**
   * Wildcard provider/model pricing is honoured when no specific rate matches.
   *
   * @covers ::calculate
   */
  public function testWildcardPricing(): void {
    $table = $this->mockTable('*', 1000000, [
      ['model' => '*', 'input' => 1.0, 'output' => 2.0],
    ]);
    $calculator = $this->calculator(['wildcard' => $table]);

    $usage  = new UsageData('anyprovider', 'anymodel', 'chat', 1000000, 1000000);
    $result = $calculator->calculate($usage);

    // 1,000,000*1 + 1,000,000*2 = 3,000,000 ; /1e6 = 3.0.
    $this->assertSame(CostResult::STATUS_COMPUTED, $result->status);
    $this->assertEqualsWithDelta(3.0, $result->amount, 0.0000001);
  }

  /**
   * Per-table unit is used rather than any global setting.
   *
   * @covers ::calculate
   */
  public function testPerTableUnit(): void {
    // Unit of 500,000 (per half-million tokens) → same amount as per-million
    // but halved relative to a 1M unit table.
    $table = $this->mockTable('custom', 500000, [
      ['model' => '*', 'input' => 1.0, 'output' => 0.0],
    ]);
    $calculator = $this->calculator(['custom' => $table]);

    // 500,000 input tokens * 1.0 rate / 500,000 unit = 1.0.
    $usage  = new UsageData('custom', 'model', 'chat', 500000, 0);
    $result = $calculator->calculate($usage);

    $this->assertSame(CostResult::STATUS_COMPUTED, $result->status);
    $this->assertEqualsWithDelta(1.0, $result->amount, 0.0000001);
  }

}
