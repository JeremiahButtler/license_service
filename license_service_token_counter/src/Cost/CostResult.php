<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\Cost;

/**
 * Immutable result of a cost calculation.
 *
 * Author: Jeremiah Buttler.
 */
final class CostResult {

  /**
   * Cost was computed from licensed pricing.
   */
  public const STATUS_COMPUTED = 'computed';

  /**
   * Cost is unavailable because no active license is present.
   */
  public const STATUS_LOCKED = 'locked';

  /**
   * The site is licensed, but no price exists for this provider/model.
   */
  public const STATUS_UNPRICED = 'unpriced';

  /**
   * Constructs a CostResult.
   *
   * @param string $status
   *   One of the STATUS_* constants.
   * @param float|null $amount
   *   The estimated cost, or NULL when not computed.
   * @param string $currency
   *   ISO currency code for the amount.
   */
  public function __construct(
    public readonly string $status,
    public readonly ?float $amount = NULL,
    public readonly string $currency = '',
  ) {}

  /**
   * Creates a "locked" result (no active license).
   */
  public static function locked(): self {
    return new self(self::STATUS_LOCKED);
  }

  /**
   * Creates an "unpriced" result (licensed but no matching price).
   */
  public static function unpriced(string $currency = ''): self {
    return new self(self::STATUS_UNPRICED, NULL, $currency);
  }

  /**
   * Creates a "computed" result.
   */
  public static function computed(float $amount, string $currency): self {
    return new self(self::STATUS_COMPUTED, $amount, $currency);
  }

  /**
   * Whether a numeric cost is available.
   */
  public function isComputed(): bool {
    return $this->status === self::STATUS_COMPUTED && $this->amount !== NULL;
  }

}
