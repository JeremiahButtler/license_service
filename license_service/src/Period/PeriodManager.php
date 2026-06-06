<?php

declare(strict_types=1);

namespace Drupal\license_service\Period;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Canonical period-bucketing service for the License Service ecosystem.
 *
 * Provides two complementary APIs used across License Service and its
 * sub-modules:
 *
 * 1. getCurrentPeriodKey(string $period): string
 *    Returns a UTC-anchored string key identifying the current calendar period.
 *    Used by content-access metering to key the license_service_meter table.
 *    The ISO week-year format ('o-W') is the authoritative bucket — using
 *    'Y-W' instead silently mis-buckets the week spanning 1 Jan / 31 Dec.
 *
 * 2. getStart(string $period, ?int $now = NULL): int
 *    Returns the Unix timestamp of the period start in the site timezone.
 *    Used by the AI Token Counter to window aggregation queries over the
 *    license_service_token_usage table.
 *
 * All License Service sub-modules that need calendar-period math MUST inject
 * and use this service rather than reimplementing the logic themselves. This
 * is a deliberate concrete coupling: the period semantics (especially the
 * ISO week-year 'o-W' subtlety) are hard to reproduce correctly, and a
 * standalone copy that gets them wrong will silently miscount usage near
 * year boundaries.
 *
 * Author: Jeremiah Buttler.
 */
class PeriodManager {

  /**
   * Valid period identifiers.
   *
   * Listed in display order. Aliases (daily, weekly, monthly) are also
   * accepted by both public methods.
   */
  public const PERIODS = ['day', 'week', 'month', 'year', 'lifetime'];

  /**
   * Constructs a PeriodManager.
   *
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The request-time service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory, used to read the site timezone for getStart().
   */
  public function __construct(
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns a string key for the current calendar period (UTC).
   *
   * This is the authoritative implementation for License Service period
   * labels. The 'o-W' ISO week format is critical: 'Y' (calendar year) differs
   * from 'o' (ISO week-numbering year) around 1 Jan / 31 Dec, so using 'Y-W'
   * would split or collide a week's bucket at the year boundary.
   *
   * Supported period labels (with aliases):
   *   daily  | day    → 'Y-m-d'  (UTC date, e.g. '2026-06-06')
   *   weekly | week   → 'o-W'    (ISO week-numbering year + week)
   *   monthly| month  → 'Y-m'    (UTC year-month, e.g. '2026-06')
   *
   * Unrecognised values fall back to monthly.
   *
   * @param string $period
   *   Period label. Accepts daily/day, weekly/week, monthly/month.
   *
   * @return string
   *   The current period key.
   */
  public function getCurrentPeriodKey(string $period): string {
    $now = new \DateTime('now', new \DateTimeZone('UTC'));
    return match ($period) {
      'daily', 'day'     => $now->format('Y-m-d'),
      // Use the ISO week-numbering year ('o'), not the calendar year ('Y'):
      // around 1 Jan / 31 Dec they differ, so 'Y-W' would split or collide
      // a week's bucket at the year boundary.
      'weekly', 'week'   => $now->format('o-W'),
      'monthly', 'month' => $now->format('Y-m'),
      default            => $now->format('Y-m'),
    };
  }

  /**
   * Returns the Unix timestamp of the period start in the site timezone.
   *
   * Used for timestamp-range WHERE clauses over usage tables (e.g.
   * WHERE created >= :since). Supports the same labels as getCurrentPeriodKey()
   * plus 'year' and 'lifetime'.
   *
   * @param string $period
   *   One of: day/daily, week/weekly, month/monthly, year, lifetime.
   *   Unknown values fall back to 0 (no lower bound = full lifetime).
   * @param int|null $now
   *   Reference Unix timestamp. Defaults to the current request time. Pass
   *   an explicit value in tests to produce deterministic results.
   *
   * @return int
   *   Unix timestamp of the period start. Returns 0 for 'lifetime' and any
   *   unrecognised period key (i.e. no lower bound is applied).
   */
  public function getStart(string $period, ?int $now = NULL): int {
    $now = $now ?? $this->time->getRequestTime();
    $tz  = new \DateTimeZone(
      $this->configFactory->get('system.date')->get('timezone.default') ?: 'UTC'
    );

    return match ($period) {
      'day', 'daily'     => $this->midnight($now, $tz),
      'week', 'weekly'   => $this->weekStart($now, $tz),
      'month', 'monthly' => $this->monthStart($now, $tz),
      'year'             => $this->yearStart($now, $tz),
      default            => 0,
    };
  }

  /**
   * Returns a human-readable label for each period key.
   *
   * Used by admin forms and block configuration to populate period dropdowns.
   *
   * @return array<string, string>
   *   Period key => human-readable label (e.g. 'day' => 'Per day').
   */
  public static function labels(): array {
    return [
      'day'      => 'Per day',
      'week'     => 'Per week',
      'month'    => 'Per month',
      'year'     => 'Per year',
      'lifetime' => 'Lifetime',
    ];
  }

  /**
   * Unix timestamp of midnight (start of the calendar day) for $now.
   */
  private function midnight(int $now, \DateTimeZone $tz): int {
    return (int) (new \DateTimeImmutable('@' . $now))
      ->setTimezone($tz)
      ->setTime(0, 0, 0)
      ->getTimestamp();
  }

  /**
   * Unix timestamp of the Monday that started the current ISO week.
   *
   * ISO day-of-week: 1 = Monday, 7 = Sunday. Subtracting (dow - 1) days
   * always lands on Monday.
   */
  private function weekStart(int $now, \DateTimeZone $tz): int {
    $dt  = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0, 0);
    $dow = (int) $dt->format('N');
    return (int) $dt->modify('-' . ($dow - 1) . ' days')->getTimestamp();
  }

  /**
   * Unix timestamp of midnight on the first of the current month.
   */
  private function monthStart(int $now, \DateTimeZone $tz): int {
    $dt = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
    return (int) $dt
      ->setDate((int) $dt->format('Y'), (int) $dt->format('n'), 1)
      ->setTime(0, 0, 0)
      ->getTimestamp();
  }

  /**
   * Unix timestamp of midnight on January 1 of the current year.
   */
  private function yearStart(int $now, \DateTimeZone $tz): int {
    $dt = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
    return (int) $dt
      ->setDate((int) $dt->format('Y'), 1, 1)
      ->setTime(0, 0, 0)
      ->getTimestamp();
  }

}
