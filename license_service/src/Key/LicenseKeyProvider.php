<?php

namespace Drupal\license_service\Key;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;

/**
 * Retrieves and stores the site license key with optional Key module support.
 *
 * Resolution order:
 *   1. Key module (recommended for production).
 *   2. settings.php / environment variable override.
 *   3. Drupal state (development fallback — NOT for production).
 *
 * The key is treated as a secret: never logged, never stored in exported
 * configuration, and masked in any UI output.
 *
 * Author: Jeremiah Buttler
 */
class LicenseKeyProvider {

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly StateInterface $state,
  ) {}

  /**
   * Returns the configured license key, or empty string if not set.
   *
   * Callers must not log or cache this value in non-secure storage.
   */
  public function getKey(): string {
    $config   = $this->configFactory->get('license_service.settings');
    $provider = $config->get('key_provider') ?? 'state';

    if ($provider === 'key_module') {
      return $this->getFromKeyModule($config->get('key_id') ?? '');
    }

    if ($provider === 'settings_php') {
      // The key is set in settings.php as:
      // $settings['license_service_key'] = 'XXXX-XXXX-XXXX-XXXX';.
      $settings = \Drupal::service('settings');
      return (string) ($settings->get('license_service_key') ?? '');
    }

    // State fallback — only suitable for local development.
    return (string) ($this->state->get('license_service.license_key', ''));
  }

  /**
   * Stores the license key in Drupal state (development fallback only).
   *
   * For production, use the Key module or settings.php. This method exists
   * so the settings form can accept a key when no other provider is configured.
   * The key value is NOT logged.
   */
  public function setKey(string $key): void {
    $this->state->set('license_service.license_key', $key);
  }

  /**
   * Removes the stored license key from all state storage (called on uninstall).
   */
  public function clearKey(): void {
    $this->state->delete('license_service.license_key');
  }

  /**
   * Returns TRUE if the Key module service is available.
   */
  public function hasKeyModuleSupport(): bool {
    return \Drupal::hasService('key.repository');
  }

  /**
   * Returns a masked representation of the key for safe display in the UI.
   *
   * Shows only the last 4 characters to confirm the key is set without
   * leaking the full value.
   */
  public function getMaskedKey(): string {
    $key = $this->getKey();
    if ($key === '') {
      return '';
    }
    $visible = substr($key, -4);
    return '••••••••••••' . $visible;
  }

  /**
   * Retrieves the key from the Key module by key entity ID.
   */
  protected function getFromKeyModule(string $keyId): string {
    if ($keyId === '' || !$this->hasKeyModuleSupport()) {
      return '';
    }
    try {
      /** @var \Drupal\key\KeyRepositoryInterface $repo */
      $repo = \Drupal::service('key.repository');
      $key  = $repo->getKey($keyId);
      return $key ? (string) $key->getKeyValue() : '';
    }
    catch (\Throwable) {
      // Key module unavailable or key entity deleted; fail silently.
      return '';
    }
  }

}
