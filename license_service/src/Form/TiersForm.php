<?php

declare(strict_types=1);

namespace Drupal\license_service\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\license_service\LicenseManagerService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tenant-defined license tier editor.
 *
 * The tenant (the site running this module) fully controls their own license
 * tiers and the features each tier includes. Tiers defined here drive the
 * Role Levels form, content-access rules, and token quota enforcement.
 * The LVS has no role in defining these tiers.
 *
 * Author: Jeremiah Buttler.
 */
class TiersForm extends ConfigFormBase {

  /**
   * Config object name for tier definitions.
   */
  private const SETTINGS = 'license_service.tiers';

  /**
   * Feature definitions: key => human label.
   */
  public const FEATURES = [
    'field_gating'    => 'Field-level content gating',
    'download_gating' => 'Download / file gating',
    'metered_views'   => 'Metered (limited) content views',
    'quotas'          => 'AI token quota enforcement',
    'content_access'  => 'Content access control rules',
  ];

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->licenseManager = $container->get('license_service.license_manager');
    return $instance;
  }

  /**
   * The license manager, for cache invalidation after save.
   *
   * Nullable because AJAX form-cache deserialization can leave injected
   * services uninitialized; the submit handler falls back to the container.
   */
  private ?LicenseManagerService $licenseManager = NULL;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'license_service_tiers';
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
    // Initialize working tier set from config on the first render.
    if (!$form_state->get('tiers_initialized')) {
      $saved = (array) ($this->config(self::SETTINGS)->get('tiers') ?? []);
      if (!isset($saved['free'])) {
        $saved = ['free' => $this->defaultTier('Free', -10)] + $saved;
      }
      $form_state->set('tiers', $saved);
      $form_state->set('tiers_initialized', TRUE);
    }

    $tiers = $form_state->get('tiers');
    uasort($tiers, static fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));

    $form['help'] = [
      '#markup' => '<p>' . $this->t(
        'Define the license tiers your site offers to users. Assign tiers to roles in the <a href="/admin/config/license-service/role-levels">Role Levels</a> form. The <em>Free</em> tier is always present and cannot be removed.',
      ) . '</p>',
    ];

    $form['tiers_wrap'] = [
      '#type'       => 'container',
      '#attributes' => ['id' => 'license-service-tiers-wrap'],
    ];

    $headers = [$this->t('Tier ID'), $this->t('Label'), $this->t('Weight')];
    foreach (self::FEATURES as $label) {
      $headers[] = $this->t($label);
    }
    $headers[] = $this->t('Operations');

    $form['tiers_wrap']['tiers'] = [
      '#type'      => 'table',
      '#caption'   => $this->t('License tiers'),
      '#header'    => $headers,
      '#empty'     => $this->t('No tiers defined.'),
      '#tabledrag' => [
        [
          'action'       => 'order',
          'relationship' => 'sibling',
          'group'        => 'tier-weight',
        ],
      ],
      '#tree'      => TRUE,
    ];

    foreach ($tiers as $tierId => $tier) {
      $isFree = ($tierId === 'free');
      $row    = &$form['tiers_wrap']['tiers'][$tierId];

      $row['#attributes']['class'][] = 'draggable';

      $row['tier_id'] = [
        '#plain_text' => $tierId,
      ];

      $row['label'] = [
        '#type'          => 'textfield',
        '#title'         => $this->t('Label for @tier', ['@tier' => $tierId]),
        '#title_display' => 'invisible',
        '#default_value' => (string) ($tier['label'] ?? ucfirst($tierId)),
        '#size'          => 20,
        '#required'      => TRUE,
      ];

      $row['weight'] = [
        '#type'          => 'weight',
        '#title'         => $this->t('Weight for @tier', ['@tier' => $tierId]),
        '#title_display' => 'invisible',
        '#default_value' => (int) ($tier['weight'] ?? 0),
        '#attributes'    => ['class' => ['tier-weight']],
      ];

      foreach (array_keys(self::FEATURES) as $featureKey) {
        $row[$featureKey] = [
          '#type'          => 'checkbox',
          '#title'         => $featureKey,
          '#title_display' => 'invisible',
          '#default_value' => (bool) ($tier['features'][$featureKey] ?? FALSE),
        ];
      }

      if ($isFree) {
        $row['operations'] = ['#markup' => '—'];
      }
      else {
        $row['operations'] = [
          '#type'                    => 'submit',
          '#value'                   => $this->t('Remove'),
          '#name'                    => 'remove_' . $tierId,
          '#tier_id'                 => $tierId,
          '#submit'                  => ['::removeTier'],
          '#ajax'                    => [
            'callback' => '::tiersAjaxCallback',
            'wrapper'  => 'license-service-tiers-wrap',
          ],
          '#limit_validation_errors' => [],
        ];
      }

      unset($row);
    }

    // ---- Add tier form -------------------------------------------------------
    $form['tiers_wrap']['add_header'] = [
      '#markup' => '<h3>' . $this->t('Add a tier') . '</h3>',
    ];

    $form['tiers_wrap']['new_tier_id'] = [
      '#type'        => 'textfield',
      '#title'       => $this->t('Tier ID'),
      '#description' => $this->t('Lowercase letters, numbers, underscores only — e.g. <em>standard</em>, <em>premium</em>.'),
      '#size'        => 20,
    ];

    $form['tiers_wrap']['new_tier_label'] = [
      '#type'  => 'textfield',
      '#title' => $this->t('Label'),
      '#size'  => 30,
    ];

    $form['tiers_wrap']['add_tier'] = [
      '#type'                    => 'submit',
      '#value'                   => $this->t('Add tier'),
      '#submit'                  => ['::addTier'],
      '#ajax'                    => [
        'callback' => '::tiersAjaxCallback',
        'wrapper'  => 'license-service-tiers-wrap',
      ],
      '#limit_validation_errors' => [['new_tier_id'], ['new_tier_label']],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * AJAX callback — returns the tiers wrapper for partial refresh.
   */
  public function tiersAjaxCallback(array &$form, FormStateInterface $form_state): array {
    return $form['tiers_wrap'];
  }

  /**
   * Submit handler for the "Add tier" button.
   */
  public function addTier(array &$form, FormStateInterface $form_state): void {
    $rawId = (string) $form_state->getValue('new_tier_id');
    $id    = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($rawId)));

    if ($id === '' || $id === 'free') {
      $form_state->setErrorByName('new_tier_id', $this->t(
        'Tier ID must be a non-empty machine name (not "free").'
      ));
      $form_state->setRebuild(TRUE);
      return;
    }

    $tiers = $form_state->get('tiers');
    if (isset($tiers[$id])) {
      $form_state->setErrorByName('new_tier_id', $this->t(
        'A tier with ID "@id" already exists.', ['@id' => $id]
      ));
      $form_state->setRebuild(TRUE);
      return;
    }

    $label      = (string) ($form_state->getValue('new_tier_label') ?: ucfirst($id));
    $tiers[$id] = $this->defaultTier($label, count($tiers) * 10);
    $form_state->set('tiers', $tiers);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Submit handler for per-row "Remove" buttons.
   */
  public function removeTier(array &$form, FormStateInterface $form_state): void {
    $tierId = (string) ($form_state->getTriggeringElement()['#tier_id'] ?? '');
    if ($tierId !== '' && $tierId !== 'free') {
      $tiers = $form_state->get('tiers');
      unset($tiers[$tierId]);
      $form_state->set('tiers', $tiers);
    }
    $form_state->setRebuild(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $submitted = (array) ($form_state->getValue('tiers') ?? []);
    $existing  = $form_state->get('tiers');

    $saved = [];
    foreach (array_keys($existing) as $tierId) {
      $isFree = ($tierId === 'free');
      $row    = (array) ($submitted[$tierId] ?? []);

      $features = [];
      foreach (array_keys(self::FEATURES) as $featureKey) {
        $features[$featureKey] = (bool) ($row[$featureKey] ?? FALSE);
      }

      $saved[$tierId] = [
        'label'    => trim((string) ($row['label'] ?? ucfirst($tierId))),
        'weight'   => (int) ($row['weight'] ?? 0),
        'features' => $features,
      ];
    }

    // 'free' tier must always be present.
    if (!isset($saved['free'])) {
      $saved['free'] = $this->defaultTier('Free', -10);
    }

    $this->config(self::SETTINGS)->set('tiers', $saved)->save();

    // Flush cached license status so level/feature reads see the new config.
    // Defensive: $licenseManager may be unset on AJAX form-cache deserialization.
    ($this->licenseManager ?? \Drupal::service('license_service.license_manager'))->invalidateCache();

    parent::submitForm($form, $form_state);
  }

  // --------------------------------------------------------------------------
  // Helpers
  // --------------------------------------------------------------------------

  /**
   * Returns a default tier array with all features disabled.
   */
  private function defaultTier(string $label, int $weight = 0): array {
    return [
      'label'    => $label,
      'weight'   => $weight,
      'features' => array_fill_keys(array_keys(self::FEATURES), FALSE),
    ];
  }

}
