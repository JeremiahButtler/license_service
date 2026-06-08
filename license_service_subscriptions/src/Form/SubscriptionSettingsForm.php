<?php

declare(strict_types=1);

namespace Drupal\license_service_subscriptions\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for the subscription deprecation and renewal pipeline.
 *
 * Route: /admin/config/license-service/subscriptions/settings
 * Permission: administer license subscriptions
 *
 * Author: Jeremiah Buttler.
 */
class SubscriptionSettingsForm extends ConfigFormBase {

  /**
   * Constructs a SubscriptionSettingsForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $configFactory) {
    parent::__construct($configFactory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('config.factory'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'license_service_subscriptions_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['license_service_subscriptions.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('license_service_subscriptions.settings');

    // Build tier options for the fallback-tier select.
    $tiers = (array) ($this->config('license_service.tiers')->get('tiers') ?? []);
    $tierOptions = ['' => $this->t('— None (revoke access) —')];
    foreach ($tiers as $tierId => $tierData) {
      $label = (string) ($tierData['label'] ?? ucfirst((string) $tierId));
      if (!empty($tierData['active'])) {
        $tierOptions[(string) $tierId] = $label;
      }
      else {
        // Show deprecated tiers so admins can see existing assignments, but
        // mark them as deprecated so they know not to use them as fallbacks.
        $tierOptions[(string) $tierId] = $this->t('@label (deprecated)', ['@label' => $label]);
      }
    }

    $form['fallback'] = [
      '#type'        => 'fieldset',
      '#title'       => $this->t('Default fallback tier'),
      '#description' => $this->t(
        'The tier applied when a deprecated-plan subscriber makes no explicit choice,
        or when checkout for a paid replacement plan is not completed in time.
        Individual plans may override this via their own <em>Fallback tier</em> field.'
      ),
    ];

    $form['fallback']['default_fallback_tier'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Default fallback tier'),
      '#options'       => $tierOptions,
      '#default_value' => (string) ($config->get('default_fallback_tier') ?? 'free'),
      '#required'      => FALSE,
    ];

    // ── Deprecation timing ───────────────────────────────────────────────────
    $form['timing'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Deprecation & payment timing'),
    ];

    $form['timing']['grace_window_days'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Grace window (days)'),
      '#description'   => $this->t(
        'Days a subscriber on a deprecated or payment-failed plan retains access
        while the choose-plan window is open. After this, the fallback tier applies.'
      ),
      '#default_value' => (int) ($config->get('grace_window_days') ?? 7),
      '#min'           => 1,
      '#max'           => 365,
      '#required'      => TRUE,
    ];

    $form['timing']['choice_window_days'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Choose-plan window (days)'),
      '#description'   => $this->t(
        'Days before the migration deadline during which the subscriber receives
        a choose-plan email link. Must be ≤ grace window.'
      ),
      '#default_value' => (int) ($config->get('choice_window_days') ?? 14),
      '#min'           => 1,
      '#max'           => 365,
      '#required'      => TRUE,
    ];

    $form['timing']['force_migrate_grace_days'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Force-Migrate-Now grace (days)'),
      '#description'   => $this->t(
        'Days between an admin triggering Force-Migrate-Now and the actual migration.
        Gives the subscriber time to react.'
      ),
      '#default_value' => (int) ($config->get('force_migrate_grace_days') ?? 3),
      '#min'           => 0,
      '#max'           => 30,
      '#required'      => TRUE,
    ];

    $form['timing']['payment_completion_grace_days'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Payment completion grace (days)'),
      '#description'   => $this->t(
        'Days after a subscriber submits a paid-plan choice to complete checkout.
        If checkout is not completed, the fallback tier applies.'
      ),
      '#default_value' => (int) ($config->get('payment_completion_grace_days') ?? 3),
      '#min'           => 1,
      '#max'           => 30,
      '#required'      => TRUE,
    ];

    // ── Renewal & dunning ────────────────────────────────────────────────────
    $form['renewal'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Renewal & dunning'),
    ];

    $form['renewal']['renewal_reminder_days'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Renewal reminder (days before renewal)'),
      '#description'   => $this->t(
        'Days before a subscription renewal date to send the renewal reminder email.
        Also controls the dunning ceiling: subscriptions in payment_method_failing
        state longer than this are auto-revoked by cron.'
      ),
      '#default_value' => (int) ($config->get('renewal_reminder_days') ?? 7),
      '#min'           => 1,
      '#max'           => 90,
      '#required'      => TRUE,
    ];

    // ── Pause policy ─────────────────────────────────────────────────────────
    $form['pause'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Pause policy'),
    ];

    $form['pause']['max_pause_days'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Maximum pause duration (days)'),
      '#description'   => $this->t(
        'Maximum number of days a subscription may remain paused (state = paused)
        before cron auto-revokes its roles. Roles are NOT stripped during the
        pause window.'
      ),
      '#default_value' => (int) ($config->get('max_pause_days') ?? 90),
      '#min'           => 1,
      '#max'           => 730,
      '#required'      => TRUE,
    ];

    // ── Notifications ────────────────────────────────────────────────────────
    $form['notifications'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Notifications'),
    ];

    $form['notifications']['notification_enabled'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Send deprecation and renewal notification emails'),
      '#description'   => $this->t(
        'When enabled, subscribers receive emails for plan deprecation, renewal
        reminders, and choose-plan links. Disable to suppress all outgoing
        subscription emails (useful during testing or migration periods).'
      ),
      '#default_value' => (bool) ($config->get('notification_enabled') ?? TRUE),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $grace   = (int) $form_state->getValue('grace_window_days');
    $choice  = (int) $form_state->getValue('choice_window_days');

    if ($choice > $grace) {
      $form_state->setErrorByName(
        'choice_window_days',
        $this->t('The choose-plan window must be ≤ the grace window (@grace days).', ['@grace' => $grace]),
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('license_service_subscriptions.settings')
      ->set('default_fallback_tier', (string) $form_state->getValue('default_fallback_tier'))
      ->set('grace_window_days', (int) $form_state->getValue('grace_window_days'))
      ->set('choice_window_days', (int) $form_state->getValue('choice_window_days'))
      ->set('force_migrate_grace_days', (int) $form_state->getValue('force_migrate_grace_days'))
      ->set('payment_completion_grace_days', (int) $form_state->getValue('payment_completion_grace_days'))
      ->set('renewal_reminder_days', (int) $form_state->getValue('renewal_reminder_days'))
      ->set('max_pause_days', (int) $form_state->getValue('max_pause_days'))
      ->set('notification_enabled', (bool) $form_state->getValue('notification_enabled'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
