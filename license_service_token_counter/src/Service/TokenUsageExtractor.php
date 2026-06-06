<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\Service;

use Drupal\license_service_token_counter\Cost\UsageData;

/**
 * Extracts token usage from an AI module response, defensively.
 *
 * The AI module's token-usage API is still evolving and not every provider
 * normalizes usage yet, so this reads counts through three layers, in order:
 *   1. Normalized getters on the output (AI >= 1.2.0-alpha2).
 *   2. The output's metadata array.
 *   3. The provider's raw response, parsed for the common provider shapes.
 *
 * All access is guarded so an unexpected output shape yields zeroes rather than
 * an error during a live AI call.
 *
 * Author: Jeremiah Buttler.
 */
final class TokenUsageExtractor {

  /**
   * Constructs the extractor.
   */
  public function __construct() {}

  /**
   * Builds a UsageData object from an AI output object plus call context.
   *
   * @param object|null $output
   *   The AI module output (e.g. ChatOutput), or NULL.
   * @param string $providerId
   *   Provider plugin id.
   * @param string $modelId
   *   Model id.
   * @param string $operationType
   *   Operation type.
   */
  public function extract(?object $output, string $providerId, string $modelId, string $operationType): UsageData {
    $input = $cached = $reasoning = $totalOut = 0;
    $totalAll = 0;

    if ($output !== NULL) {
      [$input, $totalOut, $cached, $reasoning, $totalAll] = $this->fromGetters($output);

      if ($input === 0 && $totalOut === 0 && $totalAll === 0) {
        $usage = $this->locateUsageArray($output);
        if ($usage !== NULL) {
          [$input, $totalOut, $cached, $reasoning, $totalAll] = $this->fromUsageArray($usage);
        }
      }
    }

    return new UsageData(
      providerId: $providerId,
      modelId: $modelId,
      operationType: $operationType,
      inputTokens: $input,
      outputTokens: $totalOut,
      cachedTokens: $cached,
      reasoningTokens: $reasoning,
      totalTokens: $totalAll,
    );
  }

  /**
   * Reads normalized token getters from the output object.
   *
   * @return array{int,int,int,int,int}
   *   [input, output, cached, reasoning, total].
   */
  private function fromGetters(object $output): array {
    $read = static function (object $o, string $method): int {
      if (!method_exists($o, $method)) {
        return 0;
      }
      try {
        $value = $o->{$method}();
      }
      catch (\Throwable) {
        return 0;
      }
      return is_numeric($value) ? (int) $value : 0;
    };

    return [
      $read($output, 'getInputTokenUsage'),
      $read($output, 'getOutputTokenUsage'),
      $read($output, 'getCachedTokenUsage'),
      $read($output, 'getReasoningTokenUsage'),
      $read($output, 'getTotalTokenUsage'),
    ];
  }

  /**
   * Finds a usage array from the output's metadata or raw response.
   */
  private function locateUsageArray(object $output): ?array {
    foreach (['getMetadata', 'getRawOutput'] as $method) {
      if (!method_exists($output, $method)) {
        continue;
      }
      try {
        $data = $output->{$method}();
      }
      catch (\Throwable) {
        continue;
      }

      $data = $this->toArray($data);
      if ($data === NULL) {
        continue;
      }

      // Common nesting keys across providers.
      foreach (['usage', 'usageMetadata', 'token_usage'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
          return $data[$key];
        }
      }
      // Some providers place usage at the top level.
      if ($this->looksLikeUsage($data)) {
        return $data;
      }
    }

    return NULL;
  }

  /**
   * Parses a provider usage array into normalized counts.
   *
   * @return array{int,int,int,int,int}
   *   [input, output, cached, reasoning, total].
   */
  private function fromUsageArray(array $usage): array {
    $pick = static function (array $a, array $keys): int {
      foreach ($keys as $key) {
        if (isset($a[$key]) && is_numeric($a[$key])) {
          return (int) $a[$key];
        }
      }
      return 0;
    };

    $input = $pick($usage, ['prompt_tokens', 'input_tokens', 'promptTokenCount', 'inputTokens']);
    $output = $pick($usage, ['completion_tokens', 'output_tokens', 'candidatesTokenCount', 'outputTokens']);
    $total = $pick($usage, ['total_tokens', 'totalTokenCount', 'totalTokens']);
    $reasoning = $pick($usage, ['reasoning_tokens', 'thoughts_token_count', 'thoughtsTokenCount']);

    // Cached tokens may be nested in a details sub-array (OpenAI) or flat.
    $cached = $pick($usage, [
      'cached_tokens',
      'cache_read_input_tokens',
      'cachedContentTokenCount',
    ]);
    if ($cached === 0 && isset($usage['prompt_tokens_details']) && is_array($usage['prompt_tokens_details'])) {
      $cached = $pick($usage['prompt_tokens_details'], ['cached_tokens']);
    }

    return [$input, $output, $cached, $reasoning, $total];
  }

  /**
   * Normalizes mixed data (array, JSON string, object) to an array, or NULL.
   */
  private function toArray(mixed $data): ?array {
    if (is_array($data)) {
      return $data;
    }
    if (is_string($data) && $data !== '') {
      $decoded = json_decode($data, TRUE);
      return is_array($decoded) ? $decoded : NULL;
    }
    if (is_object($data)) {
      $decoded = json_decode(json_encode($data) ?: 'null', TRUE);
      return is_array($decoded) ? $decoded : NULL;
    }
    return NULL;
  }

  /**
   * Heuristic: does a top-level array look like a usage block?
   */
  private function looksLikeUsage(array $data): bool {
    foreach (['prompt_tokens', 'input_tokens', 'promptTokenCount', 'total_tokens'] as $key) {
      if (isset($data[$key])) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
