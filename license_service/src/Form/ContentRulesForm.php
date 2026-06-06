<?php

namespace Drupal\license_service\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\license_service\LicenseManagerService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Per-content-type access rules for each license level.
 *
 * For each content type × level combination, the admin configures:
 * - can_view / can_create / can_edit / can_delete booleans
 * - create_quota / edit_quota integers (0 = unlimited)
 * - metered_views / metered_period for time-windowed view limits
 * - gated_fields: machine names of fields hidden below this level
 * - gate_file_downloads: boolean restricting private file access.
 *
 * The level list comes from license_service.role_levels config (not hardcoded)
 * so it reflects whatever levels the admin has defined.
 *
 * Author: Jeremiah Buttler
 */
class ContentRulesForm extends ConfigFormBase {

  public function __construct(
    protected readonly LicenseManagerService $licenseManager,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static(
      $container->get('license_service.license_manager'),
      $container->get('entity_type.manager'),
    );
    $instance->setConfigFactory($container->get('config.factory'));
    $instance->setStringTranslation($container->get('string_translation'));
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'license_service_content_rules';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['license_service.content_rules'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config   = $this->config('license_service.content_rules');
    $rules    = (array) ($config->get('rules') ?? []);
    $envelope = $this->licenseManager->getEnvelope();
    $levels   = $this->licenseManager->getLevelOrder();

    // Index existing rules by level+content_type for quick lookup.
    $ruleIndex = [];
    foreach ($rules as $rule) {
      if (!is_array($rule)) {
        continue;
      }
      $key = ($rule['level'] ?? '') . '|' . ($rule['content_type'] ?? '');
      $ruleIndex[$key] = $rule;
    }

    $contentTypes = $this->getContentTypes();
    if (empty($contentTypes)) {
      $form['notice'] = ['#markup' => '<p>' . $this->t('No content types found.') . '</p>'];
      return parent::buildForm($form, $form_state);
    }

    $form['intro'] = [
      '#markup' => '<p>' . $this->t(
        'Configure access entitlements per level and content type. Leave all fields at their defaults (all unchecked, all zeros) to deny access.'
      ) . '</p>',
    ];

    foreach ($contentTypes as $ctId => $ctLabel) {
      $form[$ctId] = [
        '#type'  => 'details',
        '#title' => $ctLabel,
        '#open'  => FALSE,
        'levels' => [
          '#type'   => 'table',
          '#header' => $this->buildTableHeader($envelope),
          '#rows'   => [],
        ],
      ];

      foreach ($levels as $level) {
        $key = "{$level}|{$ctId}";
        $rule = $ruleIndex[$key] ?? [];
        $form[$ctId]['levels'] = $this->buildRuleRow($form[$ctId]['levels'], $level, $ctId, $rule, $envelope);
      }
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Validate integer fields are non-negative.
    $contentTypes = $this->getContentTypes();
    $levels       = $this->licenseManager->getLevelOrder();

    foreach ($contentTypes as $ctId => $_) {
      foreach ($levels as $level) {
        $prefix = "rules][{$ctId}][{$level}";
        foreach (['create_quota', 'edit_quota', 'metered_views'] as $intField) {
          $val = $form_state->getValue(['rules', $ctId, $level, $intField], 0);
          if (!is_numeric($val) || (int) $val < 0) {
            $form_state->setErrorByName("{$prefix}][{$intField}", $this->t('@field must be 0 or greater.', ['@field' => $intField]));
          }
        }
      }
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $rulesInput   = $form_state->getValue('rules', []);
    $levels       = $this->licenseManager->getLevelOrder();
    $contentTypes = $this->getContentTypes();
    $envelope     = $this->licenseManager->getEnvelope();

    $rules = [];
    foreach ($contentTypes as $ctId => $_) {
      foreach ($levels as $level) {
        $entry = (array) ($rulesInput[$ctId][$level] ?? []);
        if (empty($entry)) {
          continue;
        }

        // Sanitize + cap by envelope.
        $period = (string) ($entry['metered_period'] ?? 'monthly');
        if (!in_array($period, ['daily', 'weekly', 'monthly'], TRUE)) {
          $period = 'monthly';
        }

        $gatedRaw = (string) ($entry['gated_fields'] ?? '');
        $gatedFields = $envelope['field_gating']
          ? array_values(array_filter(
              array_map('trim', explode(',', $gatedRaw)),
              static fn(string $f) => $f !== '',
            ))
          : [];

        $rules[] = [
          'level'              => $level,
          'content_type'       => $ctId,
          'can_view'           => (bool) ($entry['can_view'] ?? FALSE),
          'can_create'         => (bool) ($entry['can_create'] ?? FALSE),
          'can_edit'           => (bool) ($entry['can_edit'] ?? FALSE),
          'can_delete'         => (bool) ($entry['can_delete'] ?? FALSE),
          'create_quota'       => $envelope['quotas'] ? max(0, (int) ($entry['create_quota'] ?? 0)) : 0,
          'edit_quota'         => $envelope['quotas'] ? max(0, (int) ($entry['edit_quota'] ?? 0)) : 0,
          'metered_views'      => $envelope['metered_views'] ? max(0, (int) ($entry['metered_views'] ?? 0)) : 0,
          'metered_period'     => $period,
          'gated_fields'       => $gatedFields,
          'gate_file_downloads' => $envelope['download_gating'] ? (bool) ($entry['gate_file_downloads'] ?? FALSE) : FALSE,
        ];
      }
    }

    $this->config('license_service.content_rules')
      ->set('rules', $rules)
      ->save();

    $this->licenseManager->invalidateCache();

    parent::submitForm($form, $form_state);
  }

  // --------------------------------------------------------------------------
  // Private helpers
  // --------------------------------------------------------------------------

  /**
   * Returns a label-keyed list of node content types.
   *
   * @return string[]
   *   Map of machine_name => label.
   */
  protected function getContentTypes(): array {
    try {
      $types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
      $result = [];
      foreach ($types as $id => $type) {
        $result[$id] = $type->label();
      }
      return $result;
    }
    catch (\Exception) {
      return [];
    }
  }

  /**
   * Builds the table header columns; hides columns for unavailable envelope features.
   */
  protected function buildTableHeader(array $envelope): array {
    $header = [
      $this->t('Level'),
      $this->t('View'),
      $this->t('Create'),
      $this->t('Edit'),
      $this->t('Delete'),
    ];
    if ($envelope['quotas']) {
      $header[] = $this->t('Create quota (0=∞)');
      $header[] = $this->t('Edit quota (0=∞)');
    }
    if ($envelope['metered_views']) {
      $header[] = $this->t('View limit (0=∞)');
      $header[] = $this->t('Period');
    }
    if ($envelope['field_gating']) {
      $header[] = $this->t('Gated fields (comma-separated)');
    }
    if ($envelope['download_gating']) {
      $header[] = $this->t('Gate file downloads');
    }
    return $header;
  }

  /**
   * Builds a form row for one level + content type combination.
   *
   * Adds the row's form elements directly to the table render element and
   * returns the modified table.
   */
  protected function buildRuleRow(array $table, string $level, string $ctId, array $rule, array $envelope): array {
    $prefix = ['rules', $ctId, $level];

    $table[$level]['level'] = ['#plain_text' => ucfirst($level)];

    foreach (['can_view', 'can_create', 'can_edit', 'can_delete'] as $op) {
      $table[$level][$op] = [
        '#type'          => 'checkbox',
        '#default_value' => (bool) ($rule[$op] ?? FALSE),
        '#parents'       => [...$prefix, $op],
      ];
    }

    if ($envelope['quotas']) {
      $table[$level]['create_quota'] = [
        '#type'          => 'number',
        '#min'           => 0,
        '#default_value' => (int) ($rule['create_quota'] ?? 0),
        '#size'          => 6,
        '#parents'       => [...$prefix, 'create_quota'],
      ];
      $table[$level]['edit_quota'] = [
        '#type'          => 'number',
        '#min'           => 0,
        '#default_value' => (int) ($rule['edit_quota'] ?? 0),
        '#size'          => 6,
        '#parents'       => [...$prefix, 'edit_quota'],
      ];
    }

    if ($envelope['metered_views']) {
      $table[$level]['metered_views'] = [
        '#type'          => 'number',
        '#min'           => 0,
        '#default_value' => (int) ($rule['metered_views'] ?? 0),
        '#size'          => 6,
        '#parents'       => [...$prefix, 'metered_views'],
      ];
      $table[$level]['metered_period'] = [
        '#type'          => 'select',
        '#options'       => [
          'daily' => $this->t('Daily'),
          'weekly' => $this->t('Weekly'),
          'monthly' => $this->t('Monthly'),
        ],
        '#default_value' => $rule['metered_period'] ?? 'monthly',
        '#parents'       => [...$prefix, 'metered_period'],
      ];
    }

    if ($envelope['field_gating']) {
      $table[$level]['gated_fields'] = [
        '#type'          => 'textfield',
        '#default_value' => implode(', ', (array) ($rule['gated_fields'] ?? [])),
        '#size'          => 30,
        '#description'   => $this->t('Field machine names, comma-separated.'),
        '#parents'       => [...$prefix, 'gated_fields'],
      ];
    }

    if ($envelope['download_gating']) {
      $table[$level]['gate_file_downloads'] = [
        '#type'          => 'checkbox',
        '#default_value' => (bool) ($rule['gate_file_downloads'] ?? FALSE),
        '#parents'       => [...$prefix, 'gate_file_downloads'],
      ];
    }

    return $table;
  }

}
