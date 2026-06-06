<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\license_service_token_counter\Service\CurrencyHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Capture, retention, and display settings for License Service Token Counter.
 *
 * Author: Jeremiah Buttler.
 */
final class SettingsForm extends ConfigFormBase {

  private const SETTINGS = 'license_service_token_counter.settings';

  /**
   * Provides currency options and the site default currency.
   */
  private CurrencyHelper $currencyHelper;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance                 = parent::create($container);
    $instance->currencyHelper = $container->get('license_service_token_counter.currency_helper');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'license_service_token_counter_settings';
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
    $config = $this->config(self::SETTINGS);

    // ── Quick links to related pages ────────────────────────────────────────
    $links = [];
    try {
      $links[] = Link::fromTextAndUrl(
        $this->t('AI token usage report'),
        Url::fromRoute('license_service_token_counter.report')
      )->toString();
    }
    catch (\Exception) {
    }

    try {
      $links[] = Link::fromTextAndUrl(
        $this->t('Usage by user'),
        Url::fromRoute('view.license_service_token_usage_by_user.page_1')
      )->toString();
    }
    catch (\Exception) {
    }

    try {
      $links[] = Link::fromTextAndUrl(
        $this->t('Token limits'),
        Url::fromRoute('entity.token_limit.collection')
      )->toString();
    }
    catch (\Exception) {
    }

    if (\Drupal::moduleHandler()->moduleExists('license_service_token_counter_engine')) {
      try {
        $links[] = Link::fromTextAndUrl(
          $this->t('Pricing tables'),
          Url::fromRoute('entity.pricing_table.collection')
        )->toString();
      }
      catch (\Exception) {
      }
    }

    if ($links) {
      $form['quick_links'] = [
        '#markup' => '<p>' . implode(' &mdash; ', $links) . '</p>',
        '#weight' => -10,
      ];
    }

    // ── Capture ─────────────────────────────────────────────────────────────
    $form['capture'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Capture'),
    ];

    $form['capture']['capture_enabled'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Capture AI token usage'),
      '#description'   => $this->t('When enabled, token usage is recorded for each AI interaction.'),
      '#default_value' => (bool) $config->get('capture_enabled'),
    ];

    $form['capture']['captured_operations'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Operation types to capture'),
      '#description'   => $this->t('One AI operation type per line (for example: chat, embeddings). Leave empty to capture every operation type.'),
      '#default_value' => implode("\n", (array) $config->get('captured_operations')),
      '#rows'          => 6,
    ];

    $form['capture']['record_anonymous'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Record anonymous (visitor) usage'),
      '#description'   => $this->t('When enabled, AI interactions made by unauthenticated visitors (uid 0) are recorded. Disable to track only authenticated users.'),
      '#default_value' => (bool) ($config->get('record_anonymous') ?? TRUE),
    ];

    // ── Retention ───────────────────────────────────────────────────────────
    $form['retention'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Retention'),
    ];

    $form['retention']['retention_days'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Retention (days)'),
      '#description'   => $this->t('Delete usage records older than this many days during cron. Use 0 to keep records forever.'),
      '#default_value' => (int) $config->get('retention_days'),
      '#min'           => 0,
    ];

    // ── Display ─────────────────────────────────────────────────────────────
    $form['display'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Display'),
    ];

    // Build the currency option list, ensuring the currently saved value is
    // always present even if Commerce's currency list has changed since save.
    $currencyOptions = $this->currencyHelper->getCurrencyOptions();
    $savedCurrency   = (string) $config->get('display_currency');
    $defaultCurrency = $savedCurrency !== '' ? $savedCurrency : $this->currencyHelper->getDefaultCurrency();
    if (!isset($currencyOptions[$defaultCurrency])) {
      $currencyOptions[$defaultCurrency] = $defaultCurrency;
    }

    $form['display']['display_currency'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Display currency'),
      '#description'   => $this->t('Currency used when showing estimated cost. Defaults to the default store currency when Drupal Commerce is installed.'),
      '#options'       => $currencyOptions,
      '#default_value' => $defaultCurrency,
      '#required'      => TRUE,
    ];

    $form['display']['cost_display_precision'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Cost display precision (decimal places)'),
      '#description'   => $this->t('Number of decimal places shown for estimated cost figures in reports and blocks. Higher values are more precise but noisier. Default: 4.'),
      '#default_value' => (int) ($config->get('cost_display_precision') ?? 4),
      '#min'           => 0,
      '#max'           => 10,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $precision = (int) $form_state->getValue('cost_display_precision');
    if ($precision < 0 || $precision > 10) {
      $form_state->setErrorByName('cost_display_precision', $this->t('Cost display precision must be between 0 and 10.'));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $operations = array_values(array_filter(array_map(
      static fn (string $line): string => trim($line),
      preg_split('/\r\n|\r|\n/', (string) $form_state->getValue('captured_operations')) ?: []
    ), static fn (string $line): bool => $line !== ''));

    $this->config(self::SETTINGS)
      ->set('capture_enabled', (bool) $form_state->getValue('capture_enabled'))
      ->set('captured_operations', $operations)
      ->set('record_anonymous', (bool) $form_state->getValue('record_anonymous'))
      ->set('retention_days', (int) $form_state->getValue('retention_days'))
      ->set('display_currency', strtoupper(trim((string) $form_state->getValue('display_currency'))))
      ->set('cost_display_precision', (int) $form_state->getValue('cost_display_precision'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
