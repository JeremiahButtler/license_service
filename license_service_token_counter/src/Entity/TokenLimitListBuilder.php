<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\Entity;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\license_service\Period\PeriodManager;

/**
 * Renders the admin list of token limit rules.
 *
 * Author: Jeremiah Buttler.
 */
final class TokenLimitListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label']  = $this->t('Label');
    $header['scope']  = $this->t('Applies to');
    $header['limit']  = $this->t('Limit');
    $header['period'] = $this->t('Period');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof TokenLimitInterface);

    $scope_labels = TokenLimit::scopeLabels();
    $period_labels = PeriodManager::labels();

    if ($entity->getScopeType() === TokenLimitInterface::SCOPE_ROLE && $entity->getRoleId() !== '') {
      $scope = $this->t('Role: @role', ['@role' => $entity->getRoleId()]);
    }
    else {
      $scope = $scope_labels[$entity->getScopeType()] ?? $entity->getScopeType();
    }

    $row['label']  = $entity->label();
    $row['scope']  = $scope;
    $row['limit']  = number_format($entity->getAmount());
    $row['period'] = $period_labels[$entity->getPeriod()] ?? $entity->getPeriod();
    $row['status'] = $entity->status() ? $this->t('Enabled') : $this->t('Disabled');

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity): array {
    $ops = parent::getDefaultOperations($entity);
    // Reorder so Edit comes before Delete.
    ksort($ops);
    return $ops;
  }

}
