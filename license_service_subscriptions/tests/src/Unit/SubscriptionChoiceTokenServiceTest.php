<?php

declare(strict_types=1);

namespace Drupal\Tests\license_service_subscriptions\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\license_service_subscriptions\Service\SubscriptionChoiceTokenService;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for SubscriptionChoiceTokenService.
 *
 * Covers the validate() and burn() logic branches using mock DB objects.
 * No Drupal container or full bootstrap required — all DB interaction is
 * exercised via PHPUnit mock objects.
 *
 * @coversDefaultClass \Drupal\license_service_subscriptions\Service\SubscriptionChoiceTokenService
 * @group license_service_subscriptions
 *
 * Author: Jeremiah Buttler.
 */
class SubscriptionChoiceTokenServiceTest extends UnitTestCase {

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Builds a ConfigFactory stub returning $ttlDays for any config key.
   */
  private function buildConfigFactory(int $ttlDays = 14): ConfigFactoryInterface {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn($ttlDays);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);

    return $factory;
  }

  /**
   * Builds a Select query mock that returns $row from fetchAssoc().
   */
  private function buildSelectMock(array|false $row): Select {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn($row);

    /** @var Select&\PHPUnit\Framework\MockObject\MockObject $select */
    $select = $this->getMockBuilder(Select::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['fields', 'condition', 'execute'])
      ->getMock();
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    return $select;
  }

  /**
   * Builds an Update query mock.
   *
   * @param int $rowsAffected
   *   Value returned by execute().
   */
  private function buildUpdateMock(int $rowsAffected = 1): Update {
    /** @var Update&\PHPUnit\Framework\MockObject\MockObject $update */
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
   * Builds a Connection mock wired with the given select and update mocks.
   */
  private function buildConnection(?Select $selectMock = NULL, ?Update $updateMock = NULL): Connection {
    /** @var Connection&\PHPUnit\Framework\MockObject\MockObject $db */
    $db = $this->getMockBuilder(Connection::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['select', 'update'])
      ->getMock();

    if ($selectMock !== NULL) {
      $db->method('select')->willReturn($selectMock);
    }
    if ($updateMock !== NULL) {
      $db->method('update')->willReturn($updateMock);
    }

    return $db;
  }

  // ---------------------------------------------------------------------------
  // validate() — empty-token fast path
  // ---------------------------------------------------------------------------

  /**
   * @covers ::validate
   */
  public function testValidateReturnsFalseForEmptyToken(): void {
    // Empty token must return FALSE immediately without any DB call.
    /** @var Connection&\PHPUnit\Framework\MockObject\MockObject $db */
    $db = $this->getMockBuilder(Connection::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['select'])
      ->getMock();
    $db->expects($this->never())->method('select');

    $service = new SubscriptionChoiceTokenService($db, $this->buildConfigFactory());
    $this->assertFalse($service->validate('', 42));
  }

  // ---------------------------------------------------------------------------
  // validate() — DB row branches
  // ---------------------------------------------------------------------------

  /**
   * @covers ::validate
   */
  public function testValidateReturnsFalseWhenTokenNotInDatabase(): void {
    $db = $this->buildConnection($this->buildSelectMock(FALSE));
    $service = new SubscriptionChoiceTokenService($db, $this->buildConfigFactory());
    $this->assertFalse($service->validate('unknowntoken', 42));
  }

  /**
   * @covers ::validate
   */
  public function testValidateReturnsFalseWhenUidMismatches(): void {
    // Token belongs to uid 99 — caller presents uid 42.
    $row = [
      'uid'           => '99',
      'token_used'    => '0',
      'token_expires' => (string) (time() + 86400),
    ];
    $db = $this->buildConnection($this->buildSelectMock($row));
    $service = new SubscriptionChoiceTokenService($db, $this->buildConfigFactory());
    $this->assertFalse($service->validate('sometoken', 42));
  }

  /**
   * @covers ::validate
   */
  public function testValidateReturnsFalseWhenTokenAlreadyUsed(): void {
    $row = [
      'uid'           => '42',
      'token_used'    => '1',   // burned
      'token_expires' => (string) (time() + 86400),
    ];
    $db = $this->buildConnection($this->buildSelectMock($row));
    $service = new SubscriptionChoiceTokenService($db, $this->buildConfigFactory());
    $this->assertFalse($service->validate('sometoken', 42));
  }

  /**
   * @covers ::validate
   */
  public function testValidateReturnsFalseWhenTokenExpired(): void {
    $row = [
      'uid'           => '42',
      'token_used'    => '0',
      'token_expires' => (string) (time() - 1), // 1 second in the past
    ];
    $db = $this->buildConnection($this->buildSelectMock($row));
    $service = new SubscriptionChoiceTokenService($db, $this->buildConfigFactory());
    $this->assertFalse($service->validate('sometoken', 42));
  }

  /**
   * @covers ::validate
   */
  public function testValidateReturnsTrueForValidUnusedUnexpiredToken(): void {
    $row = [
      'uid'           => '42',
      'token_used'    => '0',
      'token_expires' => (string) (time() + 86400 * 14), // 14 days from now
    ];
    $db = $this->buildConnection($this->buildSelectMock($row));
    $service = new SubscriptionChoiceTokenService($db, $this->buildConfigFactory());
    $this->assertTrue($service->validate('sometoken', 42));
  }

  // ---------------------------------------------------------------------------
  // burn()
  // ---------------------------------------------------------------------------

  /**
   * @covers ::burn
   */
  public function testBurnDoesNothingForEmptyToken(): void {
    /** @var Connection&\PHPUnit\Framework\MockObject\MockObject $db */
    $db = $this->getMockBuilder(Connection::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['update'])
      ->getMock();
    $db->expects($this->never())->method('update');

    $service = new SubscriptionChoiceTokenService($db, $this->buildConfigFactory());
    $service->burn('');
    // No assertion needed — the expects($this->never()) above is the test.
  }

  /**
   * @covers ::burn
   */
  public function testBurnCallsUpdateOnMigrationIntentsTable(): void {
    $update = $this->buildUpdateMock();

    /** @var Connection&\PHPUnit\Framework\MockObject\MockObject $db */
    $db = $this->getMockBuilder(Connection::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['update'])
      ->getMock();
    $db->expects($this->once())
      ->method('update')
      ->with('license_service_migration_intents')
      ->willReturn($update);

    $service = new SubscriptionChoiceTokenService($db, $this->buildConfigFactory());
    $service->burn('my-raw-token');
  }

}
