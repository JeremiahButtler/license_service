<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\Cost;

/**
 * Immutable description of a single AI interaction's token usage.
 *
 * Author: Jeremiah Buttler.
 */
final class UsageData {

  /**
   * Constructs a UsageData object.
   *
   * @param string $providerId
   *   AI provider plugin id (e.g. "openai").
   * @param string $modelId
   *   Model id used for the call.
   * @param string $operationType
   *   AI operation type (e.g. "chat").
   * @param int $inputTokens
   *   Input/prompt tokens.
   * @param int $outputTokens
   *   Output/completion tokens.
   * @param int $cachedTokens
   *   Cached/cache-read tokens.
   * @param int $reasoningTokens
   *   Reasoning tokens.
   * @param int $totalTokens
   *   Total tokens for the call.
   */
  public function __construct(
    public readonly string $providerId,
    public readonly string $modelId,
    public readonly string $operationType,
    public readonly int $inputTokens,
    public readonly int $outputTokens,
    public readonly int $cachedTokens = 0,
    public readonly int $reasoningTokens = 0,
    public readonly int $totalTokens = 0,
  ) {}

  /**
   * Total tokens, falling back to the sum of the parts when not reported.
   */
  public function effectiveTotal(): int {
    if ($this->totalTokens > 0) {
      return $this->totalTokens;
    }
    return $this->inputTokens + $this->outputTokens;
  }

  /**
   * Whether any token count was captured at all.
   */
  public function hasTokens(): bool {
    return ($this->inputTokens + $this->outputTokens + $this->cachedTokens
      + $this->reasoningTokens + $this->totalTokens) > 0;
  }

}
