<?php

declare(strict_types=1);

namespace Drupal\Tests\license_service_token_counter\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\license_service_token_counter\License\LicenseBridge;
use Psr\Log\NullLogger;

/**
 * Live cross-module check: LicenseBridge resolves the renamed license_service.
 *
 * Boots a real Drupal kernel with the License Module (machine name
 * `license_service`, renamed from `license_gate`) enabled and confirms that AI
 * Token Counter's LicenseBridge discovers the module's cross-module provider
 * service through the live dependency-injection container.
 *
 * This guards the `license_gate` -> `license_service` rename end to end: the
 * bridge probes a list of service ids, so a missed rename would silently leave
 * the module unlicensed with no error. Resolving the renamed id here on a real
 * container is the cross-module live verification.
 *
 * Author: Jeremiah Buttler.
 *
 * @group license_service_token_counter
 */
final class LicenseBridgeResolutionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The License Module (license_service) plus its declared dependencies. The AI
   * contrib module is intentionally not enabled: this test exercises the bridge
   * resolution path only, which depends solely on the License Module being
   * present in the container.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'license_service',
  ];

  /**
   * The renamed provider service is present and the bridge resolves it.
   */
  public function testBridgeResolvesRenamedLicenseService(): void {
    // The License Module registers its cross-module API under this id, which is
    // the first id LicenseBridge probes (CANDIDATE_SERVICE_IDS[0]).
    $this->assertTrue(
      $this->container->has('license_service.manager'),
      'The renamed module must register the license_service.manager service.',
    );

    // Instantiate the production bridge against the live kernel container — the
    // same wiring as the license_service_token_counter.license_bridge service definition
    // (arguments: @service_container, logger).
    $bridge = new LicenseBridge($this->container, new NullLogger());
    $status = $bridge->status();

    // The bridge located a compatible provider through the container: proof the
    // renamed service id is discovered.
    $this->assertTrue(
      $status->isProviderPresent(),
      'LicenseBridge should locate the renamed license_service provider.',
    );

    // No license token is configured in this kernel, so the resolved provider
    // correctly reports inactive. Reaching a real "inactive" answer (rather than
    // "provider absent") confirms the bridge actually reached the provider.
    $this->assertFalse(
      $status->isActive(),
      'With no license token, the resolved provider reports inactive.',
    );
    $this->assertFalse(
      $bridge->isActive(),
      'The isActive() convenience accessor agrees with the status snapshot.',
    );
  }

  /**
   * The legacy license_gate service ids are gone after the rename.
   */
  public function testLegacyLicenseGateServiceAbsent(): void {
    $this->assertFalse(
      $this->container->has('license_gate.manager'),
      'The legacy license_gate.manager service must not exist after the rename.',
    );
  }

}
