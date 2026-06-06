<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\Service;

/**
 * Contract for aggregating AI token usage from the recording table.
 *
 * Companion modules (e.g. License Service: Token Limits) type-hint on this
 * interface rather than the concrete UsageAggregator so they can mock
 * the aggregator in unit tests without depending on final-class mocking.
 *
 * Author: Jeremiah Buttler.
 */
interface UsageAggregatorInterface {

  /**
   * Returns the total tokens used by a single user within a period.
   *
   * @param int $uid
   *   Drupal user id.
   * @param string $period
   *   One of: day, week, month, year, lifetime. Defaults to 'lifetime'.
   *
   * @return int
   *   Token count (0 when no records match).
   */
  public function getUserTokens(int $uid, string $period = 'lifetime'): int;

  /**
   * Returns the site-wide total tokens within a period.
   *
   * @param string $period
   *   One of: day, week, month, year, lifetime. Defaults to 'lifetime'.
   *
   * @return int
   *   Token count (0 when no records match).
   */
  public function getSiteTokens(string $period = 'lifetime'): int;

  /**
   * Returns per-user token totals within a period, ordered by usage descending.
   *
   * @param string $period
   *   One of: day, week, month, year, lifetime. Defaults to 'lifetime'.
   * @param int $limit
   *   Maximum number of rows to return.
   *
   * @return array<int, int>
   *   Array keyed by uid (int) with token count (int) as value.
   */
  public function getPerUserTokens(string $period = 'lifetime', int $limit = 100): array;

  /**
   * Returns per-provider, per-model token breakdown within a scope and period.
   *
   * @param int|null $uid
   *   When non-null, restrict to this user. NULL = site-wide.
   * @param string $period
   *   One of: day, week, month, year, lifetime. Defaults to 'lifetime'.
   *
   * @return list<array{provider: string, total: int, models: list<array{model: string, tokens: int}>}>
   *   Ordered list of provider entries, each with per-model rows.
   */
  public function getBreakdown(?int $uid, string $period = 'lifetime'): array;

  /**
   * Returns the estimated cost total for a scope and period.
   *
   * @param int|null $uid
   *   When non-null, restrict to this user. NULL = site-wide.
   * @param string $period
   *   One of: day, week, month, year, lifetime. Defaults to 'lifetime'.
   *
   * @return array{amount: float, currency: string}
   *   Aggregated estimated cost amount and currency code.
   */
  public function getCostSummary(?int $uid, string $period = 'lifetime'): array;

}
