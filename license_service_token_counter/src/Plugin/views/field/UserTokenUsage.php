<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\Plugin\views\field;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormStateInterface;
use Drupal\license_service\Period\PeriodManager;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A computed Views field showing the total AI tokens used by a user.
 *
 * Add this field to any View that is based on the Users (users_field_data)
 * table to show how many AI tokens each listed user has consumed. The period
 * (day / week / month / year / lifetime) is configured per field instance in
 * the Views field settings.
 *
 * The count is computed via a correlated SQL subquery so no extra join or
 * GROUP BY clause is added to the outer query, keeping the result set stable.
 *
 * Author: Jeremiah Buttler.
 *
 * @ViewsField("license_service_token_counter_user_tokens")
 */
final class UserTokenUsage extends FieldPluginBase {

  /**
   * Constructs a UserTokenUsage field plugin.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection (for table prefix resolution).
   * @param \Drupal\license_service\Period\PeriodManager $periodManager
   *   The canonical period service from license_service. Injected directly
   *   (concrete class, no interface) as a deliberate anti-extraction coupling:
   *   a copy without license_service cannot compute period start timestamps.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly Connection $connection,
    private readonly PeriodManager $periodManager,
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
      $container->get('database'),
      $container->get('license_service.period_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions(): array {
    $options = parent::defineOptions();
    $options['period'] = ['default' => 'lifetime'];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(mixed &$form, FormStateInterface $form_state): void {
    parent::buildOptionsForm($form, $form_state);
    $form['period'] = [
      '#type' => 'select',
      '#title' => $this->t('Period'),
      '#options' => PeriodManager::labels(),
      '#default_value' => $this->options['period'],
      '#description' => $this->t('Count tokens used within this time period. Calendar-aligned: day = since midnight, week = since Monday, month = since the 1st, year = since January 1.'),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Injects a correlated subquery expression so the Views outer query does not
   * need an extra join or GROUP BY that could affect other fields.
   */
  public function query(): void {
    $this->ensureMyTable();

    $period    = $this->options['period'] ?? 'lifetime';
    $since     = $this->periodManager->getStart($period);
    $outer_uid = $this->tableAlias . '.uid';
    // Resolve the DB table prefix (handles sites with a table-name prefix).
    $inner_table = $this->connection->prefixTables('{license_service_token_usage}');

    $sql = "(SELECT COALESCE(SUM(atu.total_tokens), 0) "
         . "FROM $inner_table atu "
         . "WHERE atu.uid = $outer_uid";

    if ($since > 0) {
      // $since is a Unix timestamp (integer); safe to embed directly.
      $sql .= " AND atu.created >= " . (int) $since;
    }

    $sql .= ')';

    $this->field_alias = $this->query->addField(
      NULL,
      $sql,
      'license_service_token_counter_tokens_' . $period
    );
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values): string {
    $tokens = (int) $this->getValue($values);
    return number_format($tokens);
  }

  /**
   * {@inheritdoc}
   */
  public function clickSortable(): bool {
    // The correlated subquery supports ORDER BY so click-sort works.
    return TRUE;
  }

}
