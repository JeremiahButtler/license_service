<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\Plugin\Block;

use Drupal\user\UserInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\license_service\Period\PeriodManager;
use Drupal\license_service_token_counter\Service\UsageAggregator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays the AI token usage for the user whose account page is being viewed.
 *
 * Place on user account pages (/user/{user}) to give administrators or the
 * users themselves a quick view of how many tokens they have consumed.
 *
 * This block reads the `user` route parameter when placed on the user profile
 * page; it renders nothing on routes that do not have a user parameter.
 *
 * Author: Jeremiah Buttler.
 *
 * @Block(
 *   id = "license_service_token_counter_user_usage",
 *   admin_label = @Translation("AI token usage — account page"),
 *   category = @Translation("License Service Token Counter"),
 *   context_definitions = {
 *     "user" = @ContextDefinition(
 *       "entity:user",
 *       label = @Translation("User"),
 *       required = FALSE,
 *     )
 *   }
 * )
 */
final class UserUsageBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the block plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly UsageAggregator $aggregator,
    private readonly RouteMatchInterface $routeMatch,
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
      $container->get('current_route_match'),
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
    // Admins always see the block; users can see it on their own profile page.
    $has_admin   = $account->hasPermission('view ai token usage reports')
                || $account->hasPermission('administer ai token counter');
    $has_own     = $account->hasPermission('view own ai token usage');
    $viewed_user = $this->getViewedUser();

    if ($has_admin) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    if ($has_own && $viewed_user !== NULL && (int) $viewed_user->id() === (int) $account->id()) {
      return AccessResult::allowed()->cachePerPermissions()->cachePerUser();
    }
    return AccessResult::forbidden()->cachePerPermissions()->cachePerUser();
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $viewed_user = $this->getViewedUser();
    if ($viewed_user === NULL) {
      // Not on a user profile page; hide silently.
      return [];
    }

    $period    = $this->configuration['period'] ?? 'lifetime';
    $uid       = (int) $viewed_user->id();
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
      '#scope_label' => $this->t("@name's usage", ['@name' => $viewed_user->getDisplayName()]),
      '#period_label' => $label,
      '#scope' => 'user',
      '#uid' => (int) $viewed_user->id(),
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
        'tags' => ['user:' . $viewed_user->id()],
      ],
    ];
  }

  /**
   * Returns the user entity from the current route (user profile routes).
   *
   * Tries the route context first; falls back to the route `user` parameter.
   *
   * @return \Drupal\user\UserInterface|null
   *   The viewed user, or NULL if the context cannot be resolved.
   */
  private function getViewedUser(): ?UserInterface {
    // Prefer the context value injected by Drupal's block context system.
    try {
      $user = $this->getContextValue('user');
      if ($user instanceof UserInterface) {
        return $user;
      }
    }
    catch (\Exception) {
      // Context not available (e.g. admin block layout preview); fall through.
    }

    // Fallback: pull the user entity from the route parameters.
    $user = $this->routeMatch->getParameter('user');
    if ($user instanceof UserInterface) {
      return $user;
    }

    return NULL;
  }

}
