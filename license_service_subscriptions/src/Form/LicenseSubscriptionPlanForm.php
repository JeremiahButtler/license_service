<?php

declare(strict_types=1);

namespace Drupal\license_service_subscriptions\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\license_service\LicenseFeatureProviderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add / Edit form for the LicenseSubscriptionPlan config entity.
 *
 * Presents:
 *   - Label + machine name (ID) — standard entity fields.
 *   - Tier selector — from live license_service.tiers config (active tiers only).
 *   - Product variation IDs — comma-separated text field (Phase 3 will upgrade
 *     to a proper entity-reference widget once Commerce is available in tests).
 *   - Plan type — subscription vs perpetual.
 *   - Active toggle — FALSE triggers the deprecation pipeline.
 *   - Fallback tier override — optional; leave empty to use the module default.
 *
 * Author: Jeremiah Buttler.
 */
class LicenseSubscriptionPlanForm extends EntityForm {

  /**
   * The license feature provider.
   *
   * @var \Drupal\license_service\LicenseFeatureProviderInterface
   */
  protected LicenseFeatureProviderInterface $licenseFeatureProvider;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->licenseFeatureProvider = $container->get('license_service.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\license_service_subscriptions\Entity\LicenseSubscriptionPlanInterface $plan */
    $plan = $this->entity;

    $form['label'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Plan label'),
      '#default_value' => $plan->label(),
      '#maxlength'     => 255,
      '#required'      => TRUE,
    ];

    $form['id'] = [
      '#type'          => 'machine_name',
      '#default_value' => $plan->id(),
      '#disabled'      => !$plan->isNew(),
      '#machine_name'  => [
        'exists' => '\Drupal\license_service_subscriptions\Entity\LicenseSubscriptionPlan::load',
      ],
    ];

    // Build tier options from live license_service.tiers config.
    $tiers       = (array) ($this->configFactory()->get('license_service.tiers')->get('tiers') ?? []);
    $tierOptions = [];
    foreach ($tiers as $tierId => $tier) {
      if ((bool) ($tier['active'] ?? TRUE)) {
        $tierOptions[$tierId] = (string) ($tier['label'] ?? ucfirst($tierId));
      }
    }

    $form['tier_id'] = [
      '#type'          => 'select',
      '#title'         => $this->t('License tier'),
      '#description'   => $this->t('Users who subscribe to this plan receive the roles/entitlements for this tier. Only active tiers are shown.'),
      '#options'       => $tierOptions,
      '#default_value' => $plan->getTierId(),
      '#required'      => TRUE,
      '#empty_option'  => $this->t('— Select a tier —'),
    ];

    $form['product_variation_ids'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Commerce product variation IDs'),
      '#description'   => $this->t('Comma-separated IDs of the Commerce product variations that activate this plan. Monthly and annual billing cycles can both map here — they confer the same tier. Example: <em>1, 2</em>.'),
      '#default_value' => implode(', ', $plan->getProductVariationIds()),
      '#maxlength'     => 1024,
    ];

    $form['type'] = [
      '#type'          => 'radios',
      '#title'         => $this->t('Plan type'),
      '#description'   => $this->t('<strong>Subscription:</strong> recurring billing; subject to renewal enforcement and forced migration when deprecated. <strong>Perpetual:</strong> one-time purchase; deactivating only blocks new sales — existing customers are never migrated.'),
      '#options'       => [
        'subscription' => $this->t('Subscription (recurring)'),
        'perpetual'    => $this->t('Perpetual (one-time)'),
      ],
      '#default_value' => $plan->getType(),
      '#required'      => TRUE,
    ];

    $form['active'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Plan is active'),
      '#description'   => $this->t('<strong>On (default):</strong> new subscriptions are allowed and no migration pipeline is triggered.<br><strong>Off (deprecated):</strong> new subscriptions are blocked. Existing subscribers finish their current period, then receive a choose-plan email and are migrated to the fallback tier if no choice is made.'),
      '#default_value' => $plan->isActive(),
    ];

    $form['fallback_tier'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Fallback tier (override)'),
      '#description'   => $this->t('When this plan is deprecated, subscribers with no explicit choice are migrated here. Leave blank to use the module-level default fallback tier.'),
      '#options'       => ['' => $this->t('— Use module default —')] + $tierOptions,
      '#default_value' => $plan->getFallbackTier(),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\license_service_subscriptions\Entity\LicenseSubscriptionPlanInterface $plan */
    $plan = $this->entity;

    // Parse the comma-separated product variation IDs back into an array.
    $rawIds = (string) ($form_state->getValue('product_variation_ids') ?? '');
    $ids    = array_values(array_filter(array_map('trim', explode(',', $rawIds))));
    $plan->set('product_variation_ids', $ids);

    $status = parent::save($form, $form_state);

    if ($status === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Subscription plan %label created.', ['%label' => $plan->label()]));
    }
    else {
      $this->messenger()->addStatus($this->t('Subscription plan %label updated.', ['%label' => $plan->label()]));
    }

    $form_state->setRedirectUrl($plan->toUrl('collection'));
    return $status;
  }

}
