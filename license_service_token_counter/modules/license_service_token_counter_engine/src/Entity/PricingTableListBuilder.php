<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter_engine\Entity;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the admin list of cost pricing tables.
 *
 * Prepends a disclaimer about rate accuracy and a "Seed defaults" action so
 * administrators can pre-populate tables for their enabled AI providers.
 * Also shows the estimated spend per provider from recorded usage data so
 * admins can quickly confirm which pricing tables are seeing real traffic.
 *
 * Author: Jeremiah Buttler.
 */
final class PricingTableListBuilder extends ConfigEntityListBuilder {

  /**
   * Per-provider spend map populated before rows are rendered.
   *
   * @var array<string, array{amount: float, currency: string}>
   */
  private array $providerSpend = [];

  /**
   * Constructs the list builder.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    private readonly Connection $database,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('database'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label']    = $this->t('Label');
    $header['provider'] = $this->t('Provider');
    $header['unit']     = $this->t('Tokens per unit');
    $header['rows']     = $this->t('Rate rows');
    $header['spend']    = $this->t('Est. spend (all time)');
    $header['status']   = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof PricingTableInterface);

    $provider_key = $entity->getProvider();
    $spend_data   = $this->providerSpend[$provider_key] ?? NULL;

    if ($spend_data !== NULL && $spend_data['amount'] > 0) {
      $spend_display = number_format($spend_data['amount'], 4);
      if ($spend_data['currency'] !== '') {
        $spend_display .= ' ' . $spend_data['currency'];
      }
    }
    else {
      $spend_display = '—';
    }

    $row['label']    = $entity->label();
    $row['provider'] = $entity->getProvider() === PricingTableInterface::PROVIDER_WILDCARD
      ? $this->t('* (fallback for any provider)')
      : $entity->getProvider();
    $row['unit']     = number_format($entity->getUnit());
    $row['rows']     = count($entity->getRates());
    $row['spend']    = $spend_display;
    $row['status']   = $entity->status() ? $this->t('Enabled') : $this->t('Disabled');

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity): array {
    $ops = parent::getDefaultOperations($entity);

    // Add a Duplicate operation.
    if ($entity->access('update') && $entity->hasLinkTemplate('duplicate-form')) {
      $ops['duplicate'] = [
        'title'  => $this->t('Duplicate'),
        'weight' => 15,
        'url'    => $entity->toUrl('duplicate-form'),
      ];
    }

    // Alphabetical order: delete, duplicate, edit.
    ksort($ops);
    return $ops;
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    // Pre-load spend totals so buildRow() can look them up without N+1 queries.
    $this->providerSpend = $this->loadProviderSpend();

    $build = parent::render();

    // Disclaimer: rates drift — administrators must verify regularly.
    $build['disclaimer'] = [
      '#type'       => 'container',
      '#weight'     => -10,
      '#attributes' => ['class' => ['messages', 'messages--warning']],
      'message'     => [
        '#markup' => $this->t(
          'Cost estimates are based on the rates you configure here. AI providers change their model offerings and prices frequently — these figures <strong>may be inaccurate</strong> and should be verified against each provider\'s current pricing page on a regular basis.'
        ),
      ],
    ];

    // "Seed defaults for enabled providers" action link.
    $build['seed_action'] = [
      '#weight'     => -5,
      '#type'       => 'container',
      '#attributes' => ['class' => ['action-links']],
      'link'        => [
        '#type'       => 'link',
        '#title'      => $this->t('Seed defaults for enabled providers'),
        '#url'        => Url::fromRoute('license_service_token_counter_engine.pricing_seed'),
        '#attributes' => ['class' => ['button', 'button--action', 'button--secondary']],
      ],
    ];

    return $build;
  }

  /**
   * Queries the license_service_token_usage table for per-provider estimated spend totals.
   *
   * Per-provider estimated spend on pricing admin page. Author: Jeremiah Buttler.
   *
   * @return array<string, array{amount: float, currency: string}>
   *   Keyed by provider_id.
   */
  private function loadProviderSpend(): array {
    $query = $this->database->select('license_service_token_usage', 'u');
    $query->addField('u', 'provider_id');
    $query->addExpression('SUM(u.estimated_cost)', 'spend');
    $query->addExpression('MAX(u.currency)', 'currency');
    $query->condition('u.cost_status', 'computed');
    $query->groupBy('u.provider_id');

    $result = [];
    foreach ($query->execute() as $row) {
      $result[(string) $row->provider_id] = [
        'amount'   => (float) ($row->spend ?? 0),
        'currency' => (string) ($row->currency ?? ''),
      ];
    }
    return $result;
  }

}
