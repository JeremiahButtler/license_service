<?php

declare(strict_types=1);

namespace Drupal\license_service_token_limits\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\license_service\LicenseFeatureProviderInterface;
use Drupal\license_service_token_counter\Service\UsageAggregatorInterface;
use Drupal\license_service_token_limits\Service\LevelQuotaEvaluator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the token-usage reports page for the License Service admin area.
 *
 * Route: /admin/config/license-service/reports
 * Permission: administer ai token counter
 *
 * Author: Jeremiah Buttler.
 */
class ReportsController extends ControllerBase {

  public function __construct(
    private readonly UsageAggregatorInterface $usageAggregator,
    private readonly LicenseFeatureProviderInterface $licenseProvider,
    private readonly LevelQuotaEvaluator $quotaEvaluator,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->entityTypeManager = $entityTypeManager;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('license_service_token_counter.usage_aggregator'),
      $container->get('license_service.manager'),
      $container->get('license_service_token_limits.level_quota_evaluator'),
      $container->get('entity_type.manager'),
    );
  }

  public function page(): array {
    $period    = 'month';
    $siteTotal = $this->usageAggregator->getSiteTokens($period);
    $perUser   = $this->usageAggregator->getPerUserTokens($period, 200);
    $build     = [];

    $build['summary'] = [
      '#markup' => '<p>' . $this->t('Site-wide token usage this month: <strong>@total</strong>', ['@total' => number_format($siteTotal)]) . '</p>',
    ];

    if (empty($perUser)) {
      $build['empty'] = ['#markup' => '<p>' . $this->t('No token usage recorded for this period.') . '</p>'];
      return $build;
    }

    $uids  = array_keys($perUser);
    $users = $this->entityTypeManager->getStorage('user')->loadMultiple($uids);
    $rows  = [];

    foreach ($perUser as $uid => $used) {
      $uid      = (int) $uid;
      $user     = $users[$uid] ?? NULL;
      $userName = $user ? $user->getDisplayName() : $this->t('(uid @uid)', ['@uid' => $uid]);
      $level    = $user ? $this->licenseProvider->getLevelForAccount($user) : '';
      $quota    = $this->quotaEvaluator->getQuota($level);
      $limit    = $quota['amount'];

      if ($limit > 0) {
        $pct          = min(100, (int) round($used / $limit * 100));
        $limitDisplay = number_format($limit);
        $pctDisplay   = $pct . '%';
        $overQuota    = $used >= $limit;
      }
      else {
        $pct          = NULL;
        $limitDisplay = $this->t('Unlimited');
        $pctDisplay   = '';
        $overQuota    = FALSE;
      }

      $badge  = $overQuota
        ? ['#markup' => '<span class="lss-state state-failing">' . $this->t('Over quota') . '</span>']
        : ['#markup' => '<span class="lss-state state-active">' . $this->t('OK') . '</span>'];

      $rows[] = [
        ['data' => ['#markup' => '<strong>' . htmlspecialchars((string) $userName) . '</strong><br><small>' . $uid . '</small>']],
        ['data' => ['#plain_text' => $level]],
        ['data' => ['#plain_text' => number_format($used)]],
        ['data' => ['#plain_text' => $limitDisplay]],
        ['data' => ['#plain_text' => $pctDisplay]],
        ['data' => $badge],
      ];
    }

    $build['table'] = [
      '#type'       => 'table',
      '#header'     => [$this->t('User'), $this->t('License level'), $this->t('Tokens used (month)'), $this->t('Quota'), $this->t('% used'), $this->t('Status')],
      '#rows'       => $rows,
      '#empty'      => $this->t('No usage data found.'),
      '#attributes' => ['class' => ['license-service-reports-table']],
    ];

    $build['note'] = [
      '#markup' => '<p><small>' . $this->t('Showing up to 200 users by token usage this calendar month. Quota amounts are read from <a href=":url">Token Limits settings</a>.', [':url' => '/admin/config/license-service/token-limits']) . '</small></p>',
    ];

    return $build;
  }

}