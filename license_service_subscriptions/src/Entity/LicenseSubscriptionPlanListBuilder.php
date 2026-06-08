<?php

declare(strict_types=1);

namespace Drupal\license_service_subscriptions\Entity;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Provides a listing page for License Subscription Plan config entities.
 *
 * Shown at /admin/config/license-service/subscriptions/plans.
 * Columns: Plan label, machine name, mapped tier, plan type, active status,
 * and the standard Operations column (Edit / Delete / Force-Migrate-Now
 * — Force-Migrate-Now added in Phase 3).
 *
 * Author: Jeremiah Buttler.
 */
class LicenseSubscriptionPlanListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label']   = $this->t('Plan');
    $header['id']      = $this->t('Machine name');
    $header['tier_id'] = $this->t('Tier');
    $header['type']    = $this->t('Type');
    $header['active']  = $this->t('Active');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\license_service_subscriptions\Entity\LicenseSubscriptionPlanInterface $entity */
    $row['label']   = $entity->label();
    $row['id']      = $entity->id();
    $row['tier_id'] = $entity->getTierId() ?: $this->t('— not set —');
    $row['type']    = $entity->getType() === 'perpetual'
      ? $this->t('Perpetual')
      : $this->t('Subscription');
    $row['active']  = $entity->isActive()
      ? $this->t('Yes')
      : $this->t('<strong>Deprecated</strong>');
    return $row + parent::buildRow($entity);
  }

}
