<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter_engine;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Drupal\Core\DependencyInjection\ServiceProviderInterface;

/**
 * Installs the licensed cost calculator in place of the shell's default.
 *
 * The shell module binds "license_service_token_counter.cost" to a NullCostCalculator that
 * always reports "locked". When this engine module is enabled, this provider
 * re-points that service id at the licensed EngineCostCalculator. The real cost
 * logic therefore only exists when the engine is installed.
 *
 * EngineCostCalculator performs a runtime license check (via LicenseBridge) on
 * every calculate() call and returns CostResult::locked() when no active license
 * is present. Cost estimation is a licensed feature — see EngineCostCalculator.
 *
 * Author: Jeremiah Buttler.
 */
final class LicenseServiceTokenCounterEngineServiceProvider implements ServiceProviderInterface, ServiceModifierInterface {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    // Services are declared in license_service_token_counter_engine.services.yml.
  }

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if (!$container->hasDefinition('license_service_token_counter_engine.calculator')) {
      return;
    }

    // Replace the shell's NullCostCalculator with the licensed calculator.
    if ($container->hasDefinition('license_service_token_counter.cost') || $container->hasAlias('license_service_token_counter.cost')) {
      $container->removeDefinition('license_service_token_counter.cost');
      $container->removeAlias('license_service_token_counter.cost');
    }
    $container->setAlias('license_service_token_counter.cost', 'license_service_token_counter_engine.calculator');
  }

}
