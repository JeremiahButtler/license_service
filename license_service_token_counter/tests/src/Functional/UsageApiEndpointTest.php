<?php

declare(strict_types=1);

namespace Drupal\Tests\license_service_token_counter\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies the real-time JSON usage endpoint resolves and enforces access.
 *
 * Regression guard for the controller-argument-resolution bug: usage() once
 * declared a `$account` parameter, which Drupal cannot resolve for a regular
 * _controller (only access callbacks get the account injected), so every
 * request threw a RuntimeException -> HTTP 500. This asserts a real 200 with
 * the expected JSON shape per scope, plus the 403 access paths.
 *
 * Route: GET /api/license-service-token-counter/usage
 *
 * Author: Jeremiah Buttler.
 *
 * @group license_service_token_counter
 */
final class UsageApiEndpointTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['license_service_token_counter'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The JSON endpoint path.
   */
  private const API_PATH = '/api/license-service-token-counter/usage';

  /**
   * {@inheritdoc}
   *
   * Disable site-directory permissions hardening for the child test site so
   * that teardown can delete it on Windows. Without this, Drupal chmods the
   * test site directory to read-only after install, and the recursive-delete
   * chmod in BrowserTestBase::cleanupEnvironment() can fail with
   * "chmod(): Permission denied" when the host OS transiently locks a file.
   */
  protected function prepareSettings() {
    parent::prepareSettings();
    $settings['settings']['skip_permissions_hardening'] = (object) [
      'value' => TRUE,
      'required' => TRUE,
    ];
    $this->writeSettings($settings);
  }

  /**
   * {@inheritdoc}
   *
   * Tolerate the Windows-only teardown file lock. BrowserTestBase deletes the
   * child test-site directory by chmod-ing every entry first; on Windows the
   * recursive chmod can fail with "chmod(): Permission denied" when the host OS
   * (or the PHP built-in server that just served the site) transiently holds a
   * generated cache file open. The test assertions have already completed by
   * this point, so a cleanup chmod failure must not fail the test — we swallow
   * it and remove the leftover directory best-effort instead.
   */
  protected function cleanupEnvironment() {
    try {
      parent::cleanupEnvironment();
    }
    catch (\Throwable $e) {
      if (stripos($e->getMessage(), 'chmod') === FALSE && stripos($e->getMessage(), 'Permission denied') === FALSE) {
        // Not the known Windows file-lock issue — re-throw real failures.
        throw $e;
      }
      $dir = DRUPAL_ROOT . '/' . $this->siteDirectory;
      if (is_dir($dir)) {
        if (str_starts_with(strtoupper(PHP_OS), 'WIN')) {
          // rmdir /s /q ignores transient locks far better than PHP unlink.
          @exec('rmdir /s /q "' . str_replace('/', '\\', $dir) . '"');
        }
        else {
          @exec('rm -rf ' . escapeshellarg($dir));
        }
      }
    }
  }

  /**
   * A permitted site-scope request returns 200 with the expected JSON shape.
   */
  public function testSiteScopeReturnsJson(): void {
    $this->drupalLogin($this->drupalCreateUser(['view ai token usage reports']));

    $this->drupalGet(self::API_PATH, ['query' => ['scope' => 'site', 'period' => 'month']]);
    // The pre-fix bug threw a RuntimeException -> 500. Assert it does not.
    $this->assertSession()->statusCodeEquals(200);

    $data = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($data);
    $this->assertArrayHasKey('tokens', $data);
    $this->assertArrayHasKey('scope', $data);
    $this->assertArrayHasKey('period', $data);
    $this->assertArrayHasKey('breakdown', $data);
    $this->assertSame('site', $data['scope']);
    $this->assertSame('month', $data['period']);
  }

  /**
   * The own-usage permission grants access to scope=own.
   */
  public function testOwnScopeReturnsJson(): void {
    $this->drupalLogin($this->drupalCreateUser(['view own ai token usage']));

    $this->drupalGet(self::API_PATH, ['query' => ['scope' => 'own', 'period' => 'month']]);
    $this->assertSession()->statusCodeEquals(200);

    $data = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($data);
    $this->assertArrayHasKey('tokens', $data);
    $this->assertArrayHasKey('breakdown', $data);
    $this->assertSame('own', $data['scope']);
  }

  /**
   * A logged-in user without any usage permission is denied (403, not 500).
   */
  public function testNoPermissionIsForbidden(): void {
    $this->drupalLogin($this->drupalCreateUser([]));

    $this->drupalGet(self::API_PATH, ['query' => ['scope' => 'site', 'period' => 'month']]);
    $this->assertSession()->statusCodeEquals(403);
  }

}
