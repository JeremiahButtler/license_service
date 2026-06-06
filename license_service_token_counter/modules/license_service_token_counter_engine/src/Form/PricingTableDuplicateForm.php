<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter_engine\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Duplicate form for pricing_table config entities.
 *
 * Creates a copy of the selected table with a new machine name and label,
 * disabled by default, then redirects to its edit form.
 *
 * Author: Jeremiah Buttler.
 */
final class PricingTableDuplicateForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    $form['message'] = [
      '#markup' => '<p>' . $this->t(
        'A copy of <strong>%label</strong> will be created in a <em>disabled</em> state with a new machine name. You will be redirected to the copy\'s edit form to review and update the rates before enabling it.',
        ['%label' => $this->entity->label()]
      ) . '</p>',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    return [
      'submit' => [
        '#type'   => 'submit',
        '#value'  => $this->t('Duplicate'),
        '#submit' => ['::save'],
      ],
      'cancel' => [
        '#type'       => 'link',
        '#title'      => $this->t('Cancel'),
        '#url'        => Url::fromRoute('entity.pricing_table.collection'),
        '#attributes' => ['class' => ['button']],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\license_service_token_counter_engine\Entity\PricingTableInterface $source */
    $source  = $this->entity;
    $storage = $this->entityTypeManager->getStorage('pricing_table');

    // Generate a unique machine name based on the source id.
    $base_id = $source->id() . '_copy';
    $new_id  = $base_id;
    $suffix  = 1;
    while ($storage->load($new_id) !== NULL) {
      $new_id = $base_id . '_' . $suffix++;
    }

    // Clone the entity values; start disabled so the admin can review first.
    $values           = $source->toArray();
    $values['id']     = $new_id;
    $values['label']  = $this->t('Copy of @label', ['@label' => $source->label()]);
    $values['status'] = FALSE;
    // EntityStorageInterface::create() generates a new UUID.
    $values['uuid'] = NULL;

    /** @var \Drupal\license_service_token_counter_engine\Entity\PricingTableInterface $copy */
    $copy = $storage->create($values);
    $copy->save();

    $this->messenger()->addStatus($this->t('Pricing table duplicated as %label.', [
      '%label' => $copy->label(),
    ]));

    $form_state->setRedirectUrl($copy->toUrl('edit-form'));
    return SAVED_NEW;
  }

}
