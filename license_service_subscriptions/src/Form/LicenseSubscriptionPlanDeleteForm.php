<?php

declare(strict_types=1);

namespace Drupal\license_service_subscriptions\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Delete confirmation form for a LicenseSubscriptionPlan config entity.
 *
 * Queries the subscription-state table and blocks deletion if any active,
 * paused, or migrating subscribers are linked to this plan. The admin must
 * either wait for migrations to complete or manually move subscribers first.
 *
 * Author: Jeremiah Buttler.
 */
class LicenseSubscriptionPlanDeleteForm extends EntityConfirmFormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->database = $container->get('database');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    return (string) $this->t('Are you sure you want to delete the subscription plan %name?', [
      '%name' => $this->entity->label(),
    ]);
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
  public function getConfirmText(): string {
    return (string) $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Block deletion if there are active subscribers.
    $activeCount = $this->countActiveSubscribers();
    if ($activeCount > 0) {
      $form['blocked'] = [
        '#markup' => '<p>' . $this->t(
          'This plan cannot be deleted: it has @count active, paused, or in-migration subscriber(s). Deactivate the plan first and wait for all migrations to complete, or move subscribers to another plan manually.',
          ['@count' => $activeCount],
        ) . '</p>',
      ];
      // Hide the confirm / cancel buttons so the admin cannot proceed.
      $form['actions']['submit']['#access'] = FALSE;
      return $form;
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->entity->delete();
    $this->messenger()->addStatus($this->t('Subscription plan %label deleted.', [
      '%label' => $this->entity->label(),
    ]));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  // --------------------------------------------------------------------------
  // Helpers
  // --------------------------------------------------------------------------

  /**
   * Returns the count of active/paused/migrating subscribers for this plan.
   *
   * @return int
   *   Row count; 0 means safe to delete.
   */
  private function countActiveSubscribers(): int {
    if (!\Drupal::database()->schema()->tableExists('license_service_subscriptions_state')) {
      return 0;
    }

    return (int) $this->database
      ->select('license_service_subscriptions_state', 's')
      ->condition('s.plan_id', $this->entity->id())
      ->condition('s.state', ['active', 'paused', 'migrating'], 'IN')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
