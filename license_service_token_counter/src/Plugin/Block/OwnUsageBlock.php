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
 * Displays the logged-in user's own AI token usage.
 *
 * Place in the sidebar, header, or user dashboard to give each authenticated
 * user visibility into how many tokens they have consumed in the chosen period.
 *
 * Author: Jeremiah Buttler.
 *
 * @Block(
 *   id = "license_service_token_counter_own_usage",
 *   admin_label = @Translation("AI token usage — my usage"),
 *   category = @Translation("License Service Token Counter"),
 * )
 */
final class OwnUsageBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the block plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly UsageAggregator $aggregator,
    private readonly AccountInterface $currentUser,
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
      $container->get('current_user'),
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
    return AccessResult::allowedIfHasPermission($account, 'view own ai token usage')
      ->cachePerPermissions()
      ->cachePerUser();
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    // Hide from anonymous users — they have no usage to show.
    if ($this->currentUser->isAnonymous()) {
      return [];
    }

    $period    = $this->configuration['period'] ?? 'lifetime';
    $uid       = (int) $this->currentUser->id();
    $tokens    = $this->aggregator->getUserTokens($uid, $period);
    $breakdown = $this->aggregator->getBreakdown($uid, $period);
    $cost      = $this->aggregator->getCostSummary($uid, $period);
    $labels    = PeriodManager::labels();
    $label     = $labels[$period] ?? $period;

    return [
      '#theme'     => 'license_service_token_counter_usage_block',
      '#tokens'    => $tokens,
      '#breakdown' => $breakdown,
      '#cost'      => $cost,
      '#scope_label' => $this->t('My usage'),
      '#period_label' => $label,
      '#scope' => 'own',
      '#uid' => (int) $this->currentUser->id(),
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
        'max-age' => 0,
        'contexts' => ['user'],
      ],
    ];
  }

}
