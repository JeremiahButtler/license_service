<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\Form;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Delete-confirmation form for token_limit config entities.
 *
 * Author: Jeremiah Buttler.
 */
final class TokenLimitDeleteForm extends EntityConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Delete the %label token limit?', ['%label' => $this->entity->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return $this->entity->toUrl('collection');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('This action cannot be undone. The limit will be removed permanently.');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->entity->delete();
    $this->messenger()->addStatus($this->t('The %label token limit has been deleted.', [
      '%label' => $this->entity->label(),
    ]));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
