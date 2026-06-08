<?php

declare(strict_types=1);

namespace Drupal\Tests\license_service_subscriptions\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\license_service\LicenseManagerService;
use Drupal\license_service\SeatCapService;
use Drupal\license_service_subscriptions\Service\SubscriptionRoleGrantService;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for SubscriptionRoleGrantService::getRolesForTier().
 *
 * getRolesForTier() is pure config-reading logic with no DB access and no
 * Drupal statics, so these tests run with simple mock objects and no container.
 *
 * @coversDefaultClass \Drupal\license_service_subscriptions\Service\SubscriptionRoleGrantService
 * @group license_service_subscriptions
 *
 * Author: Jeremiah Buttler.
 */
class SubscriptionRoleGrantServiceTest extends UnitTestCase {

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Builds a SubscriptionRoleGrantService wired to a given role_levels map.
   *
   * @param array $roleLevels
   *   Associative array keyed by Drupal role ID, valued by tier machine name.
   *   Mirrors the structure of license_service.role_levels config.
   */
  private function buildService(array $roleLevels): SubscriptionRoleGrantService {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('role_levels')
      ->willReturn($roleLevels);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('license_service.role_levels')
      ->willReturn($config);

    return new SubscriptionRoleGrantService(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LicenseManagerService::class),
      $this->createMock(SeatCapService::class),
      $this->createMock(LoggerChannelFactoryInterface::class),
      $configFactory,
      $this->createMock(Connection::class),
    );
  }

  // ---------------------------------------------------------------------------
  // getRolesForTier() tests
  // ---------------------------------------------------------------------------

  /**
   * @covers ::getRolesForTier
   */
  public function testReturnsEmptyArrayWhenConfigIsEmpty(): void {
    $service = $this->buildService([]);
    $this->assertSame([], $service->getRolesForTier('standard'));
  }

  /**
   * @covers ::getRolesForTier
   */
  public function testReturnsMatchingRoleForExactTierMatch(): void {
    $roleLevels = [
      'subscriber'    => 'standard',
      'premium_user'  => 'premium',
    ];
    $service = $this->buildService($roleLevels);
    $this->assertSame(['subscriber'], $service->getRolesForTier('standard'));
  }

  /**
   * @covers ::getRolesForTier
   */
  public function testReturnsEmptyArrayWhenNoRoleMatchesTier(): void {
    $roleLevels = [
      'subscriber'   => 'standard',
      'premium_user' => 'premium',
    ];
    $service = $this->buildService($roleLevels);
    $this->assertSame([], $service->getRolesForTier('enterprise'));
  }

  /**
   * @covers ::getRolesForTier
   */
  public function testReturnsMultipleRolesWhenSeveralMatchSameTier(): void {
    $roleLevels = [
      'subscriber'      => 'standard',
      'content_creator' => 'standard',
      'premium_user'    => 'premium',
    ];
    $service = $this->buildService($roleLevels);
    $result = $service->getRolesForTier('standard');
    sort($result);
    $this->assertSame(['content_creator', 'subscriber'], $result);
  }

  /**
   * @covers ::getRolesForTier
   */
  public function testTierMatchIsExact_doesNotMatchSubstring(): void {
    // 'standard_plus' must NOT match when querying for 'standard'.
    $roleLevels = [
      'subscriber'      => 'standard',
      'premium_user'    => 'standard_plus',
    ];
    $service = $this->buildService($roleLevels);
    $result = $service->getRolesForTier('standard');
    $this->assertSame(['subscriber'], $result);
    $this->assertNotContains('premium_user', $result);
  }

  /**
   * @covers ::getRolesForTier
   */
  public function testNonArrayConfigReturnsEmptyArray(): void {
    // If role_levels config is not an array (misconfigured), return empty.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('role_levels')->willReturn(NULL);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $service = new SubscriptionRoleGrantService(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LicenseManagerService::class),
      $this->createMock(SeatCapService::class),
      $this->createMock(LoggerChannelFactoryInterface::class),
      $configFactory,
      $this->createMock(Connection::class),
    );

    $this->assertSame([], $service->getRolesForTier('standard'));
  }

}
