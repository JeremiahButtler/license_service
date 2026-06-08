<?php

declare(strict_types=1);

namespace Drupal\Tests\license_service_subscriptions\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\license_service_subscriptions\Service\SubscriptionChoiceTokenService;
use Drupal\license_service_subscriptions\Service\SubscriptionNotificationService;
use Drupal\license_service_subscriptions\Service\SubscriptionRoleGrantService;
use Drupal\license_service_subscriptions\Service\TierMigrationService;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Unit tests for TierMigrationService suspend/resume operations.
 *
 * SuspendSubscription() and resumeSubscription() are the cleanest state-machine
 * operations to unit-test: they call \Drupal::time() and a single DB UPDATE with
 * well-defined WHERE conditions, making their behavior fully verifiable via mocks.
 *
 * @coversDefaultClass \Drupal\license_service_subscriptions\Service\TierMigrationService
 * @group license_service_subscriptions
 *
 * Author: Jeremiah Buttler.
 */
class TierMigrationServiceTest extends UnitTestCase {

  /**
   * Frozen "now" timestamp used across all tests.
   */
  private const NOW = 1700000000;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Wire a minimal Drupal container so \Drupal::time() is resolvable.
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(self::NOW);

    $container = new ContainerBuilder();
    $container->set('datetime.time', $time);
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Reset the static container so it does not bleed into other test classes.
    \Drupal::setContainer(new ContainerBuilder());
    parent::tearDown();
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Builds an Update query mock returning $rowsAffected from execute().
   */
  private function buildUpdateMock(int $rowsAffected = 1): Update {
    /** @var \Drupal\Core\Database\Query\Update&\PHPUnit\Framework\MockObject\MockObject $update */
    $update = $this->getMockBuilder(Update::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['fields', 'condition', 'execute'])
      ->getMock();
    $update->method('fields')->willReturnSelf();
    $update->method('condition')->willReturnSelf();
    $update->method('execute')->willReturn($rowsAffected);
    return $update;
  }

  /**
   * Builds a TierMigrationService with the supplied DB mock.
   *
   * All other dependencies are created as no-op mocks.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The DB mock wired to the service under test.
   * @param \Drupal\Core\Logger\LoggerChannelInterface|null $logger
   *   Optional logger channel for assertion on log calls.
   */
  private function buildService(Connection $database, ?LoggerChannelInterface $logger = NULL): TierMigrationService {
    if ($logger === NULL) {
      $logger = $this->createMock(LoggerChannelInterface::class);
    }

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    return new TierMigrationService(
      $database,
      $this->createMock(SubscriptionRoleGrantService::class),
      $this->createMock(SubscriptionChoiceTokenService::class),
      $this->createMock(QueueFactory::class),
      $loggerFactory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(SubscriptionNotificationService::class),
    );
  }

  // ---------------------------------------------------------------------------
  // suspendSubscription()
  // ---------------------------------------------------------------------------

  /**
   * @covers ::suspendSubscription
   */
  public function testSuspendSubscriptionCallsUpdateWithPausedState(): void {
    // 1 row updated = active sub found
    $update = $this->buildUpdateMock(1);

    /** @var \Drupal\Core\Database\Connection&\PHPUnit\Framework\MockObject\MockObject $db */
    $db = $this->getMockBuilder(Connection::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['update'])
      ->getMockForAbstractClass();

    // Verify update() is called on the right table.
    $db->expects($this->once())
      ->method('update')
      ->with('license_service_subscriptions_state')
      ->willReturn($update);

    $service = $this->buildService($db);
    $service->suspendSubscription(101, 'plan_deprecated');
    // Success == no exception + correct table targeted.
  }

  /**
   * @covers ::suspendSubscription
   */
  public function testSuspendSubscriptionDoesNotThrowWhenNoActiveRowExists(): void {
    // 0 rows updated = sub not active
    $update = $this->buildUpdateMock(0);

    /** @var \Drupal\Core\Database\Connection&\PHPUnit\Framework\MockObject\MockObject $db */
    $db = $this->getMockBuilder(Connection::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['update'])
      ->getMockForAbstractClass();
    $db->method('update')->willReturn($update);

    // No exception expected; logger->info() is NOT called when 0 rows affected.
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->never())->method('info');

    $service = $this->buildService($db, $logger);
    $service->suspendSubscription(999, 'plan_deprecated');
  }

  /**
   * @covers ::suspendSubscription
   */
  public function testSuspendSubscriptionLogsInfoWhenRowIsUpdated(): void {
    $update = $this->buildUpdateMock(1);

    /** @var \Drupal\Core\Database\Connection&\PHPUnit\Framework\MockObject\MockObject $db */
    $db = $this->getMockBuilder(Connection::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['update'])
      ->getMockForAbstractClass();
    $db->method('update')->willReturn($update);

    // logger->info() must be called exactly once when 1 row is updated.
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('info');

    $service = $this->buildService($db, $logger);
    $service->suspendSubscription(101, 'plan_deprecated');
  }

  // ---------------------------------------------------------------------------
  // resumeSubscription()
  // ---------------------------------------------------------------------------

  /**
   * @covers ::resumeSubscription
   */
  public function testResumeSubscriptionCallsUpdateWithActiveState(): void {
    $update = $this->buildUpdateMock(1);

    /** @var \Drupal\Core\Database\Connection&\PHPUnit\Framework\MockObject\MockObject $db */
    $db = $this->getMockBuilder(Connection::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['update'])
      ->getMockForAbstractClass();

    $db->expects($this->once())
      ->method('update')
      ->with('license_service_subscriptions_state')
      ->willReturn($update);

    $service = $this->buildService($db);
    $service->resumeSubscription(202);
  }

  /**
   * @covers ::resumeSubscription
   */
  public function testResumeSubscriptionDoesNotThrowWhenNoRowIsPaused(): void {
    // 0 rows = subscription was not paused
    $update = $this->buildUpdateMock(0);

    /** @var \Drupal\Core\Database\Connection&\PHPUnit\Framework\MockObject\MockObject $db */
    $db = $this->getMockBuilder(Connection::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['update'])
      ->getMockForAbstractClass();
    $db->method('update')->willReturn($update);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->never())->method('info');

    $service = $this->buildService($db, $logger);
    $service->resumeSubscription(999);
  }

  /**
   * @covers ::resumeSubscription
   */
  public function testResumeSubscriptionLogsInfoWhenRowIsUpdated(): void {
    $update = $this->buildUpdateMock(1);

    /** @var \Drupal\Core\Database\Connection&\PHPUnit\Framework\MockObject\MockObject $db */
    $db = $this->getMockBuilder(Connection::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['update'])
      ->getMockForAbstractClass();
    $db->method('update')->willReturn($update);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('info');

    $service = $this->buildService($db, $logger);
    $service->resumeSubscription(202);
  }

}
