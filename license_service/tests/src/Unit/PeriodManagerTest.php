<?php

declare(strict_types=1);

namespace Drupal\Tests\license_service\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use Drupal\license_service\Period\PeriodManager;

/**
 * Unit tests for PeriodManager.
 *
 * Migrated from license_service_token_counter's PeriodCalculatorTest when
 * period logic was centralized into license_service as the canonical
 * period-bucketing service for the entire License Service ecosystem.
 *
 * @coversDefaultClass \Drupal\license_service\Period\PeriodManager
 * @group license_service
 *
 * Reference timestamp used in all getStart() tests (passed as the $now arg):
 *   2026-06-03 03:30:00 UTC = Unix 1780457400
 *   - ISO weekday: 3 (Wednesday)
 *   - Week start (ISO Monday): 2026-06-01 = Unix 1780272000
 *   - Month start (June 1):    2026-06-01 = Unix 1780272000
 *   - Year start (Jan 1):      2026-01-01 = Unix 1767225600
 *   - Day start (midnight):    2026-06-03 = Unix 1780444800
 *
 * Author: Jeremiah Buttler.
 */
final class PeriodManagerTest extends UnitTestCase {

  /**
   * Fixed reference timestamp: 2026-06-03 03:30:00 UTC.
   */
  private const NOW = 1780457400;

  /**
   * The manager under test.
   */
  private PeriodManager $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('timezone.default')
      ->willReturn('UTC');

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('system.date')
      ->willReturn($config);

    $time = $this->createMock(TimeInterface::class);
    // getRequestTime() is only called when $now is not supplied to getStart();
    // all explicit-$now tests below use self::NOW directly.
    $time->method('getRequestTime')->willReturn(self::NOW);

    $this->manager = new PeriodManager($time, $configFactory);
  }

  // ---------------------------------------------------------------------------
  // getStart() — period-windowing timestamps
  // ---------------------------------------------------------------------------

  /**
   * The 'day' key returns midnight of the reference date.
   *
   * 2026-06-03 00:00:00 UTC = 1780444800.
   *
   * @covers ::getStart
   */
  public function testDay(): void {
    $this->assertSame(1780444800, $this->manager->getStart('day', self::NOW));
  }

  /**
   * The 'daily' alias also works.
   *
   * @covers ::getStart
   */
  public function testDailyAlias(): void {
    $this->assertSame(1780444800, $this->manager->getStart('daily', self::NOW));
  }

  /**
   * The 'week' key returns the Monday midnight that started the ISO week.
   *
   * The reference date (Wednesday) falls in the week starting Monday 2026-06-01.
   * 2026-06-01 00:00:00 UTC = 1780272000.
   *
   * @covers ::getStart
   */
  public function testWeek(): void {
    $this->assertSame(1780272000, $this->manager->getStart('week', self::NOW));
  }

  /**
   * The 'weekly' alias also works.
   *
   * @covers ::getStart
   */
  public function testWeeklyAlias(): void {
    $this->assertSame(1780272000, $this->manager->getStart('weekly', self::NOW));
  }

  /**
   * The 'month' key returns midnight on the first of the current calendar month.
   *
   * 2026-06-01 00:00:00 UTC = 1780272000.
   *
   * @covers ::getStart
   */
  public function testMonth(): void {
    $this->assertSame(1780272000, $this->manager->getStart('month', self::NOW));
  }

  /**
   * The 'monthly' alias also works.
   *
   * @covers ::getStart
   */
  public function testMonthlyAlias(): void {
    $this->assertSame(1780272000, $this->manager->getStart('monthly', self::NOW));
  }

  /**
   * The 'year' key returns midnight on January 1 of the current year.
   *
   * 2026-01-01 00:00:00 UTC = 1767225600.
   *
   * @covers ::getStart
   */
  public function testYear(): void {
    $this->assertSame(1767225600, $this->manager->getStart('year', self::NOW));
  }

  /**
   * The 'lifetime' key returns 0 (no lower bound).
   *
   * @covers ::getStart
   */
  public function testLifetime(): void {
    $this->assertSame(0, $this->manager->getStart('lifetime', self::NOW));
  }

  /**
   * Unrecognised period keys also return 0 (treated as no lower bound).
   *
   * @covers ::getStart
   */
  public function testUnknownPeriodReturnsZero(): void {
    $this->assertSame(0, $this->manager->getStart('quarter', self::NOW));
  }

  /**
   * When $now is not supplied, getStart() uses the injected TimeInterface.
   *
   * @covers ::getStart
   */
  public function testDefaultNowUsesTimeInterface(): void {
    // 'lifetime' returns 0 regardless of $now — use it to verify the code
    // path that calls getRequestTime() doesn't error.
    $this->assertSame(0, $this->manager->getStart('lifetime'));
  }

  /**
   * The 'week' key with a Monday $now should return the same midnight (no day subtracted).
   *
   * 2026-06-01 09:00:00 UTC = 1780304400 (a Monday).
   * Expected week start: 2026-06-01 00:00:00 UTC = 1780272000.
   *
   * @covers ::getStart
   */
  public function testWeekOnMonday(): void {
    // Monday June 1 midnight + 9 hours.
    $monday = 1780272000 + 32400;
    $this->assertSame(1780272000, $this->manager->getStart('week', $monday));
  }

  /**
   * The 'week' key with a Sunday $now returns the preceding Monday.
   *
   * 2026-06-07 12:00:00 UTC (Sunday).
   * Expected week start: 2026-06-01 00:00:00 UTC = 1780272000.
   *
   * @covers ::getStart
   */
  public function testWeekOnSunday(): void {
    // Monday June 1 + 6 days + 12 hours.
    $sunday = 1780272000 + (6 * 86400) + 43200;
    $this->assertSame(1780272000, $this->manager->getStart('week', $sunday));
  }

  // ---------------------------------------------------------------------------
  // getCurrentPeriodKey() — string period bucket labels (UTC)
  // ---------------------------------------------------------------------------

  /**
   * The 'daily' period returns a YYYY-MM-DD formatted key.
   *
   * @covers ::getCurrentPeriodKey
   */
  public function testGetCurrentPeriodKeyDaily(): void {
    $key = $this->manager->getCurrentPeriodKey('daily');
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $key);
  }

  /**
   * The 'day' alias also returns a YYYY-MM-DD formatted key.
   *
   * @covers ::getCurrentPeriodKey
   */
  public function testGetCurrentPeriodKeyDayAlias(): void {
    $this->assertSame(
      $this->manager->getCurrentPeriodKey('daily'),
      $this->manager->getCurrentPeriodKey('day')
    );
  }

  /**
   * The 'weekly' period returns a key matching the ISO week-numbering year format.
   *
   * Format: YYYY-W (e.g. '2026-23') — uses 'o' (ISO year) not 'Y'.
   *
   * @covers ::getCurrentPeriodKey
   */
  public function testGetCurrentPeriodKeyWeekly(): void {
    $key = $this->manager->getCurrentPeriodKey('weekly');
    $this->assertMatchesRegularExpression('/^\d{4}-\d+$/', $key);
  }

  /**
   * The 'monthly' period returns a YYYY-MM formatted key.
   *
   * @covers ::getCurrentPeriodKey
   */
  public function testGetCurrentPeriodKeyMonthly(): void {
    $key = $this->manager->getCurrentPeriodKey('monthly');
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $key);
  }

  /**
   * Unknown period falls back to monthly format.
   *
   * @covers ::getCurrentPeriodKey
   */
  public function testGetCurrentPeriodKeyUnknownFallsBackToMonthly(): void {
    $monthly = $this->manager->getCurrentPeriodKey('monthly');
    $unknown = $this->manager->getCurrentPeriodKey('quarterly');
    $this->assertSame($monthly, $unknown);
  }

  // ---------------------------------------------------------------------------
  // labels() — static method
  // ---------------------------------------------------------------------------

  /**
   * Labels() returns all expected period keys.
   *
   * @covers ::labels
   */
  public function testLabelsContainsAllPeriods(): void {
    $labels = PeriodManager::labels();
    foreach (PeriodManager::PERIODS as $period) {
      $this->assertArrayHasKey($period, $labels, "labels() is missing key '$period'");
    }
  }

}
