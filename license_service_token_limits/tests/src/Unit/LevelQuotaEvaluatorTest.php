<?php

declare(strict_types=1);

namespace Drupal\Tests\license_service_token_limits\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Session\AccountInterface;
use Drupal\license_service\LicenseFeatureProviderInterface;
use Drupal\license_service_token_counter\Service\UsageAggregatorInterface;
use Drupal\license_service_token_limits\Service\LevelQuotaEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LevelQuotaEvaluator.
 *
 * Verifies all early-exit paths and the over-quota return value.
 *
 * @group license_service_token_limits
 * @coversDefaultClass \Drupal\license_service_token_limits\Service\LevelQuotaEvaluator
 *
 * Author: Jeremiah Buttler
 */
class LevelQuotaEvaluatorTest extends TestCase {

  /**
   * Builds an evaluator with controllable config, license, and usage stubs.
   *
   * @param bool $enabled
   *   Whether the module's 'enabled' flag is set in config.
   * @param array $levelQuotas
   *   The 'quotas' value to return from config, keyed by level name.
   * @param string $accountLevel
   *   The level returned by getLevelForAccount() for non-anonymous accounts.
   * @param int $tokensUsed
   *   The value returned by UsageAggregator::getUserTokens().
   *
   * @return \Drupal\license_service_token_limits\Service\LevelQuotaEvaluator
   *   A configured evaluator instance.
   */
  private function buildEvaluator(
    bool $enabled = TRUE,
    array $levelQuotas = [],
    string $accountLevel = 'standard',
    int $tokensUsed = 0,
  ): LevelQuotaEvaluator {
    $provider = $this->createMock(LicenseFeatureProviderInterface::class);
    $provider->method('getLevelForAccount')->willReturn($accountLevel);

    $aggregator = $this->createMock(UsageAggregatorInterface::class);
    $aggregator->method('getUserTokens')->willReturn($tokensUsed);

    $settingsConfig = $this->createMock(ImmutableConfig::class);
    $settingsConfig->method('get')->willReturnMap([
      ['enabled', $enabled],
      ['quotas', $levelQuotas],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('license_service_token_limits.settings')
      ->willReturn($settingsConfig);

    return new LevelQuotaEvaluator($provider, $aggregator, $configFactory);
  }

  /**
   * Builds a non-anonymous account mock with controllable permissions.
   *
   * @param int $uid
   *   The user id.
   * @param string[] $grantedPermissions
   *   Permissions that hasPermission() should return TRUE for.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The account stub.
   */
  private function buildAccount(int $uid = 1, array $grantedPermissions = []): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAnonymous')->willReturn(FALSE);
    $account->method('id')->willReturn($uid);
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $perm): bool => in_array($perm, $grantedPermissions, TRUE)
    );
    return $account;
  }

  /**
   * Returns NULL when token usage is strictly below the configured quota.
   *
   * @covers ::getExceededInfo
   */
  public function testUnderQuotaIsNull(): void {
    $evaluator = $this->buildEvaluator(
      levelQuotas: ['standard' => ['amount' => 1000, 'period' => 'month']],
      tokensUsed: 999,
    );
    $this->assertNull($evaluator->getExceededInfo($this->buildAccount()));
  }

  /**
   * Returns the info array when usage meets or exceeds the configured quota.
   *
   * @covers ::getExceededInfo
   */
  public function testOverQuotaReturnsInfo(): void {
    $evaluator = $this->buildEvaluator(
      levelQuotas: ['standard' => ['amount' => 1000, 'period' => 'month']],
      tokensUsed: 1000,
    );
    $info = $evaluator->getExceededInfo($this->buildAccount());
    $this->assertIsArray($info);
    $this->assertSame('standard', $info['level']);
    $this->assertSame(1000, $info['amount']);
    $this->assertSame(1000, $info['used']);
    $this->assertSame('month', $info['period']);
  }

  /**
   * Returns NULL when the configured amount is 0 (unlimited).
   *
   * @covers ::getExceededInfo
   */
  public function testUnlimitedAmountIsNull(): void {
    $evaluator = $this->buildEvaluator(
      levelQuotas: ['standard' => ['amount' => 0, 'period' => 'month']],
      tokensUsed: 99999,
    );
    $this->assertNull($evaluator->getExceededInfo($this->buildAccount()));
  }

  /**
   * Returns NULL when the module's enabled flag is FALSE.
   *
   * @covers ::getExceededInfo
   */
  public function testDisabledIsNull(): void {
    $evaluator = $this->buildEvaluator(
      enabled: FALSE,
      levelQuotas: ['standard' => ['amount' => 100, 'period' => 'month']],
      tokensUsed: 9999,
    );
    $this->assertNull($evaluator->getExceededInfo($this->buildAccount()));
  }

  /**
   * Returns NULL for anonymous accounts regardless of quota configuration.
   *
   * @covers ::getExceededInfo
   */
  public function testAnonymousIsNull(): void {
    $evaluator = $this->buildEvaluator(
      levelQuotas: ['free' => ['amount' => 10, 'period' => 'day']],
      tokensUsed: 999,
    );
    $anon = $this->createMock(AccountInterface::class);
    $anon->method('isAnonymous')->willReturn(TRUE);
    $this->assertNull($evaluator->getExceededInfo($anon));
  }

  /**
   * Returns NULL when the account holds 'bypass ai token usage limits'.
   *
   * @covers ::getExceededInfo
   */
  public function testBypassTokenCounterIsNull(): void {
    $evaluator = $this->buildEvaluator(
      levelQuotas: ['standard' => ['amount' => 100, 'period' => 'month']],
      tokensUsed: 9999,
    );
    $account = $this->buildAccount(grantedPermissions: ['bypass ai token usage limits']);
    $this->assertNull($evaluator->getExceededInfo($account));
  }

  /**
   * Returns NULL when the account holds 'bypass license gate'.
   *
   * @covers ::getExceededInfo
   */
  public function testBypassLicenseGateIsNull(): void {
    $evaluator = $this->buildEvaluator(
      levelQuotas: ['standard' => ['amount' => 100, 'period' => 'month']],
      tokensUsed: 9999,
    );
    $account = $this->buildAccount(grantedPermissions: ['bypass license gate']);
    $this->assertNull($evaluator->getExceededInfo($account));
  }

}
