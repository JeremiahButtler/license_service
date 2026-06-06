<?php

declare(strict_types=1);

namespace Drupal\Tests\license_service_token_counter\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\license_service\LicenseFeatureProviderInterface;
use Drupal\license_service_token_counter\License\LicenseBridge;
use Psr\Log\NullLogger;

/**
 * Confirms the LicenseBridge never lets a faulty license provider crash callers.
 *
 * The usage report, the status report (hook_requirements), and the cost engine
 * all call LicenseBridge::status() on the request path. A License Module service
 * that throws must be contained inside the bridge and degrade to "unlicensed"
 * rather than propagate a 500 to those pages.
 *
 * LicenseBridge now injects LicenseFeatureProviderInterface directly (hard
 * dependency) instead of probing the container defensively. This reflects the
 * deliberate anti-extraction design: license_service is a declared .info.yml
 * dependency, so the provider is always present on a real install.
 *
 * Author: Jeremiah Buttler.
 *
 * @group license_service_token_counter
 *
 * @covers \Drupal\license_service_token_counter\License\LicenseBridge
 */
final class LicenseBridgeResilienceTest extends UnitTestCase {

  /**
   * Creates a LicenseBridge around a given provider mock.
   */
  private function makeBridge(LicenseFeatureProviderInterface $provider): LicenseBridge {
    return new LicenseBridge($provider, new NullLogger());
  }

  /**
   * When isActive() throws, the bridge degrades to unavailable (not a crash).
   */
  public function testThrowingProviderDegradesToUnavailable(): void {
    $provider = $this->createMock(LicenseFeatureProviderInterface::class);
    $provider->method('isActive')->willThrowException(new \RuntimeException('boom'));

    $bridge = $this->makeBridge($provider);
    $status = $bridge->status();

    $this->assertFalse($status->isActive(), 'A throwing provider must degrade to inactive, not crash.');
    $this->assertFalse($bridge->isActive(), 'The isActive() convenience accessor must agree and not throw.');
  }

  /**
   * When the provider is present but inactive, the bridge reports unlicensed.
   */
  public function testInactiveProviderIsUnavailable(): void {
    $provider = $this->createMock(LicenseFeatureProviderInterface::class);
    $provider->method('isActive')->willReturn(FALSE);

    $bridge = $this->makeBridge($provider);
    $status = $bridge->status();

    $this->assertTrue($status->isProviderPresent(), 'Provider was injected — it is present.');
    $this->assertFalse($status->isActive(), 'Inactive license → bridge must report not active.');
  }

  /**
   * When the provider is active but the feature is not granted, bridge is inactive.
   */
  public function testProviderActiveButFeatureAbsentIsUnavailable(): void {
    $provider = $this->createMock(LicenseFeatureProviderInterface::class);
    $provider->method('isActive')->willReturn(TRUE);
    $provider->method('hasFeature')->willReturn(FALSE);
    $provider->method('getWarnings')->willReturn([]);

    $bridge = $this->makeBridge($provider);

    $this->assertFalse($bridge->isActive(), 'License active but feature not granted → bridge must be inactive.');
  }

  /**
   * When provider is active and the feature is granted, bridge reports active.
   */
  public function testActiveLicenseWithFeatureIsActive(): void {
    $provider = $this->createMock(LicenseFeatureProviderInterface::class);
    $provider->method('isActive')->willReturn(TRUE);
    $provider->method('hasFeature')->willReturn(TRUE);
    $provider->method('getFeature')->willReturn(['some' => 'payload']);
    $provider->method('getWarnings')->willReturn([]);

    $bridge = $this->makeBridge($provider);
    $status = $bridge->status();

    $this->assertTrue($status->isProviderPresent());
    $this->assertTrue($status->isActive());
  }

  /**
   * Bridge memoizes the result — provider methods are only called once.
   */
  public function testStatusIsMemoizedPerRequest(): void {
    $provider = $this->createMock(LicenseFeatureProviderInterface::class);
    $provider->expects($this->once())->method('isActive')->willReturn(TRUE);
    $provider->expects($this->once())->method('hasFeature')->willReturn(TRUE);
    $provider->expects($this->once())->method('getFeature')->willReturn(NULL);
    $provider->expects($this->once())->method('getWarnings')->willReturn([]);

    $bridge = $this->makeBridge($provider);
    // Call status() twice — provider methods should only fire once.
    $bridge->status();
    $bridge->status();
  }

}
