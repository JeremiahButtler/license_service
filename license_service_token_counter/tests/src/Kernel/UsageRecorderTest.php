<?php

declare(strict_types=1);

namespace Drupal\Tests\license_service_token_counter\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\license_service_token_counter\Cost\CostResult;
use Drupal\license_service_token_counter\Cost\UsageData;
use Drupal\license_service_token_counter\Service\UsageRecorder;

/**
 * @coversDefaultClass \Drupal\license_service_token_counter\Service\UsageRecorder
 * @group license_service_token_counter
 */
final class UsageRecorderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Only the shell module is enabled: its services depend on core alone, so the
   * recorder can be exercised without the AI or License contrib modules.
   */
  protected static $modules = ['license_service_token_counter'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('license_service_token_counter', ['license_service_token_usage']);
    $this->installConfig(['license_service_token_counter']);
  }

  /**
   * A recorded interaction is persisted with the expected values.
   *
   * @covers ::record
   */
  public function testRecordPersistsRow(): void {
    /** @var \Drupal\license_service_token_counter\Service\UsageRecorder $recorder */
    $recorder = $this->container->get('license_service_token_counter.recorder');
    $this->assertInstanceOf(UsageRecorder::class, $recorder);

    $usage = new UsageData('openai', 'gpt-4o', 'chat', 1000, 500, 200, 0, 1500);
    $cost = CostResult::computed(0.00725, 'USD');

    $uuid = $recorder->record($usage, $cost, 7, 'thread-123', ['my-call'], 'system.admin');
    $this->assertNotEmpty($uuid);

    $row = $this->container->get('database')->select('license_service_token_usage', 'u')
      ->fields('u')
      ->condition('u.uuid', $uuid)
      ->execute()
      ->fetchAssoc();

    $this->assertNotFalse($row);
    $this->assertSame('openai', $row['provider_id']);
    $this->assertSame('gpt-4o', $row['model_id']);
    $this->assertSame('chat', $row['operation_type']);
    $this->assertSame('7', (string) $row['uid']);
    $this->assertSame('1000', (string) $row['input_tokens']);
    $this->assertSame('500', (string) $row['output_tokens']);
    $this->assertSame('1500', (string) $row['total_tokens']);
    $this->assertSame(CostResult::STATUS_COMPUTED, $row['cost_status']);
    $this->assertSame('USD', $row['currency']);
    $this->assertSame('my-call', $row['tags']);
  }

  /**
   * A locked cost stores a NULL amount.
   *
   * @covers ::record
   */
  public function testLockedCostStoresNull(): void {
    /** @var \Drupal\license_service_token_counter\Service\UsageRecorder $recorder */
    $recorder = $this->container->get('license_service_token_counter.recorder');

    $usage = new UsageData('anthropic', 'claude', 'chat', 10, 20, 0, 0, 30);
    $uuid = $recorder->record($usage, CostResult::locked(), 0, 'thread-x');

    $row = $this->container->get('database')->select('license_service_token_usage', 'u')
      ->fields('u')
      ->condition('u.uuid', $uuid)
      ->execute()
      ->fetchAssoc();

    $this->assertNull($row['estimated_cost']);
    $this->assertSame(CostResult::STATUS_LOCKED, $row['cost_status']);
  }

}
