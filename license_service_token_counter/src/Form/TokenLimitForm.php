<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\license_service\LicenseFeatureProviderInterface;
use Drupal\license_service_token_counter\Entity\TokenLimit;
use Drupal\license_service_token_counter\Entity\TokenLimitInterface;
use Drupal\license_service\Period\PeriodManager;
use Drupal\user\RoleInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add and edit form for token_limit config entities.
 *
 * Author: Jeremiah Buttler.
 */
final class TokenLimitForm extends EntityForm {

  /**
   * The license feature provider, for resolving available levels.
   */
  private LicenseFeatureProviderInterface $licenseProvider;

  /**
   * Constructs a TokenLimitForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\license_service\LicenseFeatureProviderInterface $licenseProvider
   *   The license feature provider, for the available level list.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    LicenseFeatureProviderInterface $licenseProvider,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->licenseProvider   = $licenseProvider;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('license_service.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\license_service_token_counter\Entity\TokenLimitInterface $entity */
    $entity = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#description' => $this->t('A short human-readable name for this rule, e.g. "Authenticated users — 50k/month".'),
      '#default_value' => $entity->label(),
      '#maxlength' => 255,
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $entity->id(),
      '#machine_name' => [
        'exists' => [TokenLimit::class, 'load'],
      ],
      '#disabled' => !$entity->isNew(),
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#description' => $this->t('Disabled rules are stored but never evaluated.'),
      '#default_value' => $entity->status(),
    ];

    // ── Scope ────────────────────────────────────────────────────────────────
    $form['scope_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Applies to'),
      '#options' => TokenLimit::scopeLabels(),
      '#default_value' => $entity->getScopeType() ?: TokenLimitInterface::SCOPE_ROLE,
      '#required' => TRUE,
    ];

    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    // Exclude the anonymous and authenticated meta-roles; the all_users scope
    // covers those already.
    $role_options = [];
    foreach ($roles as $role_id => $role) {
      if (in_array($role_id, [RoleInterface::ANONYMOUS_ID, RoleInterface::AUTHENTICATED_ID], TRUE)) {
        continue;
      }
      $role_options[$role_id] = $role->label();
    }

    $form['role_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Role'),
      '#description' => $this->t('The role this limit applies to. Each user in the role gets their own independent limit.'),
      '#options' => $role_options,
      '#default_value' => $entity->getRoleId(),
      '#empty_option' => $this->t('— choose a role —'),
      '#states' => [
        'visible' => [':input[name="scope_type"]' => ['value' => TokenLimitInterface::SCOPE_ROLE]],
        'required' => [':input[name="scope_type"]' => ['value' => TokenLimitInterface::SCOPE_ROLE]],
      ],
    ];

    $level_options = [];
    foreach ($this->licenseProvider->getLevelOrder() as $level) {
      $level_options[$level] = ucfirst($level);
    }

    $form['level_id'] = [
      '#type' => 'select',
      '#title' => $this->t('License level'),
      '#description' => $this->t('The license level this limit applies to. Each user at this level gets their own independent limit.'),
      '#options' => $level_options,
      '#default_value' => $entity->getLevelId(),
      '#empty_option' => $this->t('— choose a level —'),
      '#states' => [
        'visible' => [':input[name="scope_type"]' => ['value' => TokenLimitInterface::SCOPE_LEVEL]],
        'required' => [':input[name="scope_type"]' => ['value' => TokenLimitInterface::SCOPE_LEVEL]],
      ],
    ];

    // ── Quota ─────────────────────────────────────────────────────────────────
    $form['amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Token limit'),
      '#description' => $this->t('Maximum tokens allowed within the chosen period. Role and all-users scopes apply this limit per user; the site-total scope applies it to the entire site.'),
      '#default_value' => $entity->getAmount() ?: NULL,
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['period'] = [
      '#type' => 'select',
      '#title' => $this->t('Period'),
      '#description' => $this->t('Periods reset on a calendar basis (day = midnight, week = Monday, month = 1st, year = January 1).'),
      '#options' => PeriodManager::labels(),
      '#default_value' => $entity->getPeriod() ?: 'month',
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $scope = $form_state->getValue('scope_type');
    if ($scope === TokenLimitInterface::SCOPE_ROLE) {
      $role_id = (string) $form_state->getValue('role_id');
      if ($role_id === '' || $role_id === '_none') {
        $form_state->setErrorByName('role_id', $this->t('Please choose a role for this limit.'));
      }
    }
    if ($scope === TokenLimitInterface::SCOPE_LEVEL) {
      $level_id = (string) $form_state->getValue('level_id');
      if ($level_id === '' || $level_id === '_none') {
        $form_state->setErrorByName('level_id', $this->t('Please choose a license level for this limit.'));
      }
    }

    $amount = (int) $form_state->getValue('amount');
    if ($amount < 1) {
      $form_state->setErrorByName('amount', $this->t('The token limit must be at least 1.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\license_service_token_counter\Entity\TokenLimitInterface $entity */
    $entity = $this->entity;

    // Clear scope-specific fields when their scope is not selected.
    if ($entity->getScopeType() !== TokenLimitInterface::SCOPE_ROLE) {
      $entity->set('role_id', '');
    }
    if ($entity->getScopeType() !== TokenLimitInterface::SCOPE_LEVEL) {
      $entity->set('level_id', '');
    }

    $status = parent::save($form, $form_state);

    $this->messenger()->addStatus($status === SAVED_NEW
      ? $this->t('Token limit %label has been created.', ['%label' => $entity->label()])
      : $this->t('Token limit %label has been updated.', ['%label' => $entity->label()])
    );

    $form_state->setRedirectUrl($entity->toUrl('collection'));

    return $status;
  }

}
