<?php

declare(strict_types=1);

namespace Drupal\Tests\license_service_token_counter_engine\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\license_service_token_counter_engine\Entity\PricingTableInterface;
use Drupal\license_service_token_counter_engine\Pricing\PricingResolver;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\license_service_token_counter_engine\Pricing\PricingResolver
 * @group license_service_token_counter
 */
final class PricingResolverTest extends UnitTestCase {

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
   * Builds a resolver over the given keyed table entities.
   *
   * @param array<string, PricingTableInterface> $tables  id => entity.
   * @param string $settingsCurrency  Value returned by display_currency setting.
   */
  private function resolver(array $tables, string $settingsCurrency = 'USD'): PricingResolver {
    // Fluent entity query mock.
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
      static fn (string $key) => $key === 'display_currency' ? $settingsCurrency : NULL
    );
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($settings);

    return new PricingResolver($entityTypeManager, $factory);
  }

  /**
   * Currency is read from the display_currency setting.
   *
   * @covers ::currency
   */
  public function testCurrencyFromSettings(): void {
    $this->assertSame('EUR', $this->resolver([], 'EUR')->currency());
    $this->assertSame('GBP', $this->resolver([], 'GBP')->currency());
  }

  /**
   * An empty display_currency setting defaults to USD.
   *
   * @covers ::currency
   */
  public function testCurrencyDefaultsToUsd(): void {
    $this->assertSame('USD', $this->resolver([], '')->currency());
  }

  /**
   * Absent cached/reasoning fall back to input/output rates; unit is included.
   *
   * @covers ::rates
   */
  public function testRateFallbacksAndUnitPresent(): void {
    $table = $this->mockTable('openai', 1000000, [
      ['model' => 'gpt-4o', 'input' => 2.5, 'output' => 10.0],
    ]);
    $rates = $this->resolver(['openai' => $table])->rates('openai', 'gpt-4o');

    $this->assertNotNull($rates);
    $this->assertSame(2.5,     $rates['input']);
    $this->assertSame(10.0,    $rates['output']);
    // Absent cached falls back to input rate.
    $this->assertSame(2.5,     $rates['cached']);
    // Absent reasoning falls back to output rate.
    $this->assertSame(10.0,    $rates['reasoning']);
    // Unit is part of the resolved entry.
    $this->assertSame(1000000, $rates['unit']);
  }

  /**
   * Explicit cached and reasoning rates override the fallbacks.
   *
   * @covers ::rates
   */
  public function testExplicitCachedAndReasoning(): void {
    $table = $this->mockTable('openai', 1000000, [
      ['model' => 'gpt-4o', 'input' => 2.5, 'output' => 10.0, 'cached' => 1.25, 'reasoning' => 30.0],
    ]);
    $rates = $this->resolver(['openai' => $table])->rates('openai', 'gpt-4o');

    $this->assertSame(1.25, $rates['cached']);
    $this->assertSame(30.0, $rates['reasoning']);
  }

  /**
   * An unknown provider/model with no wildcard yields NULL (unpriced).
   *
   * @covers ::rates
   */
  public function testUnknownIsNull(): void {
    $table = $this->mockTable('openai', 1000000, [
      ['model' => 'gpt-4o', 'input' => 2.5, 'output' => 10.0],
    ]);
    $this->assertNull($this->resolver(['openai' => $table])->rates('mistral', 'unknown'));
  }

  /**
   * No enabled tables → NULL rates rather than an error.
   *
   * @covers ::rates
   */
  public function testEmptyTablesIsNull(): void {
    $this->assertNull($this->resolver([])->rates('openai', 'gpt-4o'));
  }

  /**
   * A wildcard model row ('*') matches any unknown model for the provider.
   *
   * @covers ::rates
   */
  public function testWildcardModelFallback(): void {
    $table = $this->mockTable('openai', 1000000, [
      ['model' => '*', 'input' => 1.0, 'output' => 2.0],
    ]);
    $rates = $this->resolver(['openai' => $table])->rates('openai', 'any-future-model');
    $this->assertNotNull($rates);
    $this->assertSame(1.0, $rates['input']);
  }

  /**
   * A wildcard provider row ('*' / '*') matches any provider and model.
   *
   * @covers ::rates
   */
  public function testWildcardProviderFallback(): void {
    $table = $this->mockTable('*', 1000000, [
      ['model' => '*', 'input' => 0.5, 'output' => 1.0],
    ]);
    $rates = $this->resolver(['wildcard' => $table])->rates('unknownprovider', 'anymodel');
    $this->assertNotNull($rates);
    $this->assertSame(0.5, $rates['input']);
  }

  /**
   * First-write-wins: the table with the lower weight takes precedence.
   *
   * @covers ::rates
   */
  public function testFirstWriteWins(): void {
    // weight=0 (higher priority) table has input=1.0.
    $high = $this->mockTable('openai', 1000000, [
      ['model' => '*', 'input' => 1.0, 'output' => 2.0],
    ], weight: 0);
    // weight=10 (lower priority) table has input=5.0.
    $low = $this->mockTable('openai', 1000000, [
      ['model' => '*', 'input' => 5.0, 'output' => 10.0],
    ], weight: 10);

    $rates = $this->resolver(['high' => $high, 'low' => $low])->rates('openai', 'some-model');
    $this->assertSame(1.0, $rates['input']);
  }

  /**
   * Per-table unit is preserved in the resolved entry.
   *
   * @covers ::rates
   */
  public function testPerTableUnitPreserved(): void {
    $table = $this->mockTable('custom', 500000, [
      ['model' => '*', 'input' => 1.0, 'output' => 1.0],
    ]);
    $rates = $this->resolver(['custom' => $table])->rates('custom', 'model');
    $this->assertSame(500000, $rates['unit']);
  }

}
