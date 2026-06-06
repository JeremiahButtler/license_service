<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter_engine\Form;

use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Url;

/**
 * Delete confirmation form for pricing_table config entities.
 *
 * Author: Jeremiah Buttler.
 */
final class PricingTableDeleteForm extends EntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to delete the pricing table %label?', [
      '%label' => $this->entity->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('entity.pricing_table.collection');
  }

  /**
   * {@inheritdoc}
   */
  protected function getDeletionMessage(): string {
    return (string) $this->t('The pricing table %label has been deleted.', [
      '%label' => $this->entity->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function getRedirectUrl(): Url {
    return Url::fromRoute('entity.pricing_table.collection');
  }

}
