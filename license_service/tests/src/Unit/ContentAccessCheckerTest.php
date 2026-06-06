<?php

namespace Drupal\Tests\license_service\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\license_service\Access\ContentAccessChecker;
use Drupal\license_service\Entitlements\EntitlementResolver;
use Drupal\license_service\LicenseManagerService;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ContentAccessChecker.
 *
 * @group license_service
 * @coversDefaultClass \Drupal\license_service\Access\ContentAccessChecker
 *
 * Author: Jeremiah Buttler
 */
class ContentAccessCheckerTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // AccessResult cacheability merges validate cache contexts through the
    // global container (Cache::mergeContexts -> cache_contexts_manager). Pure
    // unit tests have no container, so provide a minimal one here.
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    \Drupal::setContainer($container);
  }

  // --------------------------------------------------------------------------
  // Helpers

  /**
   * Builds a mock license manager that returns the given level.
   */
  private function mockManager(string $level = 'free'): LicenseManagerService {
    $manager = $this->createMock(LicenseManagerService::class);
    $manager->method('getLevelForAccount')->willReturn($level);
    return $manager;
  }

  /**
   * Builds a mock entitlement resolver with overridable defaults.
   */
  private function mockResolver(array $overrides = []): EntitlementResolver {
    $defaults = [
      'canView'          => TRUE,
      'canCreate'        => TRUE,
      'canEdit'          => TRUE,
      'canDelete'        => TRUE,
      'getCreateQuota'   => 0,
      'getEditQuota'     => 0,
      'getViewLimit'     => 0,
      'getViewPeriod'    => 'monthly',
      'getCurrentPeriodKey' => '2026-05',
      'getGatedFields'   => [],
      'gatesFileDownloads' => FALSE,
    ];
    $cfg = array_merge($defaults, $overrides);

    $resolver = $this->createMock(EntitlementResolver::class);
    $resolver->method('canView')->willReturn($cfg['canView']);
    $resolver->method('canCreate')->willReturn($cfg['canCreate']);
    $resolver->method('canEdit')->willReturn($cfg['canEdit']);
    $resolver->method('canDelete')->willReturn($cfg['canDelete']);
    $resolver->method('getCreateQuota')->willReturn($cfg['getCreateQuota']);
    $resolver->method('getEditQuota')->willReturn($cfg['getEditQuota']);
    $resolver->method('getViewLimit')->willReturn($cfg['getViewLimit']);
    $resolver->method('getViewPeriod')->willReturn($cfg['getViewPeriod']);
    $resolver->method('getCurrentPeriodKey')->willReturn($cfg['getCurrentPeriodKey']);
    $resolver->method('getGatedFields')->willReturn($cfg['getGatedFields']);
    $resolver->method('gatesFileDownloads')->willReturn($cfg['gatesFileDownloads']);
    return $resolver;
  }

  /**
   * Builds a mock DB that returns $count on any ->select()->...->fetchField().
   */
  private function mockDatabase(int $count = 0): Connection {
    $stmt = $this->createMock(StatementInterface::class);
    $stmt->method('fetchField')->willReturn($count);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($stmt);

    $db = $this->createMock(Connection::class);
    $db->method('select')->willReturn($select);
    return $db;
  }

  /**
   * Builds a mock account with the given UID.
   */
  private function mockAccount(int $uid = 1): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn((string) $uid);
    $account->method('hasPermission')->willReturn(FALSE);
    return $account;
  }

  /**
   * Builds a mock current user proxy with the given UID.
   */
  private function mockCurrentUser(int $uid = 1): AccountProxyInterface {
    $proxy = $this->createMock(AccountProxyInterface::class);
    $proxy->method('id')->willReturn((string) $uid);
    $proxy->method('hasPermission')->willReturn(FALSE);
    return $proxy;
  }

  /**
   * Builds a mock node of the given bundle.
   */
  private function mockNode(string $bundle = 'article'): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn($bundle);
    return $node;
  }

  /**
   * Builds a mock entity type manager that loads entities of the given bundle.
   *
   * Any loadMultiple() call returns a single entity reporting $bundle.
   */
  private function mockEntityTypeManager(string $bundle = 'article'): EntityTypeManagerInterface {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('bundle')->willReturn($bundle);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn([$entity]);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')->willReturn(TRUE);
    $etm->method('getStorage')->willReturn($storage);
    return $etm;
  }

  /**
   * Builds a ContentAccessChecker from the given collaborators.
   */
  private function buildChecker(
    LicenseManagerService $manager,
    EntitlementResolver $resolver,
    Connection $db,
    ?AccountProxyInterface $currentUser = NULL,
    ?EntityTypeManagerInterface $entityTypeManager = NULL,
  ): ContentAccessChecker {
    return new ContentAccessChecker(
      $manager,
      $resolver,
      $db,
      $currentUser ?? $this->mockCurrentUser(),
      $entityTypeManager ?? $this->mockEntityTypeManager(),
    );
  }

  // --------------------------------------------------------------------------
  // checkNodeAccess — view
  // --------------------------------------------------------------------------

  /**
   * @covers ::checkNodeAccess
   */
  public function testViewAllowedWhenEntitlementGranted(): void {
    $checker = $this->buildChecker(
      $this->mockManager('premium'),
      $this->mockResolver(['canView' => TRUE, 'getViewLimit' => 0]),
      $this->mockDatabase(),
    );
    $result = $checker->checkNodeAccess($this->mockNode(), 'view', $this->mockAccount());
    $this->assertFalse($result->isForbidden(), 'Should be neutral, not forbidden');
  }

  /**
   * @covers ::checkNodeAccess
   */
  public function testViewForbiddenWhenNotEntitled(): void {
    $checker = $this->buildChecker(
      $this->mockManager('free'),
      $this->mockResolver(['canView' => FALSE]),
      $this->mockDatabase(),
    );
    $result = $checker->checkNodeAccess($this->mockNode(), 'view', $this->mockAccount());
    $this->assertTrue($result->isForbidden());
  }

  /**
   * @covers ::checkNodeAccess
   */
  public function testViewForbiddenWhenMeteredLimitReached(): void {
    $checker = $this->buildChecker(
      $this->mockManager('free'),
      $this->mockResolver(['canView' => TRUE, 'getViewLimit' => 5, 'getViewPeriod' => 'monthly']),
    // Count == limit → deny.
      $this->mockDatabase(5),
    );
    $result = $checker->checkNodeAccess($this->mockNode(), 'view', $this->mockAccount());
    $this->assertTrue($result->isForbidden());
  }

  /**
   * @covers ::checkNodeAccess
   */
  public function testViewAllowedWhenMeteredLimitNotReached(): void {
    $checker = $this->buildChecker(
      $this->mockManager('free'),
      $this->mockResolver(['canView' => TRUE, 'getViewLimit' => 5, 'getViewPeriod' => 'monthly']),
    // Count < limit → allow.
      $this->mockDatabase(3),
    );
    $result = $checker->checkNodeAccess($this->mockNode(), 'view', $this->mockAccount());
    $this->assertFalse($result->isForbidden());
  }

  // --------------------------------------------------------------------------
  // checkNodeAccess — update / delete
  // --------------------------------------------------------------------------

  /**
   * @covers ::checkNodeAccess
   */
  public function testUpdateForbiddenWhenNotEntitled(): void {
    $checker = $this->buildChecker(
      $this->mockManager('free'),
      $this->mockResolver(['canEdit' => FALSE]),
      $this->mockDatabase(),
    );
    $result = $checker->checkNodeAccess($this->mockNode(), 'update', $this->mockAccount());
    $this->assertTrue($result->isForbidden());
  }

  /**
   * @covers ::checkNodeAccess
   */
  public function testUpdateForbiddenWhenEditQuotaReached(): void {
    $checker = $this->buildChecker(
      $this->mockManager('free'),
      $this->mockResolver(['canEdit' => TRUE, 'getEditQuota' => 3]),
    // Used == quota.
      $this->mockDatabase(3),
    );
    $result = $checker->checkNodeAccess($this->mockNode(), 'update', $this->mockAccount());
    $this->assertTrue($result->isForbidden());
  }

  /**
   * @covers ::checkNodeAccess
   */
  public function testDeleteForbiddenWhenNotEntitled(): void {
    $checker = $this->buildChecker(
      $this->mockManager('free'),
      $this->mockResolver(['canDelete' => FALSE]),
      $this->mockDatabase(),
    );
    $result = $checker->checkNodeAccess($this->mockNode(), 'delete', $this->mockAccount());
    $this->assertTrue($result->isForbidden());
  }

  /**
   * @covers ::checkNodeAccess
   */
  public function testDeleteAllowedWhenEntitled(): void {
    $checker = $this->buildChecker(
      $this->mockManager('premium'),
      $this->mockResolver(['canDelete' => TRUE]),
      $this->mockDatabase(),
    );
    $result = $checker->checkNodeAccess($this->mockNode(), 'delete', $this->mockAccount());
    $this->assertFalse($result->isForbidden());
  }

  // --------------------------------------------------------------------------
  // checkCreateAccess
  // --------------------------------------------------------------------------

  /**
   * @covers ::checkCreateAccess
   */
  public function testCreateForbiddenWhenNotEntitled(): void {
    $checker = $this->buildChecker(
      $this->mockManager('free'),
      $this->mockResolver(['canCreate' => FALSE]),
      $this->mockDatabase(),
    );
    $result = $checker->checkCreateAccess($this->mockAccount(), [], 'article');
    $this->assertTrue($result->isForbidden());
  }

  /**
   * @covers ::checkCreateAccess
   */
  public function testCreateForbiddenWhenQuotaReached(): void {
    $checker = $this->buildChecker(
      $this->mockManager('free'),
      $this->mockResolver(['canCreate' => TRUE, 'getCreateQuota' => 10]),
    // Used == quota.
      $this->mockDatabase(10),
    );
    $result = $checker->checkCreateAccess($this->mockAccount(), [], 'article');
    $this->assertTrue($result->isForbidden());
  }

  /**
   * @covers ::checkCreateAccess
   */
  public function testCreateAllowedUnderQuota(): void {
    $checker = $this->buildChecker(
      $this->mockManager('standard'),
      $this->mockResolver(['canCreate' => TRUE, 'getCreateQuota' => 10]),
    // 7 < 10
      $this->mockDatabase(7),
    );
    $result = $checker->checkCreateAccess($this->mockAccount(), [], 'article');
    $this->assertFalse($result->isForbidden());
  }

  /**
   * @covers ::checkCreateAccess
   */
  public function testCreateAllowedWithNoQuota(): void {
    $checker = $this->buildChecker(
      $this->mockManager('premium'),
      $this->mockResolver(['canCreate' => TRUE, 'getCreateQuota' => 0]),
    // Unlimited — count irrelevant.
      $this->mockDatabase(999),
    );
    $result = $checker->checkCreateAccess($this->mockAccount(), [], 'article');
    $this->assertFalse($result->isForbidden());
  }

  // --------------------------------------------------------------------------
  // checkFieldAccess
  // --------------------------------------------------------------------------

  /**
   * @covers ::checkFieldAccess
   */
  public function testFieldAccessNeutralForNonViewOperation(): void {
    $checker = $this->buildChecker(
      $this->mockManager('free'),
      $this->mockResolver(['getGatedFields' => ['field_body']]),
      $this->mockDatabase(),
    );
    $fieldDef = $this->createMock(FieldDefinitionInterface::class);
    $fieldDef->method('getName')->willReturn('field_body');
    $result = $checker->checkFieldAccess('edit', $fieldDef, $this->mockAccount());
    $this->assertFalse($result->isForbidden(), 'edit op must be neutral regardless of gating');
  }

  /**
   * @covers ::checkFieldAccess
   */
  public function testFieldAccessForbiddenForGatedFieldOnView(): void {
    $checker = $this->buildChecker(
      $this->mockManager('free'),
      $this->mockResolver(['getGatedFields' => ['field_premium_body']]),
      $this->mockDatabase(),
    );

    $entity = $this->createMock(EntityInterface::class);
    $entity->method('bundle')->willReturn('article');
    $entity->method('getCacheTags')->willReturn(['node:1']);

    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('getEntity')->willReturn($entity);

    $fieldDef = $this->createMock(FieldDefinitionInterface::class);
    $fieldDef->method('getName')->willReturn('field_premium_body');
    $fieldDef->method('getTargetBundle')->willReturn('article');

    $result = $checker->checkFieldAccess('view', $fieldDef, $this->mockAccount(), $items);
    $this->assertTrue($result->isForbidden());
  }

  /**
   * @covers ::checkFieldAccess
   */
  public function testFieldAccessNeutralForUngatedField(): void {
    $checker = $this->buildChecker(
      $this->mockManager('premium'),
      $this->mockResolver(['getGatedFields' => []]),
      $this->mockDatabase(),
    );

    $entity = $this->createMock(EntityInterface::class);
    $entity->method('bundle')->willReturn('article');
    $entity->method('getCacheTags')->willReturn([]);

    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('getEntity')->willReturn($entity);

    $fieldDef = $this->createMock(FieldDefinitionInterface::class);
    $fieldDef->method('getName')->willReturn('field_body');
    $fieldDef->method('getTargetBundle')->willReturn('article');

    $result = $checker->checkFieldAccess('view', $fieldDef, $this->mockAccount(), $items);
    $this->assertFalse($result->isForbidden());
  }

  // --------------------------------------------------------------------------
  // Cache metadata
  // --------------------------------------------------------------------------

  /**
   * @covers ::checkNodeAccess
   */
  public function testAccessResultCarriesLicenseServiceCacheTag(): void {
    $checker = $this->buildChecker(
      $this->mockManager('free'),
      $this->mockResolver(['canView' => FALSE]),
      $this->mockDatabase(),
    );
    $result = $checker->checkNodeAccess($this->mockNode(), 'view', $this->mockAccount());
    $this->assertContains('license_service', $result->getCacheTags());
  }

  /**
   * @covers ::checkNodeAccess
   */
  public function testNeutralResultCarriesUserRolesCacheContext(): void {
    $checker = $this->buildChecker(
      $this->mockManager('premium'),
      $this->mockResolver(['canView' => TRUE, 'getViewLimit' => 0]),
      $this->mockDatabase(),
    );
    $result = $checker->checkNodeAccess($this->mockNode(), 'view', $this->mockAccount());
    $this->assertContains('user.roles', $result->getCacheContexts());
  }

  // --------------------------------------------------------------------------
  // checkFileDownload
  // --------------------------------------------------------------------------

  /**
   * Builds a DB mock for the file-download lookup path.
   *
   * The first query (file_managed) reads the fid via fetchField(); the second
   * (file_usage) reads (type, id) rows via fetchAll(). One statement mock
   * serves both since each query touches only one fetch method.
   *
   * @param int|false $fid
   *   The fid returned by the file_managed lookup, or FALSE if none.
   * @param array $usageRows
   *   Rows returned by the file_usage lookup (objects with ->type and ->id).
   */
  private function mockFileDatabase(int|false $fid, array $usageRows = []): Connection {
    $stmt = $this->createMock(StatementInterface::class);
    $stmt->method('fetchField')->willReturn($fid);
    $stmt->method('fetchAll')->willReturn($usageRows);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($stmt);

    $db = $this->createMock(Connection::class);
    $db->method('select')->willReturn($select);
    return $db;
  }

  /**
   * @covers ::checkFileDownload
   */
  public function testFileDownloadDeniedWhenLevelGatesBundle(): void {
    // file_usage row points at a node (entity type 'node', id 1); the loaded
    // entity resolves to bundle 'article', which this level gates.
    $row = (object) ['type' => 'node', 'id' => '1'];
    $checker = $this->buildChecker(
      $this->mockManager('free'),
      $this->mockResolver(['gatesFileDownloads' => TRUE]),
      $this->mockFileDatabase(7, [$row]),
      NULL,
      $this->mockEntityTypeManager('article'),
    );
    $this->assertSame(-1, $checker->checkFileDownload('private://secret.pdf'));
  }

  /**
   * @covers ::checkFileDownload
   */
  public function testFileDownloadAbstainsWhenLevelDoesNotGateBundle(): void {
    $row = (object) ['type' => 'node', 'id' => '1'];
    $checker = $this->buildChecker(
      $this->mockManager('premium'),
      $this->mockResolver(['gatesFileDownloads' => FALSE]),
      $this->mockFileDatabase(7, [$row]),
      NULL,
      $this->mockEntityTypeManager('article'),
    );
    $this->assertNull($checker->checkFileDownload('private://secret.pdf'));
  }

  /**
   * @covers ::checkFileDownload
   */
  public function testFileDownloadAbstainsWhenFileUnknown(): void {
    // No fid found → no referencing bundles → abstain.
    $checker = $this->buildChecker(
      $this->mockManager('free'),
      $this->mockResolver(['gatesFileDownloads' => TRUE]),
      $this->mockFileDatabase(FALSE),
    );
    $this->assertNull($checker->checkFileDownload('private://missing.pdf'));
  }

}
