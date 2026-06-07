<?php

namespace Drupal\Tests\license_service\Unit;

use Drupal\license_service\LicenseClient;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LicenseClient offline grace-hours resolution.
 *
 * Verifies that the grace window is read from the signed token payload
 * (grace_hours stamped by the LVS per level) rather than a hardcoded fallback,
 * and that the default of 24h applies when the field is absent (older tokens).
 *
 * @group license_service
 * @coversDefaultClass \Drupal\license_service\LicenseClient
 *
 * Author: Jeremiah Buttler
 */
class LicenseClientGraceHoursTest extends TestCase {

  /**
   * A thin subclass that exposes computeGraceHours() for direct unit testing.
   *
   * Skips real constructor dependencies; only computeGraceHours() is under test.
   */
  private function makeClient(): object {
    return new class extends LicenseClient {

      /**
       * Skips all real constructor dependencies.
       */
      public function __construct() {
        // Intentionally left empty — only computeGraceHours() is under test.
      }

      /**
       * Exposes the protected computeGraceHours() method for white-box testing.
       */
      public function computeGraceHoursPublic(array $payload): int {
        return $this->computeGraceHours($payload);
      }

    };
  }

  /**
   * Token grace_hours field is used when present.
   *
   * @covers ::computeGraceHours
   */
  public function testUsesTokenGraceHours(): void {
    $client = $this->makeClient();
    // Token carries grace_hours: 48 — must return 48, not the 24h default.
    $this->assertSame(48, $client->computeGraceHoursPublic(['grace_hours' => 48]));
  }

  /**
   * Falls back to 24 hours when grace_hours is absent (older tokens).
   *
   * @covers ::computeGraceHours
   */
  public function testDefaultsTo24WhenFieldAbsent(): void {
    $client = $this->makeClient();
    $this->assertSame(24, $client->computeGraceHoursPublic([]));
  }

  /**
   * Zero or negative token values are clamped to a minimum of 1 hour.
   *
   * @covers ::computeGraceHours
   */
  public function testMinimumIsOneHour(): void {
    $client = $this->makeClient();
    $this->assertSame(1, $client->computeGraceHoursPublic(['grace_hours' => 0]));
    $this->assertSame(1, $client->computeGraceHoursPublic(['grace_hours' => -5]));
  }

}
