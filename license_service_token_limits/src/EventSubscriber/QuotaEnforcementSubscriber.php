<?php

declare(strict_types=1);

namespace Drupal\license_service_token_limits\EventSubscriber;

use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\license_service_token_counter\Exception\TokenLimitExceededException;
use Drupal\license_service_token_limits\Service\LevelQuotaEvaluator;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Blocks AI calls when the current user has exceeded their level quota.
 *
 * Fires on PreGenerateResponseEvent at priority 90 — after the token counter's
 * per-rule enforcement check at priority 100 — so explicit rule limits always
 * take precedence. Uses the same abort contract as EnforcementSubscriber:
 * setAborted() when available, TokenLimitExceededException otherwise.
 *
 * Author: Jeremiah Buttler.
 */
final class QuotaEnforcementSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Constructs a QuotaEnforcementSubscriber.
   *
   * @param \Drupal\license_service_token_limits\Service\LevelQuotaEvaluator $evaluator
   *   The per-level quota evaluation service.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The currently authenticated user.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   For surfacing a user-visible over-quota warning.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module log channel.
   */
  public function __construct(
    private readonly LevelQuotaEvaluator $evaluator,
    private readonly AccountInterface $currentUser,
    private readonly MessengerInterface $messenger,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   *
   * Subscribes to PreGenerateResponseEvent only when that class exists, so the
   * subscriber degrades gracefully on AI module versions that predate it.
   */
  public static function getSubscribedEvents(): array {
    $events = [];
    if (class_exists('Drupal\ai\Event\PreGenerateResponseEvent')) {
      // Priority 90: fires after the token-counter per-rule check at 100.
      $events[PreGenerateResponseEvent::EVENT_NAME] = ['onPreGenerate', 90];
    }
    return $events;
  }

  /**
   * Checks per-level quotas before an AI call and aborts if a quota is exceeded.
   *
   * @param object $event
   *   The PreGenerateResponseEvent (typed loosely so the class need not exist
   *   at module load time — safe because the use import is never resolved when
   *   class_exists() returned FALSE in getSubscribedEvents()).
   *
   * @throws \Drupal\license_service_token_counter\Exception\TokenLimitExceededException
   *   When enforcement is active and the account's level quota is exceeded.
   */
  public function onPreGenerate(object $event): void {
    try {
      $info = $this->evaluator->getExceededInfo($this->currentUser);
      if ($info === NULL) {
        return;
      }

      $this->logger->notice(
        'AI call blocked for user @uid: level quota for "@level" exceeded (@used / @max tokens this @period).',
        [
          '@uid'    => $this->currentUser->id(),
          '@level'  => $info['level'],
          '@used'   => number_format($info['used']),
          '@max'    => number_format($info['amount']),
          '@period' => $info['period'],
        ]
      );

      $message = $this->t(
        'You have reached your AI token quota for the @level license level (@used of @max tokens this @period).',
        [
          '@level'  => $info['level'],
          '@used'   => number_format($info['used']),
          '@max'    => number_format($info['amount']),
          '@period' => $info['period'],
        ]
      );

      // If the event exposes a native abort API, prefer it so the caller
      // receives an empty/error response rather than a propagating exception.
      if (method_exists($event, 'setAborted')) {
        $event->setAborted(TRUE);
        $this->messenger->addWarning($message);
        return;
      }

      // No native abort path: throw a typed exception to stop the HTTP request.
      $this->messenger->addWarning($message);
      throw new TokenLimitExceededException(
        $info['level'],
        $info['used'],
        $info['amount'],
        $info['period'],
      );
    }
    catch (TokenLimitExceededException $e) {
      // Re-throw so the AI call is blocked.
      throw $e;
    }
    catch (\Throwable $e) {
      // Never silently block AI calls on an unexpected internal error.
      $this->logger->error(
        'QuotaEnforcementSubscriber: unexpected error during quota check — @message',
        ['@message' => $e->getMessage()]
      );
    }
  }

}
