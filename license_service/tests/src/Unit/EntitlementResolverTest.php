<?php

namespace Drupal\Tests\license_service\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\license_service\Entitlements\EntitlementResolver;
use Drupal\license_service\LicenseManagerService;
use Drupal\license_service\Period\PeriodManager;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EntitlementResolver.
 *
 * @group license_service
 * @coversDefaultClass \Drupal\license_service\Entitlements\EntitlementResolver
 *
 * Author: Jeremiah Buttler
 */
class EntitlementResolverTest extends TestCase {

  /**
   * The entitlement resolver under test.
   *
   * @var \Drupal\license_service\Entitlements\EntitlementResolver
   */
  protected EntitlementResolver $resolver;

  /**
   * Builds a resolver with a given set of content rules.
   */
  private function buildResolver(array $rules, array $envelope = []): EntitlementResolver {
    $defaultEnvelope = [
      'allowed_levels'    => ['free', 'premium'],
      'max_premium_users' => 0,
      'field_gating'      => TRUE,
      'download_gating'   => TRUE,
      'metered_views'     => TRUE,
      'quotas'            => TRUE,
    ];
    $envelope = array_merge($defaultEnvelope, $envelope);

    $manager = $this->createMock(LicenseManagerService::class);
    $manager->method('getEnvelope')->willReturn($envelope);

    $config = $this->createMock(Config::class);
    $config->method('get')->with('rules')->willReturn($rules);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('license_service.content_rules')->willReturn($config);

    // PeriodManager needs its own config factory for 'system.date'.
    $systemDateConfig = $this->createMock(ImmutableConfig::class);
    $systemDateConfig->method('get')->with('timezone.default')->willReturn('UTC');
    $periodConfigFactory = $this->createMock(ConfigFactoryInterface::class);
    $periodConfigFactory->method('get')->with('system.date')->willReturn($systemDateConfig);
    $time = $this->createMock(TimeInterface::class);
    $periodManager = new PeriodManager($time, $periodConfigFactory);

    return new EntitlementResolver($manager, $configFactory, $periodManager);
  }

  /**
   * @covers ::getEntitlementsForLevel
   */
  public function testExactRuleMatch(): void {
    $resolver = $this->buildResolver([
      [
        'level' => 'premium',
        'content_type' => 'article',
        'can_view' => TRUE,
        'can_create' => TRUE,
        'can_edit' => FALSE,
        'can_delete' => FALSE,
        'create_quota' => 10,
        'edit_quota' => 0,
        'metered_views' => 5,
        'metered_period' => 'monthly',
        'gated_fields' => ['field_body'],
        'gate_file_downloads' => FALSE,
      ],
    ]);

    $e = $resolver->getEntitlementsForLevel('premium', 'article');
    $this->assertTrue($e['can_view']);
    $this->assertTrue($e['can_create']);
    $this->assertFalse($e['can_edit']);
    $this->assertSame(10, $e['create_quota']);
    $this->assertSame(5, $e['metered_views']);
    $this->assertSame('monthly', $e['metered_period']);
    $this->assertSame(['field_body'], $e['gated_fields']);
  }

  /**
   * @covers ::getEntitlementsForLevel
   */
  public function testWildcardContentTypeFallback(): void {
    $resolver = $this->buildResolver([
      [
        'level' => 'free',
        'content_type' => '*',
        'can_view' => TRUE,
        'can_create' => FALSE,
        'can_edit' => FALSE,
        'can_delete' => FALSE,
        'create_quota' => 0,
        'edit_quota' => 0,
        'metered_views' => 0,
        'metered_period' => 'monthly',
        'gated_fields' => [],
        'gate_file_downloads' => FALSE,
      ],
    ]);

    $e = $resolver->getEntitlementsForLevel('free', 'page');
    $this->assertTrue($e['can_view']);
    $this->assertFalse($e['can_create']);
  }

  /**
   * @covers ::getEntitlementsForLevel
   */
  public function testNoRuleFallsToDenyAll(): void {
    $resolver = $this->buildResolver([]);
    $e = $resolver->getEntitlementsForLevel('premium', 'article');
    $this->assertFalse($e['can_view']);
    $this->assertFalse($e['can_create']);
    $this->assertFalse($e['can_edit']);
    $this->assertFalse($e['can_delete']);
  }

  /**
   * @covers ::getEntitlementsForLevel
   */
  public function testExactMatchTakesPriorityOverWildcard(): void {
    $resolver = $this->buildResolver([
      [
        'level' => 'free',
        'content_type' => '*',
        'can_view' => FALSE,
        'can_create' => FALSE,
        'can_edit' => FALSE,
        'can_delete' => FALSE,
        'create_quota' => 0,
        'edit_quota' => 0,
        'metered_views' => 0,
        'metered_period' => 'monthly',
        'gated_fields' => [],
        'gate_file_downloads' => FALSE,
      ],
      [
        'level' => 'free',
        'content_type' => 'article',
        'can_view' => TRUE,
        'can_create' => FALSE,
        'can_edit' => FALSE,
        'can_delete' => FALSE,
        'create_quota' => 0,
        'edit_quota' => 0,
        'metered_views' => 0,
        'metered_period' => 'monthly',
        'gated_fields' => [],
        'gate_file_downloads' => FALSE,
      ],
    ]);

    $this->assertTrue($resolver->canView('free', 'article'));
    // Hits wildcard → FALSE.
    $this->assertFalse($resolver->canView('free', 'page'));
  }

  /**
   * @covers ::canDelete
   */
  public function testCanDelete(): void {
    $resolver = $this->buildResolver([
      [
        'level' => 'premium',
        'content_type' => 'article',
        'can_view' => TRUE,
        'can_create' => TRUE,
        'can_edit' => TRUE,
        'can_delete' => TRUE,
        'create_quota' => 0,
        'edit_quota' => 0,
        'metered_views' => 0,
        'metered_period' => 'monthly',
        'gated_fields' => [],
        'gate_file_downloads' => FALSE,
      ],
    ]);
    $this->assertTrue($resolver->canDelete('premium', 'article'));
    // No rule → deny-all.
    $this->assertFalse($resolver->canDelete('free', 'article'));
  }

  /**
   * @covers ::getEntitlementsForLevel
   */
  public function testEnvelopeDisablesQuotas(): void {
    $resolver = $this->buildResolver(
      [[
        'level' => 'free',
        'content_type' => 'article',
        'can_view' => TRUE,
        'can_create' => TRUE,
        'can_edit' => TRUE,
        'can_delete' => FALSE,
        'create_quota' => 99,
        'edit_quota' => 50,
        'metered_views' => 10,
        'metered_period' => 'monthly',
        'gated_fields' => ['field_body'],
        'gate_file_downloads' => TRUE,
      ],
      ],
      ['quotas' => FALSE, 'metered_views' => FALSE, 'field_gating' => FALSE, 'download_gating' => FALSE],
    );

    $e = $resolver->getEntitlementsForLevel('free', 'article');
    $this->assertSame(0, $e['create_quota']);
    $this->assertSame(0, $e['edit_quota']);
    $this->assertSame(0, $e['metered_views']);
    $this->assertSame([], $e['gated_fields']);
    $this->assertFalse($e['gate_file_downloads']);
  }

  /**
   * GetCurrentPeriodKey() delegates to PeriodManager — verify format.
   *
   * @covers ::getCurrentPeriodKey
   */
  public function testCurrentPeriodKeyFormats(): void {
    $resolver = $this->buildResolver([]);
    $daily    = $resolver->getCurrentPeriodKey('daily');
    $weekly   = $resolver->getCurrentPeriodKey('weekly');
    $monthly  = $resolver->getCurrentPeriodKey('monthly');
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $daily);
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $monthly);
    // Weekly format: YYYY-WW.
    $this->assertMatchesRegularExpression('/^\d{4}-\d+$/', $weekly);
  }

}
