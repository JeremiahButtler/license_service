<?php

declare(strict_types=1);

namespace Drupal\license_service_token_limits\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\license_service\LicenseFeatureProviderInterface;
use Drupal\license_service\Period\PeriodManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin form for per-license-level AI token quotas.
 *
 * Renders a table of license levels (derived from the role→level config) with
 * a token amount and period for each, guarded by a master enabled toggle. The
 * license provider is consulted for the current level order so the table always
 * reflects what has been configured in the License Service role-levels form.
 *
 * Author: Jeremiah Buttler.
 */
final class LevelQuotaForm extends ConfigFormBase {

  /**
   * Config object name for this module's settings.
   */
  private const SETTINGS = 'license_service_token_limits.settings';

  /**
   * The license entitlement facade for resolving level order.
   */
  private LicenseFeatureProviderInterface $licenseProvider;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->licenseProvider = $container->get('license_service.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'license_service_token_limits_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config      = $this->config(self::SETTINGS);
    $savedQuotas = (array) ($config->get('quotas') ?? []);
    $levels      = $this->licenseProvider->getLevelOrder();

    $form['enabled'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Enforce per-level token quotas'),
      '#description'   => $this->t(
        'When enabled, AI calls are blocked once a user exceeds the token quota configured for their license level. Requires the license envelope to permit quotas.'
      ),
      '#default_value' => (bool) $config->get('enabled'),
    ];

    $form['quotas'] = [
      '#type'       => 'table',
      '#caption'    => $this->t('Token quota per license level'),
      '#header'     => [
        $this->t('Level'),
        $this->t('Token limit'),
        $this->t('Period'),
      ],
      '#empty'      => $this->t('No license levels are configured. Add levels in the <a href="/admin/config/license-service/role-levels">Role levels</a> form first.'),
    ];

    $periodOptions = PeriodManager::labels();

    foreach ($levels as $level) {
      $saved = (array) ($savedQuotas[$level] ?? []);

      $form['quotas'][$level]['level'] = [
        '#plain_text' => ucfirst($level),
      ];

      $form['quotas'][$level]['amount'] = [
        '#type'          => 'number',
        '#title'         => $this->t('Token limit for @level', ['@level' => $level]),
        '#title_display' => 'invisible',
        '#description'   => $this->t('0 = unlimited'),
        '#default_value' => (int) ($saved['amount'] ?? 0),
        '#min'           => 0,
      ];

      $form['quotas'][$level]['period'] = [
        '#type'          => 'select',
        '#title'         => $this->t('Period for @level', ['@level' => $level]),
        '#title_display' => 'invisible',
        '#options'       => $periodOptions,
        '#default_value' => (string) ($saved['period'] ?? 'month'),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $quotas = [];
    foreach ((array) $form_state->getValue('quotas') as $level => $row) {
      $quotas[(string) $level] = [
        'amount' => (int) ($row['amount'] ?? 0),
        'period' => (string) ($row['period'] ?? 'month'),
      ];
    }

    $this->config(self::SETTINGS)
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('quotas', $quotas)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
