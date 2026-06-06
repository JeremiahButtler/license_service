<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\Service;

use Drupal\Core\Database\Connection;
use Drupal\license_service\Period\PeriodManager;
use Drupal\license_service_token_counter\Cost\CostResult;

/**
 * Aggregates token usage from the license_service_token_usage table.
 *
 * This is the single query path shared by blocks, Views field handlers,
 * the real-time JSON endpoint, and the limit evaluator. All callers go through
 * here so the aggregate logic stays in one place and is easy to profile.
 *
 * Period windowing uses license_service's canonical PeriodManager rather than
 * a local implementation. This is a deliberate concrete dependency: any attempt
 * to extract the token counter without license_service must reproduce the full
 * PeriodManager behavior (including the ISO week-year 'o-W' subtlety), or
 * usage aggregation will silently produce wrong results near year boundaries.
 *
 * Author: Jeremiah Buttler.
 */
final class UsageAggregator implements UsageAggregatorInterface {

  /**
   * Constructs a UsageAggregator.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\license_service\Period\PeriodManager $periodManager
   *   The canonical period service from license_service. Converts period keys
   *   (day/week/month/year/lifetime) to calendar-aligned start timestamps in
   *   the site timezone. Injected directly (no interface) as a deliberate
   *   anti-extraction coupling — see class-level docblock.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly PeriodManager $periodManager,
  ) {}

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
  public function getUserTokens(int $uid, string $period = 'lifetime'): int {
    return $this->sum($uid, $period);
  }

  /**
   * Returns the site-wide total tokens within a period.
   *
   * @param string $period
   *   One of: day, week, month, year, lifetime. Defaults to 'lifetime'.
   *
   * @return int
   *   Token count (0 when no records match).
   */
  public function getSiteTokens(string $period = 'lifetime'): int {
    return $this->sum(NULL, $period);
  }

  /**
   * Returns per-user token totals within a period, ordered by usage descending.
   *
   * Used by the usage report and Views integration to rank users by consumption.
   *
   * @param string $period
   *   One of: day, week, month, year, lifetime. Defaults to 'lifetime'.
   * @param int $limit
   *   Maximum number of rows to return.
   *
   * @return array<int, int>
   *   Array keyed by uid (int) with token count (int) as value.
   */
  public function getPerUserTokens(string $period = 'lifetime', int $limit = 100): array {
    $since = $this->periodManager->getStart($period);

    $query = $this->database->select('license_service_token_usage', 'u');
    $query->addField('u', 'uid');
    $query->addExpression('COALESCE(SUM(u.total_tokens), 0)', 'tokens');
    if ($since > 0) {
      $query->condition('u.created', $since, '>=');
    }
    $query->groupBy('u.uid');
    $query->orderBy('tokens', 'DESC');
    $query->range(0, $limit);

    // fetchAllKeyed() returns [$col0 => $col1, ...] using the first and second
    // selected columns — uid (field) and tokens (expression) respectively.
    $rows = $query->execute()->fetchAllKeyed();
    return array_map('intval', (array) $rows);
  }

  /**
   * Returns per-provider, per-model token breakdown within a scope and period.
   *
   * Groups license_service_token_usage rows by (provider_id, model_id), sums total_tokens,
   * and returns a list ordered by provider subtotal descending. Used by usage
   * blocks and the real-time JSON endpoint to render the breakdown table.
   *
   * Per-provider breakdown: counting tokens per AI call source — display
   * feature only, no cost math. Author: Jeremiah Buttler.
   *
   * @param int|null $uid
   *   When non-null, restrict to this user. NULL = site-wide.
   * @param string $period
   *   One of: day, week, month, year, lifetime. Defaults to 'lifetime'.
   *
   * @return list<array{provider: string, total: int, models: list<array{model: string, tokens: int}>}>
   *   Ordered list of provider entries (highest total first), each containing:
   *   - provider: provider plugin id (empty string when unset in the record).
   *   - total: summed tokens across all models for this provider.
   *   - models: per-model rows ordered by tokens descending.
   */
  public function getBreakdown(?int $uid, string $period = 'lifetime'): array {
    $since = $this->periodManager->getStart($period);

    $query = $this->database->select('license_service_token_usage', 'u');
    $query->addField('u', 'provider_id');
    $query->addField('u', 'model_id');
    $query->addExpression('COALESCE(SUM(u.total_tokens), 0)', 'tokens');

    if ($uid !== NULL) {
      $query->condition('u.uid', $uid);
    }
    if ($since > 0) {
      $query->condition('u.created', $since, '>=');
    }

    $query->groupBy('u.provider_id');
    $query->groupBy('u.model_id');

    // Build a nested structure: provider id → {total, models[]}.
    $providers = [];
    foreach ($query->execute() as $row) {
      $provider = $row->provider_id ?: '';
      $model    = $row->model_id ?: '';
      $tokens   = (int) $row->tokens;

      if (!isset($providers[$provider])) {
        $providers[$provider] = ['provider' => $provider, 'total' => 0, 'models' => []];
      }
      $providers[$provider]['total'] += $tokens;
      $providers[$provider]['models'][] = ['model' => $model, 'tokens' => $tokens];
    }

    // Sort providers by total descending.
    uasort($providers, static fn(array $a, array $b): int => $b['total'] <=> $a['total']);

    // Sort models within each provider by tokens descending.
    foreach ($providers as &$entry) {
      usort($entry['models'], static fn(array $a, array $b): int => $b['tokens'] <=> $a['tokens']);
    }
    unset($entry);

    return array_values($providers);
  }

  /**
   * Returns the estimated cost total for a scope and period.
   *
   * Only rows with cost_status = 'computed' are summed; locked/unpriced rows
   * contribute 0 by exclusion. Returns amount 0.0 when no computed rows exist.
   *
   * Cost totals per scope and period — display feature. Author: Jeremiah Buttler.
   *
   * @param int|null $uid
   *   When non-null, restrict to this user. NULL = site-wide.
   * @param string $period
   *   One of: day, week, month, year, lifetime. Defaults to 'lifetime'.
   *
   * @return array{amount: float, currency: string}
   *   Aggregated estimated cost amount and currency code.
   */
  public function getCostSummary(?int $uid, string $period = 'lifetime'): array {
    $since = $this->periodManager->getStart($period);

    $query = $this->database->select('license_service_token_usage', 'u');
    $query->addExpression('COALESCE(SUM(u.estimated_cost), 0)', 'amount');
    $query->addExpression('MAX(u.currency)', 'currency');
    $query->condition('u.cost_status', CostResult::STATUS_COMPUTED);

    if ($uid !== NULL) {
      $query->condition('u.uid', $uid);
    }
    if ($since > 0) {
      $query->condition('u.created', $since, '>=');
    }

    $row = $query->execute()->fetchAssoc() ?: [];

    return [
      'amount'   => (float) ($row['amount'] ?? 0.0),
      'currency' => (string) ($row['currency'] ?? ''),
    ];
  }

  /**
   * Core aggregate: SUM(total_tokens) with optional uid and period filters.
   *
   * @param int|null $uid
   *   When non-null, restrict to this user. NULL = site-wide.
   * @param string $period
   *   Period key.
   *
   * @return int
   *   Aggregate token count.
   */
  private function sum(?int $uid, string $period): int {
    $since = $this->periodManager->getStart($period);

    $query = $this->database->select('license_service_token_usage', 'u');
    $query->addExpression('COALESCE(SUM(u.total_tokens), 0)', 'tokens');

    if ($uid !== NULL) {
      $query->condition('u.uid', $uid);
    }
    if ($since > 0) {
      $query->condition('u.created', $since, '>=');
    }

    return (int) ($query->execute()->fetchField() ?? 0);
  }

}
