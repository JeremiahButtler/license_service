<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\license_service_token_counter\Cost\CostResult;
use Drupal\license_service_token_counter\License\LicenseContextInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the AI token usage report.
 *
 * Author: Jeremiah Buttler.
 */
final class UsageReportController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly LicenseContextInterface $license,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
      $container->get('license_service_token_counter.license_bridge'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Returns the configured cost display precision (decimal places).
   */
  private function costPrecision(): int {
    $p = $this->config('license_service_token_counter.settings')->get('cost_display_precision');
    return ($p !== NULL) ? (int) $p : 4;
  }

  /**
   * Access callback: aggregate OR own-usage viewers may see the report.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf(
      $account->hasPermission('view ai token usage reports')
      || $account->hasPermission('view own ai token usage')
    )->cachePerPermissions();
  }

  /**
   * Builds the usage report page.
   */
  public function report(): array {
    $account = $this->currentUser();
    $own_only = !$account->hasPermission('view ai token usage reports')
      && $account->hasPermission('view own ai token usage');

    $build = [
      '#attached' => ['library' => ['license_service_token_counter/report']],
    ];

    $build['license'] = $this->licenseNotice();
    $build['summary'] = $this->summary($own_only ? (int) $account->id() : NULL);
    $build['records'] = $this->recordsTable($own_only ? (int) $account->id() : NULL);

    return $build;
  }

  /**
   * Builds the license status / warning banner.
   *
   * Token capture runs without a license, so standalone sites get no banner.
   * A notice appears only when the License Module is present but not granting
   * the feature (cost estimation locked), or to surface license warnings.
   */
  private function licenseNotice(): array {
    $status = $this->license->status();

    // Standalone: no License Module present. Capture runs normally and cost
    // simply shows as "Locked"; no banner is needed.
    if (!$status->isProviderPresent()) {
      return [];
    }

    // License present but not active — no cost-lock banner needed since cost
    // no longer requires a license. Only show warnings when the license is
    // active (expiry notices, etc.).
    if (!$status->isActive()) {
      return [];
    }

    $items = [];
    foreach ($status->getWarnings() as $warning) {
      $items[] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $warning,
        '#attributes' => ['class' => ['license-service-token-counter-warning']],
      ];
    }

    return $items;
  }

  /**
   * Builds the summary cards.
   */
  private function summary(?int $uid): array {
    $query = $this->database->select('license_service_token_usage', 'u');
    if ($uid !== NULL) {
      $query->condition('u.uid', $uid);
    }
    $query->addExpression('COUNT(*)', 'calls');
    $query->addExpression('COALESCE(SUM(u.total_tokens), 0)', 'tokens');
    $query->addExpression('COALESCE(SUM(u.input_tokens), 0)', 'input_tokens');
    $query->addExpression('COALESCE(SUM(u.output_tokens), 0)', 'output_tokens');
    $query->addExpression('COALESCE(SUM(u.estimated_cost), 0)', 'cost');
    $totals = $query->execute()->fetchAssoc() ?: [];

    $cards = [
      $this->card($this->t('AI calls'), number_format((float) ($totals['calls'] ?? 0))),
      $this->card($this->t('Total tokens'), number_format((float) ($totals['tokens'] ?? 0))),
      $this->card($this->t('Input tokens'), number_format((float) ($totals['input_tokens'] ?? 0))),
      $this->card($this->t('Output tokens'), number_format((float) ($totals['output_tokens'] ?? 0))),
      $this->card($this->t('Estimated cost'), number_format((float) ($totals['cost'] ?? 0), $this->costPrecision())),
    ];

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['license-service-token-counter-summary']],
      'cards' => $cards,
    ];
  }

  /**
   * Builds a single summary card render array.
   */
  private function card(string|object $label, string|object $value): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['license-service-token-counter-card']],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $label,
        '#attributes' => ['class' => ['license-service-token-counter-card__label']],
      ],
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $value,
        '#attributes' => ['class' => ['license-service-token-counter-card__value']],
      ],
    ];
  }

  /**
   * Builds the paged table of recent records.
   */
  private function recordsTable(?int $uid): array {
    $header = [
      ['data' => $this->t('When')],
      ['data' => $this->t('Provider')],
      ['data' => $this->t('Model')],
      ['data' => $this->t('Operation')],
      ['data' => $this->t('Input')],
      ['data' => $this->t('Output')],
      ['data' => $this->t('Total')],
      ['data' => $this->t('Cost')],
    ];

    $query = $this->database->select('license_service_token_usage', 'u')
      ->extend(PagerSelectExtender::class)
      ->limit(50);
    $query->fields('u', [
      'created', 'provider_id', 'model_id', 'operation_type',
      'input_tokens', 'output_tokens', 'total_tokens',
      'estimated_cost', 'currency', 'cost_status',
    ]);
    if ($uid !== NULL) {
      $query->condition('u.uid', $uid);
    }
    $query->orderBy('u.created', 'DESC');

    $rows = [];
    foreach ($query->execute() as $record) {
      $rows[] = [
        $this->dateFormatter->format((int) $record->created, 'short'),
        $record->provider_id,
        $record->model_id,
        $record->operation_type,
        number_format((float) $record->input_tokens),
        number_format((float) $record->output_tokens),
        number_format((float) $record->total_tokens),
        ['data' => $this->costCell($record)],
      ];
    }

    return [
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No AI token usage has been recorded yet.'),
      ],
      'pager' => ['#type' => 'pager'],
    ];
  }

  /**
   * Builds the cost cell as a status badge or a formatted amount.
   */
  private function costCell(object $record): array {
    if ($record->cost_status === CostResult::STATUS_COMPUTED && $record->estimated_cost !== NULL) {
      $amount = number_format((float) $record->estimated_cost, $this->costPrecision());
      $currency = $record->currency !== '' ? ' ' . $record->currency : '';
      return [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $amount . $currency,
        '#attributes' => [
          'class' => [
            'license-service-token-counter-badge',
            'license-service-token-counter-badge--computed',
          ],
        ],
      ];
    }

    $label = $record->cost_status === CostResult::STATUS_UNPRICED
      ? $this->t('No price')
      : $this->t('Locked');
    $modifier = $record->cost_status === CostResult::STATUS_UNPRICED ? 'unpriced' : 'locked';

    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $label,
      '#attributes' => [
        'class' => [
          'license-service-token-counter-badge',
          'license-service-token-counter-badge--' . $modifier,
        ],
      ],
    ];
  }

}
