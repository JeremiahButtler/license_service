<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\EventSubscriber;

use Drupal\ai\Event\PostStreamingResponseEvent;
use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\license_service_token_counter\Cost\CostCalculatorInterface;
use Drupal\license_service_token_counter\Service\TokenUsageExtractor;
use Drupal\license_service_token_counter\Service\UsageRecorder;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Captures AI token usage from the AI module's response events.
 *
 * Token capture runs independently of licensing: while capture is enabled in
 * settings, usage is recorded for every matching AI call. Cost is supplied by
 * the "license_service_token_counter.cost" service, which the licensed Cost Engine replaces
 * with a real calculator; without that engine (or an active license) cost is
 * recorded as "locked" while token counts are still captured. The License Module
 * integration lives on (see LicenseBridge, the usage report and the status
 * report); it simply no longer gates capture.
 *
 * Author: Jeremiah Buttler.
 */
final class UsageEventSubscriber implements EventSubscriberInterface {

  /**
   * Request-scoped guard against double-recording the same AI call.
   *
   * @var array<string,bool>
   */
  private array $seen = [];

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    private readonly TokenUsageExtractor $extractor,
    private readonly UsageRecorder $recorder,
    private readonly CostCalculatorInterface $cost,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountInterface $currentUser,
    private readonly RouteMatchInterface $routeMatch,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [];

    if (class_exists('Drupal\ai\Event\PostGenerateResponseEvent')) {
      $events[PostGenerateResponseEvent::EVENT_NAME] = 'onResponse';
    }
    if (class_exists('Drupal\ai\Event\PostStreamingResponseEvent')) {
      $events[PostStreamingResponseEvent::EVENT_NAME] = 'onResponse';
    }

    return $events;
  }

  /**
   * Records token usage for a completed AI response.
   *
   * @param object $event
   *   A PostGenerateResponseEvent or PostStreamingResponseEvent.
   */
  public function onResponse(object $event): void {
    try {
      $config = $this->configFactory->get('license_service_token_counter.settings');
      if (!$config->get('capture_enabled')) {
        return;
      }

      // Skip anonymous visitors when the setting is off.
      if (!($config->get('record_anonymous') ?? TRUE) && (int) $this->currentUser->id() === 0) {
        return;
      }

      $operationType = $this->callMethod($event, 'getOperationType', '');
      if (!$this->operationCaptured($operationType, (array) $config->get('captured_operations'))) {
        return;
      }

      $threadId = $this->callMethod($event, 'getRequestThreadId', '');
      if ($threadId !== '' && isset($this->seen[$threadId])) {
        return;
      }

      $output = $this->callMethod($event, 'getOutput', NULL);
      $providerId = $this->callMethod($event, 'getProviderId', '');
      $modelId = $this->callMethod($event, 'getModelId', '');
      $tags = $this->callMethod($event, 'getTags', []);

      $usage = $this->extractor->extract(
        is_object($output) ? $output : NULL,
        is_string($providerId) ? $providerId : '',
        is_string($modelId) ? $modelId : '',
        is_string($operationType) ? $operationType : '',
      );

      // Skip calls where no provider reported any token counts.
      if (!$usage->hasTokens()) {
        return;
      }

      $cost = $this->cost->calculate($usage);

      $this->recorder->record(
        $usage,
        $cost,
        (int) $this->currentUser->id(),
        is_string($threadId) ? $threadId : '',
        is_array($tags) ? $tags : [],
        $this->routeMatch->getRouteName() ?? '',
      );

      if ($threadId !== '') {
        $this->seen[$threadId] = TRUE;
      }
    }
    catch (\Throwable $e) {
      // Never let usage capture interfere with the AI response itself.
      $this->logger->error('Failed to record AI token usage: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Whether the operation type should be captured per settings.
   */
  private function operationCaptured(mixed $operationType, array $captured): bool {
    // An empty allow-list means "capture everything".
    if ($captured === []) {
      return TRUE;
    }
    return is_string($operationType) && in_array($operationType, $captured, TRUE);
  }

  /**
   * Calls a getter on the event if it exists, else returns a default.
   */
  private function callMethod(object $event, string $method, mixed $default): mixed {
    if (!method_exists($event, $method)) {
      return $default;
    }
    try {
      return $event->{$method}();
    }
    catch (\Throwable) {
      return $default;
    }
  }

}
