<?php

namespace Drupal\Tests\license_service\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Session\AccountInterface;
use Drupal\license_service\LicenseClient;
use Drupal\license_service\LicenseFeatureProvider;
use Drupal\license_service\LicenseManagerService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the cross-module entitlement facade.
 *
 * Verifies that LicenseFeatureProvider maps the consumer license contract
 * (isActive / hasFeature / getFeature / getWarnings) onto LicenseManagerService.
 *
 * @group license_service
 * @coversDefaultClass \Drupal\license_service\LicenseFeatureProvider
 *
 * Author: Jeremiah Buttler
 */
class LicenseFeatureProviderTest extends TestCase {

  /**
   * Builds a feature provider with an overridable license status.
   */
  private function buildProvider(array $statusOverride = []): LicenseFeatureProvider {
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

    $configFactory = $this->createMock(ConfigFactoryInterface::class);

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);

    $manager = new LicenseManagerService($client, $configFactory, $cache);
    return new LicenseFeatureProvider($manager);
  }

  /**
   * @covers ::isActive
   */
  public function testIsActiveWhenLicensed(): void {
    $this->assertTrue($this->buildProvider(['licensed' => TRUE])->isActive());
  }

  /**
   * @covers ::isActive
   */
  public function testIsNotActiveWhenUnlicensed(): void {
    $this->assertFalse($this->buildProvider(['licensed' => FALSE])->isActive());
  }

  /**
   * @covers ::hasFeature
   */
  public function testHasFeatureTrueWhenPresent(): void {
    $provider = $this->buildProvider([
      'features' => ['ai_token_counter' => ['currency' => 'USD', 'pricing' => []]],
    ]);
    $this->assertTrue($provider->hasFeature('ai_token_counter'));
  }

  /**
   * @covers ::hasFeature
   */
  public function testHasFeatureFalseWhenAbsent(): void {
    $provider = $this->buildProvider(['features' => []]);
    $this->assertFalse($provider->hasFeature('ai_token_counter'));
  }

  /**
   * @covers ::getFeature
   */
  public function testGetFeatureReturnsPayload(): void {
    $payload = ['currency' => 'USD', 'pricing' => ['openai' => ['*' => ['input' => 2.5, 'output' => 10]]]];
    $provider = $this->buildProvider(['features' => ['ai_token_counter' => $payload]]);
    $this->assertSame($payload, $provider->getFeature('ai_token_counter'));
  }

  /**
   * @covers ::getFeature
   */
  public function testGetFeatureReturnsNullWhenAbsent(): void {
    $this->assertNull($this->buildProvider(['features' => []])->getFeature('ai_token_counter'));
  }

  /**
   * @covers ::getWarnings
   */
  public function testGetWarningsPassthrough(): void {
    $warnings = ['License expires in 3 days.', 'Last refresh failed.'];
    $this->assertSame($warnings, $this->buildProvider(['warnings' => $warnings])->getWarnings());
  }

  /**
   * @covers ::getWarnings
   */
  public function testGetWarningsEmptyByDefault(): void {
    $this->assertSame([], $this->buildProvider(['warnings' => []])->getWarnings());
  }

  /**
   * Builds a provider with a configured role→level map.
   *
   * Sets up the ConfigFactory mock so getLevelOrder() and getLevelForAccount()
   * can read from 'license_service.role_levels'. Cache always misses so the
   * client mock is always consulted.
   *
   * @param array $roleMap
   *   Role machine name => level name map (e.g. ['editor' => 'standard']).
   * @param array $statusOverride
   *   Overrides for the default license status array.
   */
  private function buildProviderWithRoles(array $roleMap, array $statusOverride = []): LicenseFeatureProvider {
    $defaultStatus = [
      'licensed'          => TRUE,
      'tier'              => 'premium',
      'features'          => [],
      'expires_at'        => NULL,
      'trial'             => FALSE,
      'warnings'          => [],
      'refresh_failed'    => FALSE,
      'expiring_soon'     => FALSE,
      'days_until_expiry' => NULL,
      'offline'           => FALSE,
      'state'             => 'active',
    ];
    $status = array_merge($defaultStatus, $statusOverride);

    $client = $this->createMock(LicenseClient::class);
    $client->method('getStatus')->willReturn($status);

    $roleConfig = $this->createMock(ImmutableConfig::class);
    $roleConfig->method('get')->with('role_levels')->willReturn($roleMap);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->willReturnMap([['license_service.role_levels', $roleConfig]]);

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);

    $manager = new LicenseManagerService($client, $configFactory, $cache);
    return new LicenseFeatureProvider($manager);
  }

  /**
   * Returns the highest level when bypass permission is held.
   *
   * @covers ::getLevelForAccount
   */
  public function testGetLevelForAccountBypassReturnsHighest(): void {
    $provider = $this->buildProviderWithRoles(['editor' => 'standard', 'admin' => 'premium']);
    $account  = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->with('bypass license gate')->willReturn(TRUE);
    $this->assertSame('premium', $provider->getLevelForAccount($account));
  }

  /**
   * Resolves the level from the role→level config map.
   *
   * @covers ::getLevelForAccount
   */
  public function testGetLevelForAccountByRoleMapping(): void {
    $provider = $this->buildProviderWithRoles(['editor' => 'standard']);
    $account  = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);
    $account->method('getRoles')->willReturn(['editor']);
    $this->assertSame('standard', $provider->getLevelForAccount($account));
  }

  /**
   * Returns levels sorted from lowest to highest privilege.
   *
   * @covers ::getLevelOrder
   */
  public function testGetLevelOrderReturnsSortedLevels(): void {
    $provider = $this->buildProviderWithRoles(['editor' => 'standard', 'vip' => 'premium']);
    $this->assertSame(['free', 'standard', 'premium'], $provider->getLevelOrder());
  }

  /**
   * Verifies comparisons for higher and lower levels.
   *
   * @covers ::levelAtLeast
   */
  public function testLevelAtLeastComparisons(): void {
    $provider = $this->buildProviderWithRoles(['editor' => 'standard', 'vip' => 'premium']);
    $this->assertTrue($provider->levelAtLeast('premium', 'standard'));
    $this->assertFalse($provider->levelAtLeast('free', 'standard'));
  }

  /**
   * Exposes the 'quotas' boolean from the license features via getEnvelope().
   *
   * @covers ::getEnvelope
   */
  public function testGetEnvelopeExposesQuotas(): void {
    $provider = $this->buildProviderWithRoles(['editor' => 'standard']);
    $envelope = $provider->getEnvelope();
    $this->assertArrayHasKey('quotas', $envelope);
    // Default: quotas flag is TRUE for a licensed site with no override.
    $this->assertTrue($envelope['quotas']);
  }

}
