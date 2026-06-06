<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\EventSubscriber;

use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\license_service_token_counter\Entity\TokenLimitInterface;
use Drupal\license_service_token_counter\Exception\TokenLimitExceededException;
use Drupal\license_service_token_counter\Service\LimitEvaluator;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Blocks AI calls when the current user has exceeded a token limit.
 *
 * Enforcement runs on PreGenerateResponseEvent (before the API call) when the
 * drupal/ai module provides that event class. If the event exposes a
 * setAborted() method, that is called; otherwise a TokenLimitExceededException
 * is thrown, which prevents the underlying HTTP request from being made and
 * surfaces as an error to the caller.
 *
 * Accounts holding the 'bypass ai token usage limits' permission are never
 * blocked. When capture is disabled in settings, enforcement is also skipped.
 *
 * Author: Jeremiah Buttler.
 */
final class EnforcementSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Constructs an EnforcementSubscriber.
   *
   * @param \Drupal\license_service_token_counter\Service\LimitEvaluator $limitEvaluator
   *   The limit evaluation service.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The currently authenticated user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory, for reading capture_enabled.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   For surfacing a user-visible over-limit warning.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module log channel.
   */
  public function __construct(
    private readonly LimitEvaluator $limitEvaluator,
    private readonly AccountInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MessengerInterface $messenger,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   *
   * Subscribes to PreGenerateResponseEvent only when that class exists in the
   * installed drupal/ai module, so the subscriber degrades safely on older
   * versions that lack the pre-event.
   */
  public static function getSubscribedEvents(): array {
    $events = [];
    if (class_exists('Drupal\ai\Event\PreGenerateResponseEvent')) {
      // High priority (100) so enforcement fires before other pre-event work.
      $events[PreGenerateResponseEvent::EVENT_NAME] = ['onPreGenerate', 100];
    }
    return $events;
  }

  /**
   * Checks limits before an AI call and aborts if any are exceeded.
   *
   * @param object $event
   *   The PreGenerateResponseEvent (typed loosely to avoid a hard import that
   *   would fail when the class does not exist).
   *
   * @throws \Drupal\license_service_token_counter\Exception\TokenLimitExceededException
   *   When hard enforcement is active and a limit is exceeded.
   */
  public function onPreGenerate(object $event): void {
    try {
      // Respect the capture_enabled toggle — no limits when capture is off.
      if (!$this->configFactory->get('license_service_token_counter.settings')->get('capture_enabled')) {
        return;
      }

      $exceeded = $this->limitEvaluator->getExceededLimits($this->currentUser);
      if (empty($exceeded)) {
        return;
      }

      // Take the first exceeded rule; log it and surface a message.
      $limit = reset($exceeded);

      $used = $this->limitEvaluator->getCurrentUsage($this->currentUser, $limit);
      $max  = $limit->getAmount();

      $this->logger->notice(
        'AI call blocked for user @uid: token limit "@label" exceeded (@used / @max tokens this @period).',
        [
          '@uid'    => $this->currentUser->id(),
          '@label'  => $limit->label(),
          '@used'   => number_format($used),
          '@max'    => number_format($max),
          '@period' => $limit->getPeriod(),
        ]
      );

      $message = $this->t(
        'You have reached your AI token limit (@used of @max tokens used this @period). Please try again later.',
        [
          '@used'   => number_format($used),
          '@max'    => number_format($max),
          '@period' => $limit->getPeriod(),
        ]
      );

      // If the event supports a native abort API, prefer it; the caller then
      // receives an empty/error response rather than an exception.
      if (method_exists($event, 'setAborted')) {
        $event->setAborted(TRUE);
        $this->messenger->addWarning($message);
        return;
      }

      // No native abort path: throw a typed exception. The AI module's
      // dispatch() call propagates this up, stopping the HTTP request.
      // Callers that want a nice UX should catch TokenLimitExceededException.
      $this->messenger->addWarning($message);
      throw new TokenLimitExceededException(
        (string) $limit->label(),
        $used,
        $max,
        $limit->getPeriod(),
      );
    }
    catch (TokenLimitExceededException $e) {
      // Re-throw so the AI call is blocked.
      throw $e;
    }
    catch (\Throwable $e) {
      // Any other error must never silently block AI calls.
      $this->logger->error(
        'EnforcementSubscriber: unexpected error during limit check — @message',
        ['@message' => $e->getMessage()]
      );
    }
  }

}
