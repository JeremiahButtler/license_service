<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\Service;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\license_service_token_counter\Cost\CostResult;
use Drupal\license_service_token_counter\Cost\UsageData;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Persists AI token usage rows.
 *
 * All writes use the Database API with parameterized values. The recorder owns
 * no business logic beyond persistence and the retention window.
 *
 * Author: Jeremiah Buttler.
 */
final class UsageRecorder {

  /**
   * Constructs the recorder.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   UUID generator.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Time service.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly UuidInterface $uuid,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Inserts one usage record.
   *
   * @param \Drupal\license_service_token_counter\Cost\UsageData $usage
   *   Captured token usage.
   * @param \Drupal\license_service_token_counter\Cost\CostResult $cost
   *   The cost result for the interaction.
   * @param int $uid
   *   User id who triggered the call.
   * @param string $requestThreadId
   *   AI module request thread id.
   * @param array $tags
   *   AI call tags.
   * @param string $hostModule
   *   Best-effort initiating module/route.
   *
   * @return string
   *   The UUID of the inserted record.
   */
  public function record(
    UsageData $usage,
    CostResult $cost,
    int $uid,
    string $requestThreadId,
    array $tags = [],
    string $hostModule = '',
  ): string {
    $uuid = $this->uuid->generate();

    $this->database->insert('license_service_token_usage')
      ->fields([
        'uuid' => $uuid,
        'created' => $this->time->getRequestTime(),
        'uid' => $uid,
        'provider_id' => $this->trim($usage->providerId, 128),
        'model_id' => $this->trim($usage->modelId, 255),
        'operation_type' => $this->trim($usage->operationType, 64),
        'request_thread_id' => $this->trim($requestThreadId, 255),
        'input_tokens' => max(0, $usage->inputTokens),
        'output_tokens' => max(0, $usage->outputTokens),
        'cached_tokens' => max(0, $usage->cachedTokens),
        'reasoning_tokens' => max(0, $usage->reasoningTokens),
        'total_tokens' => max(0, $usage->effectiveTotal()),
        'estimated_cost' => $cost->isComputed() ? $cost->amount : NULL,
        'currency' => $this->trim($cost->currency, 8),
        'cost_status' => $cost->status,
        'tags' => $this->trim($this->flattenTags($tags), 1024),
        'host_module' => $this->trim($hostModule, 128),
      ])
      ->execute();

    return $uuid;
  }

  /**
   * Deletes records older than the configured retention window.
   *
   * @return int
   *   Number of rows removed.
   */
  public function purgeExpired(): int {
    $retention_days = (int) $this->configFactory->get('license_service_token_counter.settings')->get('retention_days');
    if ($retention_days <= 0) {
      return 0;
    }

    $cutoff = $this->time->getRequestTime() - ($retention_days * 86400);
    return (int) $this->database->delete('license_service_token_usage')
      ->condition('created', $cutoff, '<')
      ->execute();
  }

  /**
   * Flattens AI call tags into a comma-separated string.
   */
  private function flattenTags(array $tags): string {
    $clean = array_filter(array_map(static function ($tag): string {
      return is_scalar($tag) ? (string) $tag : '';
    }, $tags), static fn (string $t): bool => $t !== '');

    return implode(',', $clean);
  }

  /**
   * Safely truncates a string to a column length.
   */
  private function trim(string $value, int $length): string {
    return mb_substr($value, 0, $length);
  }

}
