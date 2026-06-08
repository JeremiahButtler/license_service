<?php

declare(strict_types=1);

namespace Drupal\license_service_subscriptions\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\license_service_subscriptions\Service\TierMigrationService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes subscription saga operation queue items.
 *
 * Queue name: license_service_subscriptions_saga.
 *
 * Each item is an array with at minimum:
 *   - 'operation'              (string) — 'grant' | 'revoke' | 'suspend' |
 *                                         'resume' | 'mark_intent'
 *   - 'uid'                    (int)    — Drupal user ID
 *   - 'commerce_subscription_id' (int)  — Commerce subscription entity ID
 *   - 'event_key'              (string) — idempotency key
 *
 * Additional keys per operation:
 *   grant:         plan_id (string), effective_at (int), source_subscription_id (?int)
 *   revoke:        reason (string), effective_at (int)
 *   suspend:       reason (string)
 *   resume:        (no extra keys)
 *   mark_intent:   target_plan_id (?string), token (string)
 *
 * Failed items are re-queued automatically by Drupal's cron queue runner
 * (QueueWorkerBase throws → item is released back). The audit table's
 * event_key UNIQUE constraint ensures duplicate processing is a silent no-op.
 *
 * Author: Jeremiah Buttler.
 *
 * @QueueWorker(
 *   id = "license_service_subscriptions_saga",
 *   title = @Translation("License Service Subscription Saga"),
 *   cron = {"time" = 30}
 * )
 */
class SubscriptionSagaWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a SubscriptionSagaWorker.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\license_service_subscriptions\Service\TierMigrationService $migrationService
   *   The tier migration service.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly TierMigrationService $migrationService,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('license_service_subscriptions.migration'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Exception
   *   Re-throws on unrecoverable failure so Drupal cron re-queues the item.
   */
  public function processItem($data): void {
    $operation     = (string) ($data['operation'] ?? '');
    $uid           = (int) ($data['uid'] ?? 0);
    $commerceSubId = (int) ($data['commerce_subscription_id'] ?? 0);
    $eventKey      = (string) ($data['event_key'] ?? '');

    switch ($operation) {

      case 'grant':
        $this->migrationService->applyNewTier(
          $uid,
          (string) ($data['plan_id'] ?? ''),
          (int) ($data['effective_at'] ?? time()),
          $commerceSubId,
          isset($data['source_subscription_id']) ? (int) $data['source_subscription_id'] : NULL,
        );
        break;

      case 'revoke':
        $this->migrationService->revokeSubscription(
          $commerceSubId,
          (string) ($data['reason'] ?? 'unknown'),
          (int) ($data['effective_at'] ?? time()),
          $eventKey,
        );
        break;

      case 'suspend':
        $this->migrationService->suspendSubscription(
          $commerceSubId,
          (string) ($data['reason'] ?? ''),
        );
        break;

      case 'resume':
        $this->migrationService->resumeSubscription($commerceSubId);
        break;

      case 'mark_intent':
        $this->migrationService->markIntentToChange(
          $commerceSubId,
          isset($data['target_plan_id']) ? (string) $data['target_plan_id'] : NULL,
          (string) ($data['token'] ?? ''),
          $uid,
        );
        break;

      default:
        // Unknown operation — log and discard (do not re-queue).
        \Drupal::logger('license_service_subscriptions')->error(
          'SubscriptionSagaWorker: unknown operation "@op" in queue item; discarding.',
          ['@op' => $operation],
        );
    }
  }

}
