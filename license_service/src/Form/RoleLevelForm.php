<?php

namespace Drupal\license_service\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\license_service\LicenseManagerService;
use Drupal\user\RoleStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Maps Drupal roles to license levels.
 *
 * Each role is assigned a license level (free, standard, premium, etc.).
 * Available levels come from the tenant-defined License Tiers config — any
 * tier the admin created here is available for assignment. There are no
 * server-side level restrictions.
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
    $tiers   = (array) ($this->config('license_service.tiers')->get('tiers') ?? []);
    $levels  = $this->licenseManager->getLevelOrder();

    // Only enabled tiers (plus free, which is always selectable) appear as options.
    $enabledLevels = array_values(array_filter(
      $levels,
      static fn(string $l) => $l === 'free' || (bool) ($tiers[$l]['active'] ?? TRUE),
    ));

    $form['intro'] = [
      '#markup' => '<p>' . $this->t(
        'Assign a license tier to each role. A user\'s effective tier is the highest across all their roles. Define tiers and their included features on the <a href="/admin/config/license-service/tiers">License Tiers</a> page.'
      ) . '</p>',
    ];

    $levelOptions = $this->buildLevelOptions($enabledLevels);

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

      // If this role is already assigned to a tier that is now disabled, keep it
      // visible in the select so admins don't silently lose the assignment on
      // save. They must explicitly move the role to an enabled tier to change it.
      $rowOptions = $levelOptions;
      if ($current !== '' && !isset($rowOptions[$current]) && isset($tiers[$current])) {
        $disabledLabel = (string) ($tiers[$current]['label'] ?? ucfirst($current));
        $rowOptions[$current] = $this->t('@label (disabled)', ['@label' => $disabledLabel]);
      }

      $form['role_levels'][$roleId]['level'] = [
        '#type'          => 'select',
        '#options'       => $rowOptions,
        '#default_value' => isset($rowOptions[$current]) ? $current : 'free',
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
   * Builds select options from the given tier IDs (caller pre-filters disabled).
   */
  protected function buildLevelOptions(array $levels): array {
    $options = [];
    foreach ($levels as $level) {
      $options[$level] = ucfirst($level);
    }
    return $options;
  }

}
