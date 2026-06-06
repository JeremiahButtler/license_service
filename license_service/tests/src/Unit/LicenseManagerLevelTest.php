<?php

namespace Drupal\Tests\license_service\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\license_service\LicenseClient;
use Drupal\license_service\LicenseManagerService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LicenseManagerService level resolution.
 *
 * @group license_service
 * @coversDefaultClass \Drupal\license_service\LicenseManagerService
 *
 * Author: Jeremiah Buttler
 */
class LicenseManagerLevelTest extends TestCase {

  /**
   * Builds a license manager with the given role map and status.
   */
  private function buildManager(array $roleMap, array $statusOverride = []): LicenseManagerService {
    $defaultStatus = [
      'licensed' => TRUE,
      'tier' => 'pro',
      'features' => [],
      'expires_at' => NULL,
      'trial' => FALSE,
      'warnings' => [],
      'refresh_failed' => FALSE,
      'expiring_soon' => FALSE,
      'days_until_expiry' => NULL,
      'offline' => FALSE,
      'state' => 'active',
    ];
    $status = array_merge($defaultStatus, $statusOverride);

    $client = $this->createMock(LicenseClient::class);
    $client->method('getStatus')->willReturn($status);

    $roleConfig = $this->createMock(Config::class);
    $roleConfig->method('get')->with('role_levels')->willReturn($roleMap);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('license_service.role_levels')->willReturn($roleConfig);

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);

    return new LicenseManagerService($client, $configFactory, $cache);
  }

  /**
   * Builds a mock account with the given roles and bypass permission.
   */
  private function mockAccount(array $roles, bool $bypass = FALSE): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('getRoles')->willReturn($roles);
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $perm) => $bypass && $perm === 'bypass license gate',
    );
    return $account;
  }

  /**
   * @covers ::getLevelForAccount
   */
  public function testFallsBackToFreeWithNoMapping(): void {
    $manager = $this->buildManager([]);
    $account = $this->mockAccount(['authenticated']);
    $this->assertSame('free', $manager->getLevelForAccount($account));
  }

  /**
   * @covers ::getLevelForAccount
   */
  public function testResolvesDirectRoleMapping(): void {
    $manager = $this->buildManager(['editor' => 'standard', 'publisher' => 'premium']);
    $account = $this->mockAccount(['authenticated', 'editor']);
    $this->assertSame('standard', $manager->getLevelForAccount($account));
  }

  /**
   * @covers ::getLevelForAccount
   */
  public function testHighestLevelWinsAcrossRoles(): void {
    $manager = $this->buildManager(['editor' => 'standard', 'publisher' => 'premium']);
    $account = $this->mockAccount(['authenticated', 'editor', 'publisher']);
    $this->assertSame('premium', $manager->getLevelForAccount($account));
  }

  /**
   * @covers ::getLevelForAccount
   */
  public function testBypassPermissionGrantsHighestLevel(): void {
    $manager = $this->buildManager(['editor' => 'standard']);
    $account = $this->mockAccount(['authenticated'], bypass: TRUE);
    // Bypass users get the top of the level order.
    $level = $manager->getLevelForAccount($account);
    $this->assertNotSame('', $level);
  }

  /**
   * @covers ::getLevelOrder
   */
  public function testLevelOrderAlwaysStartsWithFree(): void {
    $manager = $this->buildManager(['editor' => 'premium', 'author' => 'standard']);
    $order   = $manager->getLevelOrder();
    $this->assertSame('free', $order[0]);
  }

  /**
   * @covers ::getLevelOrder
   */
  public function testCanonicalLevelOrderIsStable(): void {
    $manager = $this->buildManager(['r1' => 'premium', 'r2' => 'standard', 'r3' => 'pro']);
    $order   = $manager->getLevelOrder();
    $this->assertSame('free', $order[0]);
    $this->assertSame('standard', $order[1]);
    $this->assertSame('pro', $order[2]);
    $this->assertSame('premium', $order[3]);
  }

  /**
   * @covers ::levelAtLeast
   */
  public function testLevelAtLeast(): void {
    $manager = $this->buildManager(['r1' => 'premium', 'r2' => 'standard']);
    $this->assertTrue($manager->levelAtLeast('premium', 'free'));
    $this->assertTrue($manager->levelAtLeast('premium', 'premium'));
    $this->assertFalse($manager->levelAtLeast('free', 'premium'));
    $this->assertTrue($manager->levelAtLeast('standard', 'free'));
  }

}
