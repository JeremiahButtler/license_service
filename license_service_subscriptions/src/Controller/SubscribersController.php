<?php

declare(strict_types=1);

namespace Drupal\license_service_subscriptions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the Subscribers report page.
 *
 * Route: /admin/config/license-service/subscriptions/subscribers
 * Permission: administer license subscriptions
 *
 * Author: Jeremiah Buttler.
 */
class SubscribersController extends ControllerBase {

  /**
   * Constructs a SubscribersController.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager (for loading user labels).
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   */
  public function __construct(
    protected readonly Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
    protected readonly DateFormatterInterface $dateFormatter,
  ) {
    // $entityTypeManager is declared non-readonly on ControllerBase; assign
    // rather than re-promoting to avoid a PHP fatal on readonly redeclaration.
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Renders the subscribers report table.
   *
   * @return array
   *   Render array.
   */
  public function report(): array {
    $build = [];

    // ── State filter (defaults to active + payment_method_failing) ───────────
    // @todo Phase 5: add a real exposed filter form. For now, show all rows.
    $rows = $this->database->select('license_service_subscriptions_state', 's')
      ->fields('s', [
        'uid',
        'plan_id',
        'commerce_subscription_id',
        'state',
        'granted_by_action',
        'effective_from',
        'effective_until',
        'payment_failed_since',
        'last_reminder_sent_at',
        'created',
      ])
      ->orderBy('state')
      ->orderBy('uid')
      ->execute()
      ->fetchAll();

    if (empty($rows)) {
      $build['empty'] = [
        '#markup' => '<p>' . $this->t('No subscriber records found.') . '</p>',
      ];
      return $build;
    }

    // Pre-load all user names in one query.
    $uids = array_unique(array_column((array) $rows, 'uid'));
    $users = $this->entityTypeManager->getStorage('user')->loadMultiple($uids);

    $tableRows = [];
    foreach ($rows as $row) {
      $uid  = (int) $row->uid;
      $user = $users[$uid] ?? NULL;
      $userName = $user ? $user->getDisplayName() : $this->t('(uid @uid)', ['@uid' => $uid]);

      $effectiveUntil = $row->effective_until
        ? $this->dateFormatter->format((int) $row->effective_until, 'short')
        : $this->t('Ongoing');

      $lastReminder = $row->last_reminder_sent_at
        ? $this->dateFormatter->format((int) $row->last_reminder_sent_at, 'short')
        : $this->t('Never');

      $paymentFailed = $row->payment_failed_since
        ? $this->dateFormatter->format((int) $row->payment_failed_since, 'short')
        : '—';

      // State badge.
      $stateBadge = $this->renderStateBadge((string) $row->state);

      $tableRows[] = [
        ['data' => ['#markup' => '<strong>' . htmlspecialchars($userName) . '</strong><br><small>' . $uid . '</small>']],
        ['data' => ['#plain_text' => (string) $row->plan_id]],
        ['data' => $stateBadge],
        ['data' => ['#plain_text' => (string) $row->commerce_subscription_id]],
        ['data' => ['#plain_text' => (string) $row->granted_by_action]],
        ['data' => ['#plain_text' => $this->dateFormatter->format((int) $row->effective_from, 'short')]],
        ['data' => ['#markup' => $effectiveUntil]],
        ['data' => ['#markup' => $paymentFailed]],
        ['data' => ['#markup' => $lastReminder]],
      ];
    }

    $build['table'] = [
      '#type'   => 'table',
      '#header' => [
        $this->t('User'),
        $this->t('Plan'),
        $this->t('State'),
        $this->t('Commerce sub ID'),
        $this->t('Granted by'),
        $this->t('Active from'),
        $this->t('Active until'),
        $this->t('Payment failed'),
        $this->t('Last reminder'),
      ],
      '#rows'  => $tableRows,
      '#empty' => $this->t('No subscriber records found.'),
      '#attributes' => ['class' => ['license-service-subscribers-table']],
    ];

    $build['summary'] = [
      '#markup' => '<p>' . $this->t('Total records: @count', ['@count' => count($rows)]) . '</p>',
    ];

    return $build;
  }

  // --------------------------------------------------------------------------
  // Private helpers
  // --------------------------------------------------------------------------

  /**
   * Returns a render array badge for the given subscription state.
   *
   * @param string $state
   *   One of: active, paused, migrating, payment_method_failing, canceled.
   *
   * @return array
   *   Render array.
   */
  protected function renderStateBadge(string $state): array {
    $map = [
      'active'                  => ['class' => 'state-active',    'label' => $this->t('Active')],
      'paused'                  => ['class' => 'state-paused',    'label' => $this->t('Paused')],
      'migrating'               => ['class' => 'state-migrating', 'label' => $this->t('Migrating')],
      'payment_method_failing'  => ['class' => 'state-failing',   'label' => $this->t('Payment failing')],
      'canceled'                => ['class' => 'state-canceled',  'label' => $this->t('Canceled')],
    ];

    $info = $map[$state] ?? ['class' => 'state-unknown', 'label' => $state];

    return [
      '#markup' => '<span class="lss-state ' . htmlspecialchars($info['class']) . '">'
        . htmlspecialchars($info['label'])
        . '</span>',
    ];
  }

}
