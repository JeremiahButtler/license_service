<?php

namespace Drupal\license_service\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\license_service\LicenseManagerService;
use Drupal\user\RoleStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Maps Drupal roles to license levels and enforces seat caps.
 *
 * Each role is assigned a license level (free, standard, premium, etc.).
 * The available levels are constrained by the license envelope (allowed_levels
 * from the signed token), so options beyond what the license permits are
 * displayed as disabled.
 *
 * Author: Jeremiah Buttler
 */
class RoleLevelForm extends ConfigFormBase {

  public function __construct(
    protected readonly LicenseManagerService $licenseManager,
    protected readonly RoleStorageInterface $roleStorage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static(
      $container->get('license_service.license_manager'),
      $container->get('entity_type.manager')->getStorage('user_role'),
    );
    $instance->setConfigFactory($container->get('config.factory'));
    $instance->setStringTranslation($container->get('string_translation'));
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'license_service_role_levels';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['license_service.role_levels'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config  = $this->config('license_service.role_levels');
    $roleMap = (array) ($config->get('role_levels') ?? []);
    $levels  = $this->licenseManager->getLevelOrder();

    $form['intro'] = [
      '#markup' => '<p>' . $this->t(
        'Assign a license tier to each role. A user\'s effective tier is the highest across all their roles. Define tiers and their included features on the <a href="/admin/config/license-service/tiers">License Tiers</a> page.'
      ) . '</p>',
    ];

    $levelOptions = $this->buildLevelOptions($levels);

    $form['role_levels'] = [
      '#type'    => 'table',
      '#caption' => $this->t('Role to tier assignments'),
      '#header'  => [$this->t('Role'), $this->t('License tier'), $this->t('Notes')],
    ];

    $roles = $this->roleStorage->loadMultiple();
    foreach ($roles as $roleId => $role) {
      $current = (string) ($roleMap[$roleId] ?? 'free');

      $notes = '';
      if ($roleId === 'administrator') {
        $notes = $this->t('Administrators always have bypass access regardless of this setting.');
      }

      $form['role_levels'][$roleId]['role_label'] = [
        '#plain_text' => $role->label(),
      ];

      $form['role_levels'][$roleId]['level'] = [
        '#type'          => 'select',
        '#options'       => $levelOptions,
        '#default_value' => in_array($current, $levels, TRUE) ? $current : 'free',
        '#parents'       => ['role_levels', $roleId, 'level'],
      ];

      $form['role_levels'][$roleId]['notes'] = [
        '#markup' => $notes,
      ];
    }

    // Show authorized-user count from the LVS plan (informational only).
    $authorizedUsers = $this->licenseManager->getEnvelope()['authorized_users'];
    if ($authorizedUsers > 0) {
      $form['seat_cap_note'] = [
        '#markup' => '<p>' . $this->t(
          'Your plan authorizes a maximum of @cap non-free users. Authorization is enforced per user by the License Verification Server.',
          ['@cap' => $authorizedUsers],
        ) . '</p>',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $roleLevels = $form_state->getValue('role_levels', []);
    $map = [];
    foreach ($roleLevels as $roleId => $entry) {
      $map[(string) $roleId] = (string) ($entry['level'] ?? 'free');
    }

    $this->config('license_service.role_levels')
      ->set('role_levels', $map)
      ->save();

    $this->licenseManager->invalidateCache();

    parent::submitForm($form, $form_state);
  }

  // --------------------------------------------------------------------------
  // Private helpers
  // --------------------------------------------------------------------------

  /**
   * Builds the level select options, marking unavailable levels as disabled.
   */
  protected function buildLevelOptions(array $allowedLevels): array {
    // Always include all known levels; disable those not in the envelope.
    $allLevels = $this->licenseManager->getLevelOrder();

    // Ensure at least a reasonable set of named levels is offered.
    foreach (['standard', 'premium', 'enterprise'] as $extra) {
      if (!in_array($extra, $allLevels, TRUE)) {
        $allLevels[] = $extra;
      }
    }

    $options = [];
    foreach ($allLevels as $level) {
      if (in_array($level, $allowedLevels, TRUE)) {
        $options[$level] = ucfirst($level);
      }
      else {
        // Show but disable levels not in the envelope so the admin knows they exist.
        $options[$level] = $this->t('@level (requires license upgrade)', ['@level' => ucfirst($level)]);
      }
    }
    return $options;
  }

}
