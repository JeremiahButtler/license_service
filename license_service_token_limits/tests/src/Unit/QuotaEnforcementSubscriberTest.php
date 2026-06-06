<?php

declare(strict_types=1);

namespace Drupal\Tests\license_service_token_limits\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\license_service_token_counter\Exception\TokenLimitExceededException;
use Drupal\license_service_token_limits\EventSubscriber\QuotaEnforcementSubscriber;
use Drupal\license_service_token_limits\Service\LevelQuotaEvaluator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for QuotaEnforcementSubscriber.
 *
 * Covers: under-quota pass-through, setAborted path, exception-throw path,
 * unexpected-error swallow with logging, and graceful class-not-exists guard.
 *
 * @group license_service_token_limits
 * @coversDefaultClass \Drupal\license_service_token_limits\EventSubscriber\QuotaEnforcementSubscriber
 *
 * Author: Jeremiah Buttler
 */
class QuotaEnforcementSubscriberTest extends TestCase {

  // --------------------------------------------------------------------------
  // Helpers
  // --------------------------------------------------------------------------

  /** Sample exceeded-info array returned by LevelQuotaEvaluator. */
  private const EXCEEDED_INFO = [
    'level'  => 'pro',
    'amount' => 50000,
    'used'   => 52000,
    'period' => 'month',
  ];

  /**
   * Builds a subscriber with controllable evaluator/account/messenger/logger.
   *
   * @param array|null $exceededInfo  What getExceededInfo() returns (NULL = under quota).
   * @param bool       $isAnonymous   Whether the account is anonymous.
   */
  private function buildSubscriber(
    ?array $exceededInfo = NULL,
    bool $isAnonymous = FALSE,
  ): array {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAnonymous')->willReturn($isAnonymous);
    $account->method('id')->willReturn(42);

    $evaluator = $this->createMock(LevelQuotaEvaluator::class);
    $evaluator->method('getExceededInfo')->willReturn($exceededInfo);

    $messenger = $this->createMock(MessengerInterface::class);
    $logger    = $this->createMock(LoggerInterface::class);

    $svc = new QuotaEnforcementSubscriber($evaluator, $account, $messenger, $logger);

    return [$svc, $evaluator, $account, $messenger, $logger];
  }

  /**
   * Builds a minimal event-like stdClass, optionally supporting setAborted().
   */
  private function buildEvent(bool $withAbort = FALSE): object {
    if ($withAbort) {
      return new class {
        public bool $aborted = FALSE;
        public function setAborted(bool $v): void { $this->aborted = $v; }
      };
    }
    return new \stdClass();
  }

  // --------------------------------------------------------------------------
  // getSubscribedEvents()
  // --------------------------------------------------------------------------

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEventsReturnsArrayOrEmpty(): void {
    $events = QuotaEnforcementSubscriber::getSubscribedEvents();
    // Whether or not the AI module is present, the result must be an array.
    $this->assertIsArray($events);
  }

  // --------------------------------------------------------------------------
  // Under-quota: no action
  // --------------------------------------------------------------------------

  /**
   * @covers ::onPreGenerate
   */
  public function testUnderQuotaPassesThrough(): void {
    [$svc, , , $messenger, $logger] = $this->buildSubscriber(exceededInfo: NULL);

    $messenger->expects($this->never())->method('addWarning');
    $logger->expects($this->never())->method('notice');

    $event = $this->buildEvent();
    $svc->onPreGenerate($event);
  }

  // --------------------------------------------------------------------------
  // Over-quota: setAborted path
  // --------------------------------------------------------------------------

  /**
   * @covers ::onPreGenerate
   */
  public function testOverQuotaWithSetAbortedCallsAbortedTrue(): void {
    [$svc, , , $messenger] = $this->buildSubscriber(exceededInfo: self::EXCEEDED_INFO);
    $messenger->expects($this->once())->method('addWarning');

    $event = $this->buildEvent(withAbort: TRUE);
    $svc->onPreGenerate($event);

    $this->assertTrue($event->aborted, 'setAborted(TRUE) must be called when the event supports it.');
  }

  /**
   * @covers ::onPreGenerate
   */
  public function testOverQuotaWithSetAbortedDoesNotThrow(): void {
    [$svc] = $this->buildSubscriber(exceededInfo: self::EXCEEDED_INFO);

    $event = $this->buildEvent(withAbort: TRUE);

    // Must not throw when setAborted path is taken.
    $this->expectNotToPerformAssertions();
    $svc->onPreGenerate($event);
  }

  // --------------------------------------------------------------------------
  // Over-quota: exception-throw path (no setAborted)
  // --------------------------------------------------------------------------

  /**
   * @covers ::onPreGenerate
   */
  public function testOverQuotaWithoutSetAbortedThrowsException(): void {
    [$svc, , , $messenger] = $this->buildSubscriber(exceededInfo: self::EXCEEDED_INFO);
    $messenger->expects($this->once())->method('addWarning');

    $event = $this->buildEvent(withAbort: FALSE);

    $this->expectException(TokenLimitExceededException::class);
    $svc->onPreGenerate($event);
  }

  /**
   * @covers ::onPreGenerate
   */
  public function testThrownExceptionCarriesQuotaDetails(): void {
    [$svc] = $this->buildSubscriber(exceededInfo: self::EXCEEDED_INFO);
    $event = $this->buildEvent(withAbort: FALSE);

    try {
      $svc->onPreGenerate($event);
      $this->fail('Expected TokenLimitExceededException was not thrown.');
    }
    catch (TokenLimitExceededException $e) {
      $this->assertSame('pro',   $e->getLimitLabel());
      $this->assertSame(52000,   $e->getUsed());
      $this->assertSame(50000,   $e->getMax());
      $this->assertSame('month', $e->getPeriod());
    }
  }

  // --------------------------------------------------------------------------
  // Over-quota: logger notice is always emitted
  // --------------------------------------------------------------------------

  /**
   * @covers ::onPreGenerate
   */
  public function testOverQuotaLogsNotice(): void {
    [$svc, , , , $logger] = $this->buildSubscriber(exceededInfo: self::EXCEEDED_INFO);
    $logger->expects($this->once())->method('notice');

    $event = $this->buildEvent(withAbort: TRUE);
    $svc->onPreGenerate($event);
  }

  // --------------------------------------------------------------------------
  // Unexpected internal error is swallowed and logged
  // --------------------------------------------------------------------------

  /**
   * @covers ::onPreGenerate
   */
  public function testUnexpectedEvaluatorErrorIsSwallowedNotRethrown(): void {
    $account   = $this->createMock(AccountInterface::class);
    $account->method('isAnonymous')->willReturn(FALSE);
    $account->method('id')->willReturn(42);

    $evaluator = $this->createMock(LevelQuotaEvaluator::class);
    $evaluator->method('getExceededInfo')->willThrowException(new \RuntimeException('DB failure'));

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->never())->method('addWarning');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $svc   = new QuotaEnforcementSubscriber($evaluator, $account, $messenger, $logger);
    $event = $this->buildEvent();

    // Must not throw — unexpected errors must never silently block AI calls.
    $svc->onPreGenerate($event);
  }

  /**
   * @covers ::onPreGenerate
   */
  public function testUnexpectedErrorDoesNotSetAbortedOnEvent(): void {
    $account   = $this->createMock(AccountInterface::class);
    $account->method('isAnonymous')->willReturn(FALSE);
    $account->method('id')->willReturn(42);

    $evaluator = $this->createMock(LevelQuotaEvaluator::class);
    $evaluator->method('getExceededInfo')->willThrowException(new \RuntimeException('oops'));

    $svc   = new QuotaEnforcementSubscriber(
      $evaluator,
      $account,
      $this->createMock(MessengerInterface::class),
      $this->createMock(LoggerInterface::class),
    );

    $event = $this->buildEvent(withAbort: TRUE);
    $svc->onPreGenerate($event);

    $this->assertFalse($event->aborted, 'An unexpected error must not abort the AI call.');
  }

}
