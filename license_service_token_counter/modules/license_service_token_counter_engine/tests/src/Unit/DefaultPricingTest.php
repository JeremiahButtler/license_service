<?php

declare(strict_types=1);

namespace Drupal\Tests\license_service_token_counter_engine\Unit;

use Drupal\license_service_token_counter_engine\Pricing\DefaultPricing;
use Drupal\Tests\UnitTestCase;

/**
 * Locks the bundled default pricing to real drupal/ai provider plugin ids.
 *
 * The drupal/ai provider plugins expose ids such as 'bedrock' and 'gemini' —
 * NOT the module names ('ai_provider_aws_bedrock') nor the vendor labels
 * ('aws_bedrock' / 'google_gemini'). Seeding keys off these ids, so a drift
 * back to a non-conforming key would silently stop a provider from auto-seeding.
 * This test fails loudly if that happens again.
 *
 * @coversDefaultClass \Drupal\license_service_token_counter_engine\Pricing\DefaultPricing
 * @group license_service_token_counter
 */
final class DefaultPricingTest extends UnitTestCase {

  /**
   * Provider ids that MUST be present, keyed by canonical drupal/ai plugin id.
   *
   * @covers ::allProviders
   */
  public function testCanonicalProviderIdsPresent(): void {
    $providers = DefaultPricing::allProviders();
    foreach (['openai', 'anthropic', 'gemini', 'mistral', 'groq', 'bedrock', 'fireworks'] as $id) {
      $this->assertContains($id, $providers, "Expected bundled pricing for provider id '$id'.");
    }
  }

  /**
   * Non-conforming legacy keys must NOT reappear.
   *
   * @covers ::allProviders
   */
  public function testLegacyKeysAbsent(): void {
    $providers = DefaultPricing::allProviders();
    $this->assertNotContains('aws_bedrock', $providers, "Use the drupal/ai id 'bedrock', not 'aws_bedrock'.");
    $this->assertNotContains('google_gemini', $providers, "Use the drupal/ai id 'gemini', not 'google_gemini'.");
  }

  /**
   * Every provider's rate list ends with a '*' wildcard catch-all row.
   *
   * @covers ::ratesForProvider
   */
  public function testEveryProviderHasWildcardFallback(): void {
    foreach (DefaultPricing::allProviders() as $id) {
      $rows = DefaultPricing::ratesForProvider($id);
      $this->assertNotNull($rows, "Provider '$id' should have rate rows.");
      $this->assertNotEmpty($rows, "Provider '$id' should have at least one rate row.");
      $last = end($rows);
      $this->assertSame('*', $last['model'], "Provider '$id' must end with a '*' wildcard fallback row.");
    }
  }

  /**
   * Each rate row carries the documented shape (model/input/output keys).
   *
   * @covers ::ratesForProvider
   */
  public function testRateRowShape(): void {
    foreach (DefaultPricing::allProviders() as $id) {
      foreach (DefaultPricing::ratesForProvider($id) as $row) {
        $this->assertArrayHasKey('model', $row);
        $this->assertArrayHasKey('input', $row);
        $this->assertArrayHasKey('output', $row);
        $this->assertIsFloat($row['input'] + 0.0);
        $this->assertIsFloat($row['output'] + 0.0);
      }
    }
  }

  /**
   * The Bedrock table is keyed 'bedrock' and prices real Bedrock model ids.
   *
   * @covers ::ratesForProvider
   */
  public function testBedrockRatesUnderCanonicalKey(): void {
    $rows = DefaultPricing::ratesForProvider('bedrock');
    $this->assertNotNull($rows);
    $models = array_column($rows, 'model');
    $this->assertContains('amazon.nova-pro-v1:0', $models);
    $this->assertContains('anthropic.claude-3-5-sonnet-20241022-v2:0', $models);
  }

  /**
   * An unknown provider id yields NULL rather than an empty array or error.
   *
   * @covers ::ratesForProvider
   */
  public function testUnknownProviderIsNull(): void {
    $this->assertNull(DefaultPricing::ratesForProvider('not-a-real-provider'));
  }

}
