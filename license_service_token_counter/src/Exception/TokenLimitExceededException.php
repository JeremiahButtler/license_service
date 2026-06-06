<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\Exception;

/**
 * Thrown by the enforcement subscriber when an AI call would exceed a limit.
 *
 * Callers that wrap AI calls (e.g. AI chat form, Explorer, custom code) can
 * catch this exception and present a user-friendly message. Uncaught, it
 * propagates and prevents the underlying API request from completing.
 *
 * Author: Jeremiah Buttler.
 */
final class TokenLimitExceededException extends \RuntimeException {

  /**
   * Creates a new TokenLimitExceededException.
   *
   * @param string $limitLabel
   *   Human-readable label of the exceeded limit rule.
   * @param int $used
   *   Tokens already used in the current period.
   * @param int $max
   *   The limit that was exceeded.
   * @param string $period
   *   The period key (day, week, …).
   */
  public function __construct(
    private readonly string $limitLabel,
    private readonly int $used,
    private readonly int $max,
    private readonly string $period,
  ) {
    parent::__construct(sprintf(
      'AI token limit "%s" exceeded: %s of %s tokens used this %s.',
      $limitLabel,
      number_format($used),
      number_format($max),
      $period,
    ));
  }

  /**
   * Returns the human-readable label for the violated limit.
   */
  public function getLimitLabel(): string {
    return $this->limitLabel;
  }

  /**
   * Returns the number of tokens consumed in the current period.
   */
  public function getUsed(): int {
    return $this->used;
  }

  /**
   * Returns the token limit that was exceeded.
   */
  public function getMax(): int {
    return $this->max;
  }

  /**
   * Returns the period key for the violated limit (day, week, month, etc.).
   */
  public function getPeriod(): string {
    return $this->period;
  }

}
