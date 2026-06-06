<?php

declare(strict_types=1);

namespace Drupal\Tests\license_service\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\license_service\LicenseClient;
use Drupal\license_service\LicenseManagerService;
use Drupal\license_service\SeatCapService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SeatCapService.
 *
 * Covers the lock acquire/retry logic, LVS authorization, cache hit/miss/stale
 * paths, TTL derivation from expires_at, and fail-closed offline behavior.
 *
 * @group license_service
 * @coversDefaultClass \Drupal\license_service\SeatCapService
 *
 * Author: Jeremiah Buttler
 */
class SeatCapServiceTest extends TestCase {

  // --------------------------------------------------------------------------
  // Helpers
  // --------------------------------------------------------------------------

  /**
   * Builds a SeatCapService with all dependencies as mocks.
   *
   * @param array $roleLevels  role_id => level map for license_service.role_levels config.
   * @param array $lvsResponse Return value of LicenseClient::authorizeUser().
   * @param \Drupal\Core\Lock\LockBackendInterface|null $lock Optional pre-built lock mock.
   * @param \Drupal\Core\Cache\CacheBackendInterface|null $cache Optional pre-built cache mock.
   */
  private function buildService(
    array $roleLevels = [],
    array $lvsResponse = ['status' => 'granted'],
    ?LockBackendInterface $lock = NULL,
    ?CacheBackendInterface $cache = NULL,
  ): SeatCapService {
    $roleLevelsConfig = $this->createMock(Config::class);
    $roleLevelsConfig->method('get')->with('role_levels')->willReturn($roleLevels);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('license_service.role_levels')->willReturn($roleLevelsConfig);

    $licenseManager = $this->createMock(LicenseManagerService::class);
    // levelAtLeast('premium', 'free') returns TRUE — any non-empty, non-free level is premium.
    $licenseManager->method('levelAtLeast')->willReturn(TRUE);

    $licenseClient = $this->getMockBuilder(LicenseClient::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['authorizeUser'])
      ->getMock();
    $licenseClient->method('authorizeUser')->willReturn($lvsResponse);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    return new SeatCapService(
      $licenseManager,
      $licenseClient,
      $configFactory,
      $entityTypeManager,
      $lock ?? $this->buildLock(firstAcquire: TRUE),
      $cache ?? $this->buildEmptyCache(),
    );
  }

  /**
   * Builds a LockBackendInterface mock with configurable acquire() return values.
   *
   * @param bool $firstAcquire  Result of the first acquire() call.
   * @param bool $secondAcquire Result of the second acquire() call (after wait).
   */
  private function buildLock(bool $firstAcquire = TRUE, bool $secondAcquire = TRUE): LockBackendInterface {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnOnConsecutiveCalls($firstAcquire, $secondAcquire);
    return $lock;
  }

  /**
   * Builds a grant-cache mock that returns no cached entry.
   */
  private function buildEmptyCache(): CacheBackendInterface {
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    return $cache;
  }

  /**
   * Builds a grant-cache mock with a pre-loaded entry.
   */
  private function buildCacheWithEntry(bool $value): CacheBackendInterface {
    $entry = new \stdClass();
    $entry->data = $value;
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn($entry);
    return $cache;
  }

  /**
   * Builds a stubbed AccountInterface for the given UID with optional roles.
   */
  private function buildAccount(int $uid = 42, bool $isAdmin = FALSE, string $email = 'u@example.com'): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('getEmail')->willReturn($email);
    $account->method('hasRole')->with('administrator')->willReturn($isAdmin);
    return $account;
  }

  // --------------------------------------------------------------------------
  // acquireSeatLock() tests
  // --------------------------------------------------------------------------

  /**
   * @covers ::acquireSeatLock
   */
  public function testAcquireLockSucceedsImmediately(): void {
    $lock = $this->buildLock(firstAcquire: TRUE);
    // wait() should NOT be called when first acquire succeeds.
    $lock->expects($this->never())->method('wait');

    $svc = $this->buildService(lock: $lock);
    $this->assertTrue($svc->acquireSeatLock());
  }

  /**
   * @covers ::acquireSeatLock
   */
  public function testAcquireLockRetriesAfterWait(): void {
    $lock = $this->buildLock(firstAcquire: FALSE, secondAcquire: TRUE);
    $lock->expects($this->once())->method('wait')->with(SeatCapService::SEAT_LOCK, 10);

    $svc = $this->buildService(lock: $lock);
    $this->assertTrue($svc->acquireSeatLock());
  }

  /**
   * @covers ::acquireSeatLock
   */
  public function testAcquireLockFailsAfterRetry(): void {
    $lock = $this->buildLock(firstAcquire: FALSE, secondAcquire: FALSE);
    $lock->expects($this->once())->method('wait');

    $svc = $this->buildService(lock: $lock);
    $this->assertFalse($svc->acquireSeatLock());
  }

  /**
   * @covers ::releaseSeatLock
   */
  public function testReleaseSeatLockCallsRelease(): void {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())->method('release')->with(SeatCapService::SEAT_LOCK);

    $svc = $this->buildService(lock: $lock);
    $svc->releaseSeatLock();
  }

  // --------------------------------------------------------------------------
  // mayAssignRole() — free / non-premium roles
  // --------------------------------------------------------------------------

  /**
   * @covers ::mayAssignRole
   */
  public function testNonPremiumRoleAlwaysAllowed(): void {
    // No role_levels mapping → every role is free.
    $svc = $this->buildService(roleLevels: []);
    $account = $this->buildAccount();

    $this->assertTrue($svc->mayAssignRole($account, 'editor'));
  }

  /**
   * @covers ::mayAssignRole
   */
  public function testFreeRoleAlwaysAllowed(): void {
    $svc = $this->buildService(roleLevels: ['editor' => 'free']);
    $account = $this->buildAccount();

    $this->assertTrue($svc->mayAssignRole($account, 'editor'));
  }

  // --------------------------------------------------------------------------
  // mayAssignRole() — cache hits
  // --------------------------------------------------------------------------

  /**
   * @covers ::mayAssignRole
   */
  public function testCacheHitGrantedSkipsLvs(): void {
    $cache = $this->buildCacheWithEntry(TRUE);

    $licenseClient = $this->getMockBuilder(LicenseClient::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['authorizeUser'])
      ->getMock();
    $licenseClient->expects($this->never())->method('authorizeUser');

    $roleLevelsConfig = $this->createMock(Config::class);
    $roleLevelsConfig->method('get')->willReturn(['premium' => 'pro']);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($roleLevelsConfig);

    $licenseManager = $this->createMock(LicenseManagerService::class);
    $licenseManager->method('levelAtLeast')->willReturn(TRUE);

    $svc = new SeatCapService(
      $licenseManager,
      $licenseClient,
      $configFactory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->buildLock(),
      $cache,
    );

    $this->assertTrue($svc->mayAssignRole($this->buildAccount(), 'premium'));
  }

  /**
   * @covers ::mayAssignRole
   */
  public function testCacheHitDeniedSkipsLvs(): void {
    $cache = $this->buildCacheWithEntry(FALSE);

    $licenseClient = $this->getMockBuilder(LicenseClient::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['authorizeUser'])
      ->getMock();
    $licenseClient->expects($this->never())->method('authorizeUser');

    $roleLevelsConfig = $this->createMock(Config::class);
    $roleLevelsConfig->method('get')->willReturn(['premium' => 'pro']);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($roleLevelsConfig);

    $licenseManager = $this->createMock(LicenseManagerService::class);
    $licenseManager->method('levelAtLeast')->willReturn(TRUE);

    $svc = new SeatCapService(
      $licenseManager,
      $licenseClient,
      $configFactory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->buildLock(),
      $cache,
    );

    $this->assertFalse($svc->mayAssignRole($this->buildAccount(), 'premium'));
  }

  // --------------------------------------------------------------------------
  // mayAssignRole() — LVS live responses
  // --------------------------------------------------------------------------

  /**
   * @covers ::mayAssignRole
   */
  public function testLvsGrantedReturnsTrueAndCaches(): void {
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    $cache->expects($this->once())->method('set')
      ->with($this->stringContains('lvs_grant:42:user'), TRUE, $this->anything(), $this->anything());

    $svc = $this->buildService(
      roleLevels: ['premium' => 'pro'],
      lvsResponse: ['status' => 'granted'],
      cache: $cache,
    );

    $this->assertTrue($svc->mayAssignRole($this->buildAccount(42), 'premium'));
  }

  /**
   * @covers ::mayAssignRole
   */
  public function testLvsRejectedReturnsFalseAndCaches(): void {
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    $cache->expects($this->once())->method('set')
      ->with($this->stringContains('lvs_grant:42:user'), FALSE, $this->anything(), $this->anything());

    $svc = $this->buildService(
      roleLevels: ['premium' => 'pro'],
      lvsResponse: ['status' => 'rejected'],
      cache: $cache,
    );

    $this->assertFalse($svc->mayAssignRole($this->buildAccount(42), 'premium'));
  }

  /**
   * @covers ::mayAssignRole
   */
  public function testAdminAccountUsesAdminKindCacheKey(): void {
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    // Cache key must contain 'admin' kind, not 'user'.
    $cache->expects($this->once())->method('set')
      ->with($this->matchesRegularExpression('/lvs_grant:\d+:admin/'), TRUE, $this->anything(), $this->anything());

    $svc = $this->buildService(
      roleLevels: ['premium' => 'pro'],
      lvsResponse: ['status' => 'granted'],
      cache: $cache,
    );

    $this->assertTrue($svc->mayAssignRole($this->buildAccount(7, isAdmin: TRUE), 'premium'));
  }

  // --------------------------------------------------------------------------
  // mayAssignRole() — TTL derivation
  // --------------------------------------------------------------------------

  /**
   * @covers ::mayAssignRole
   */
  public function testTtlDerivedFromExpiresAt(): void {
    $futureIso = (new \DateTime('+7200 seconds', new \DateTimeZone('UTC')))->format(\DateTime::ATOM);

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    // The stored expiry should be roughly now + 7200 (±5 s tolerance).
    $cache->expects($this->once())->method('set')
      ->with(
        $this->anything(),
        TRUE,
        $this->logicalAnd(
          $this->greaterThan(time() + 7190),
          $this->lessThan(time() + 7210),
        ),
        $this->anything(),
      );

    $svc = $this->buildService(
      roleLevels: ['premium' => 'pro'],
      lvsResponse: ['status' => 'granted', 'expires_at' => $futureIso],
      cache: $cache,
    );

    $this->assertTrue($svc->mayAssignRole($this->buildAccount(), 'premium'));
  }

  /**
   * @covers ::mayAssignRole
   */
  public function testTtlFallsBackToDefaultWhenExpiresAtMissing(): void {
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    // Default TTL is GRANT_CACHE_DEFAULT_TTL = 3600.
    $cache->expects($this->once())->method('set')
      ->with(
        $this->anything(),
        TRUE,
        $this->logicalAnd(
          $this->greaterThan(time() + 3590),
          $this->lessThan(time() + 3610),
        ),
        $this->anything(),
      );

    $svc = $this->buildService(
      roleLevels: ['premium' => 'pro'],
      lvsResponse: ['status' => 'granted'],
      cache: $cache,
    );

    $this->assertTrue($svc->mayAssignRole($this->buildAccount(), 'premium'));
  }

  /**
   * @covers ::mayAssignRole
   */
  public function testTtlFallsBackToDefaultWhenExpiresAtMalformed(): void {
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    $cache->expects($this->once())->method('set')
      ->with(
        $this->anything(),
        TRUE,
        $this->logicalAnd(
          $this->greaterThan(time() + 3590),
          $this->lessThan(time() + 3610),
        ),
        $this->anything(),
      );

    $svc = $this->buildService(
      roleLevels: ['premium' => 'pro'],
      lvsResponse: ['status' => 'granted', 'expires_at' => 'not-a-date'],
      cache: $cache,
    );

    $this->assertTrue($svc->mayAssignRole($this->buildAccount(), 'premium'));
  }

  // --------------------------------------------------------------------------
  // mayAssignRole() — LVS error / offline grace
  // --------------------------------------------------------------------------

  /**
   * @covers ::mayAssignRole
   */
  public function testLvsErrorWithStaleCacheReturnsStaleGrant(): void {
    $staleEntry = new \stdClass();
    $staleEntry->data = TRUE;

    $cache = $this->createMock(CacheBackendInterface::class);
    // First get (non-expired) returns no entry; second get (allow invalid = TRUE) returns stale.
    $cache->method('get')
      ->willReturnCallback(function (string $cid, bool $allowInvalid = FALSE) use ($staleEntry) {
        return $allowInvalid ? $staleEntry : FALSE;
      });

    $svc = $this->buildService(
      roleLevels: ['premium' => 'pro'],
      lvsResponse: ['status' => 'error'],
      cache: $cache,
    );

    $this->assertTrue($svc->mayAssignRole($this->buildAccount(), 'premium'));
  }

  /**
   * @covers ::mayAssignRole
   */
  public function testLvsErrorWithNoCacheFailsClosed(): void {
    // Both get() calls (normal and allowInvalid) return FALSE — no stale entry.
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);

    $svc = $this->buildService(
      roleLevels: ['premium' => 'pro'],
      lvsResponse: ['status' => 'error'],
      cache: $cache,
    );

    $this->assertFalse($svc->mayAssignRole($this->buildAccount(), 'premium'));
  }

  /**
   * @covers ::mayAssignRole
   */
  public function testLvsErrorNeverWritesToCache(): void {
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    $cache->expects($this->never())->method('set');

    $svc = $this->buildService(
      roleLevels: ['premium' => 'pro'],
      lvsResponse: ['status' => 'error'],
      cache: $cache,
    );

    $svc->mayAssignRole($this->buildAccount(), 'premium');
  }

  // --------------------------------------------------------------------------
  // clearGrantCache()
  // --------------------------------------------------------------------------

  /**
   * @covers ::clearGrantCache
   */
  public function testClearGrantCacheInvalidatesUserTag(): void {
    // Drupal cache services implement both CacheBackendInterface and
    // CacheTagsInvalidatorInterface. CombinedCacheInterface merges both so
    // createMock() stubs all required methods including invalidateTags().
    $cache = $this->createMock(CombinedCacheInterface::class);
    $cache->expects($this->once())->method('invalidateTags')->with(['lvs_grant:99']);

    $svc = $this->buildService(cache: $cache);
    $svc->clearGrantCache('99');
  }

}

/**
 * Combined interface for mocking cache objects that support tag invalidation.
 *
 * Drupal cache backend services implement both CacheBackendInterface and
 * CacheTagsInvalidatorInterface. This interface merges both so a single
 * createMock() call stubs all required methods, including invalidateTags().
 */
interface CombinedCacheInterface extends CacheBackendInterface, CacheTagsInvalidatorInterface {}

/**
 * Extended account interface for tests that need hasRole() on an AccountInterface mock.
 *
 * AccountInterface declares getRoles() but not hasRole(). SeatCapService calls
 * hasRole() on its AccountInterface parameter, so the test mock must support it.
 */
interface AccountWithRolesInterface extends AccountInterface {
  public function hasRole(string $rid): bool;
}
