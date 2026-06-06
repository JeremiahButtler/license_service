<?php

declare(strict_types=1);

namespace Drupal\Tests\license_service_token_counter\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Verifies access control on the AI token usage report.
 *
 * Installs License Service Token Counter, which depends only on the AI module (`ai`) — token
 * counting no longer requires the License module — and checks who may view the
 * usage report.
 *
 * @group license_service_token_counter
 */
final class UsageReportAccessTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['license_service_token_counter'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The report path.
   */
  private const REPORT_PATH = '/admin/reports/ai-token-usage';

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
   * Anonymous users are denied; permitted users may view the report.
   */
  public function testReportAccess(): void {
    // Anonymous: denied.
    $this->drupalGet(self::REPORT_PATH);
    $this->assertSession()->statusCodeEquals(403);

    // User without permission: denied.
    $this->drupalLogin($this->drupalCreateUser([]));
    $this->drupalGet(self::REPORT_PATH);
    $this->assertSession()->statusCodeEquals(403);

    // User with aggregate permission: allowed.
    $this->drupalLogin($this->drupalCreateUser(['view ai token usage reports']));
    $this->drupalGet(self::REPORT_PATH);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('AI calls');

    // User with own-usage permission only: allowed.
    $this->drupalLogin($this->drupalCreateUser(['view own ai token usage']));
    $this->drupalGet(self::REPORT_PATH);
    $this->assertSession()->statusCodeEquals(200);
  }

}
