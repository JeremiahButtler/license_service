<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\license_service\Period\PeriodManager;
use Drupal\license_service_token_counter\Service\UsageAggregator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays the site-wide AI token usage total.
 *
 * Place this block anywhere in the block layout to show administrators and
 * editors how many tokens the entire site has consumed in a chosen period.
 *
 * Author: Jeremiah Buttler.
 *
 * @Block(
 *   id = "license_service_token_counter_site_usage",
 *   admin_label = @Translation("AI token usage — site total"),
 *   category = @Translation("License Service Token Counter"),
 * )
 */
final class SiteUsageBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the block plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly UsageAggregator $aggregator,
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
      $container->get('license_service_token_counter.usage_aggregator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['period' => 'lifetime'] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);
    $form['period'] = [
      '#type' => 'select',
      '#title' => $this->t('Period'),
      '#options' => PeriodManager::labels(),
      '#default_value' => $this->configuration['period'],
      '#description' => $this->t('Display token usage for this time period.'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    parent::blockSubmit($form, $form_state);
    $this->configuration['period'] = $form_state->getValue('period');
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'view ai token usage reports')
      ->orIf(AccessResult::allowedIfHasPermission($account, 'administer ai token counter'))
      ->cachePerPermissions();
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $period    = $this->configuration['period'] ?? 'lifetime';
    $tokens    = $this->aggregator->getSiteTokens($period);
    $breakdown = $this->aggregator->getBreakdown(NULL, $period);
    $cost      = $this->aggregator->getCostSummary(NULL, $period);
    $labels    = PeriodManager::labels();
    $label     = $labels[$period] ?? $period;

    return [
      '#theme'     => 'license_service_token_counter_usage_block',
      '#tokens'    => $tokens,
      '#breakdown' => $breakdown,
      '#cost'      => $cost,
      '#scope_label' => $this->t('Site total'),
      '#period_label' => $label,
      '#scope' => 'site',
      '#uid' => NULL,
      '#period' => $period,
      '#attached' => [
        'library' => ['license_service_token_counter/usage'],
        'drupalSettings' => [
          'aiTokenCounter' => [
            'usageEndpoint' => Url::fromRoute('license_service_token_counter.usage_api')->toString(),
            'pollInterval'  => 30000,
          ],
        ],
      ],
      '#cache' => [
        // Always fresh — token counts change with every AI call.
        'max-age' => 0,
      ],
    ];
  }

}
