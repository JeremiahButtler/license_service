<?php

namespace Drupal\Tests\license_service\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\license_service\Key\LicenseKeyProvider;
use Drupal\license_service\LicenseClient;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for LicenseClient URL validation and SSRF prevention.
 *
 * These tests do NOT require a live server. They verify that
 * getServerUrl() and isPrivateHost() enforce HTTPS and block
 * private/reserved addresses as designed.
 *
 * @group license_service
 * @coversDefaultClass \Drupal\license_service\LicenseClient
 *
 * Author: Jeremiah Buttler
 */
class LicenseClientSsrfTest extends TestCase {

  // --------------------------------------------------------------------------
  // Helpers
  // --------------------------------------------------------------------------

  /**
   * A thin subclass that exposes isPrivateHost as public for white-box testing.
   */
  private function makeExposedClient(): object {
    return new class extends LicenseClient {

      /**
       * Inject no-op stubs so the constructor doesn't crash.
       */
      public function __construct() {
        // Intentionally left empty — we only call isPrivateHost.
      }

      /**
       * Exposes the protected isPrivateHost() method for testing.
       */
      public function isPrivateHostPublic(string $host): bool {
        return $this->isPrivateHost($host);
      }

      /**
       * Exposes the protected assertPublicHost() method for testing.
       */
      public function assertPublicHostPublic(string $url): array {
        return $this->assertPublicHost($url);
      }

    };
  }

  /**
   * Builds a real LicenseClient with the given server URL in mocked config.
   */
  private function buildClient(string $configuredUrl): LicenseClient {
    $settings = $this->createMock(Config::class);
    $settings->method('get')->willReturnMap([
      ['server_url', $configuredUrl],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('license_service.settings')->willReturn($settings);

    $logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    return new LicenseClient(
      $configFactory,
      $this->createMock(StateInterface::class),
      $this->createMock(ClientInterface::class),
      $loggerFactory,
      $this->createMock(LicenseKeyProvider::class),
    );
  }

  // --------------------------------------------------------------------------
  // isPrivateHost — loopback and special names
  // --------------------------------------------------------------------------

  /**
   * @covers ::isPrivateHost
   */
  public function testLocalhostIsPrivate(): void {
    $c = $this->makeExposedClient();
    $this->assertTrue($c->isPrivateHostPublic('localhost'));
    $this->assertTrue($c->isPrivateHostPublic('LOCALHOST'));
  }

  /**
   * @covers ::isPrivateHost
   */
  public function testDotLocalSuffixIsPrivate(): void {
    $c = $this->makeExposedClient();
    $this->assertTrue($c->isPrivateHostPublic('myserver.local'));
    $this->assertTrue($c->isPrivateHostPublic('db.internal'));
  }

  /**
   * @covers ::isPrivateHost
   */
  public function testLoopbackIpIsPrivate(): void {
    $c = $this->makeExposedClient();
    $this->assertTrue($c->isPrivateHostPublic('127.0.0.1'));
    $this->assertTrue($c->isPrivateHostPublic('127.255.255.255'));
  }

  /**
   * @covers ::isPrivateHost
   */
  public function testRfc1918IpsArePrivate(): void {
    $c = $this->makeExposedClient();
    $this->assertTrue($c->isPrivateHostPublic('10.0.0.1'));
    $this->assertTrue($c->isPrivateHostPublic('192.168.1.100'));
    $this->assertTrue($c->isPrivateHostPublic('172.16.0.1'));
    $this->assertTrue($c->isPrivateHostPublic('172.31.255.254'));
  }

  /**
   * @covers ::isPrivateHost
   */
  public function testPublicIpsAreNotPrivate(): void {
    $c = $this->makeExposedClient();
    $this->assertFalse($c->isPrivateHostPublic('8.8.8.8'));
    $this->assertFalse($c->isPrivateHostPublic('1.1.1.1'));
    $this->assertFalse($c->isPrivateHostPublic('44.228.139.22'));
  }

  /**
   * @covers ::isPrivateHost
   */
  public function testPublicHostnameIsNotPrivate(): void {
    $c = $this->makeExposedClient();
    $this->assertFalse($c->isPrivateHostPublic('www.licenseverificationserver.com'));
    $this->assertFalse($c->isPrivateHostPublic('example.com'));
  }

  // --------------------------------------------------------------------------
  // getServerUrl — config-driven URL resolution
  // --------------------------------------------------------------------------

  /**
   * @covers ::getServerUrl
   */
  public function testValidHttpsUrlIsUsedAsIs(): void {
    $client = $this->buildClient('https://www.licenseverificationserver.com');
    $this->assertSame('https://www.licenseverificationserver.com', $client->getServerUrl());
  }

  /**
   * @covers ::getServerUrl
   */
  public function testTrailingSlashIsStripped(): void {
    $client = $this->buildClient('https://www.licenseverificationserver.com/');
    $this->assertSame('https://www.licenseverificationserver.com', $client->getServerUrl());
  }

  /**
   * The bare canonical apex is normalized to the www host (TLS-only on www).
   *
   * @covers ::getServerUrl
   * @covers ::normalizeServerUrl
   */
  public function testBareCanonicalApexNormalizesToWww(): void {
    $client = $this->buildClient('https://licenseverificationserver.com');
    $this->assertSame('https://www.licenseverificationserver.com', $client->getServerUrl());
  }

  /**
   * A bare-apex URL with a trailing slash is stripped and normalized to www.
   *
   * @covers ::getServerUrl
   * @covers ::normalizeServerUrl
   */
  public function testBareCanonicalApexWithSlashNormalizesToWww(): void {
    $client = $this->buildClient('https://licenseverificationserver.com/');
    $this->assertSame('https://www.licenseverificationserver.com', $client->getServerUrl());
  }

  /**
   * @covers ::getServerUrl
   */
  public function testHttpUrlFallsBackToDefault(): void {
    $client = $this->buildClient('http://www.licenseverificationserver.com');
    $this->assertSame(LicenseClient::DEFAULT_SERVER_URL, $client->getServerUrl());
  }

  /**
   * @covers ::getServerUrl
   */
  public function testEmptyUrlFallsBackToDefault(): void {
    $client = $this->buildClient('');
    $this->assertSame(LicenseClient::DEFAULT_SERVER_URL, $client->getServerUrl());
  }

  /**
   * @covers ::getServerUrl
   */
  public function testPrivateIpUrlFallsBackToDefault(): void {
    $client = $this->buildClient('https://192.168.1.50');
    $this->assertSame(LicenseClient::DEFAULT_SERVER_URL, $client->getServerUrl());
  }

  /**
   * @covers ::getServerUrl
   */
  public function testLocalhostUrlFallsBackToDefault(): void {
    $client = $this->buildClient('https://localhost');
    $this->assertSame(LicenseClient::DEFAULT_SERVER_URL, $client->getServerUrl());
  }

  /**
   * @covers ::getServerUrl
   */
  public function testDotInternalUrlFallsBackToDefault(): void {
    $client = $this->buildClient('https://internal-server.internal');
    $this->assertSame(LicenseClient::DEFAULT_SERVER_URL, $client->getServerUrl());
  }

  /**
   * @covers ::getServerUrl
   */
  public function testLoopbackIpUrlFallsBackToDefault(): void {
    $client = $this->buildClient('https://127.0.0.1');
    $this->assertSame(LicenseClient::DEFAULT_SERVER_URL, $client->getServerUrl());
  }

  // --------------------------------------------------------------------------
  // assertPublicHost — DNS-rebind / private-resolution guard
  // --------------------------------------------------------------------------

  /**
   * @covers ::assertPublicHost
   */
  public function testAssertPublicHostThrowsForPrivateIpLiteral(): void {
    $c = $this->makeExposedClient();
    $this->expectException(\RuntimeException::class);
    $c->assertPublicHostPublic('https://192.168.1.50/api');
  }

  /**
   * @covers ::assertPublicHost
   */
  public function testAssertPublicHostAllowsPublicIpLiteral(): void {
    $c = $this->makeExposedClient();
    // A public IP literal short-circuits before DNS resolution, does not throw,
    // and pins nothing (cURL connects to the literal directly).
    $this->assertSame([], $c->assertPublicHostPublic('https://8.8.8.8/api'));
  }

}
