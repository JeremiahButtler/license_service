<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\license_service_token_counter\Service\UsageAggregator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON endpoint for real-time token usage polling.
 *
 * Route: GET /api/license-service-token-counter/usage
 * Query parameters:
 *   scope  — site | own | user  (required)
 *   uid    — integer             (required when scope = user)
 *   period — day|week|month|year|lifetime (default: lifetime)
 *
 * Access is enforced per scope:
 *   site  — 'view ai token usage reports' or 'administer ai token counter'
 *   own   — 'view own ai token usage'; uid must match the current user
 *   user  — 'view ai token usage reports' for any uid; or 'view own ai token
 *            usage' when uid matches the current user
 *
 * Author: Jeremiah Buttler.
 */
final class UsageApiController extends ControllerBase {

  /**
   * Constructs a UsageApiController.
   *
   * @param \Drupal\license_service_token_counter\Service\UsageAggregator $aggregator
   *   The token aggregation service.
   */
  public function __construct(
    private readonly UsageAggregator $aggregator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('license_service_token_counter.usage_aggregator'),
    );
  }

  /**
   * Returns the current token count for the requested scope and period.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with token/cost totals for the requested scope and period.
   */
  public function usage(Request $request): JsonResponse {
    // Resolve the current user via ControllerBase. A regular _controller method
    // argument cannot be type-resolved to the current account (only access
    // callbacks can), so taking it as a parameter threw a RuntimeException.
    $account = $this->currentUser();

    $scope  = $request->query->get('scope', 'site');
    $period = $request->query->get('period', 'lifetime');
    $uid    = (int) ($request->query->get('uid', 0));

    // Validate period.
    if (!in_array($period, ['day', 'week', 'month', 'year', 'lifetime'], TRUE)) {
      $period = 'lifetime';
    }

    // Access + token count per scope.
    switch ($scope) {
      case 'site':
        if (!$account->hasPermission('view ai token usage reports') &&
            !$account->hasPermission('administer ai token counter')) {
          return $this->forbidden();
        }
        $tokens = $this->aggregator->getSiteTokens($period);
        break;

      case 'own':
        if (!$account->hasPermission('view own ai token usage')) {
          return $this->forbidden();
        }
        $tokens = $this->aggregator->getUserTokens((int) $account->id(), $period);
        break;

      case 'user':
        // Viewing another user's data requires the report permission; own
        // data only needs 'view own'.
        $is_own = $uid > 0 && (int) $account->id() === $uid;
        $can_see = $account->hasPermission('view ai token usage reports')
                || $account->hasPermission('administer ai token counter')
                || ($is_own && $account->hasPermission('view own ai token usage'));

        if (!$can_see || $uid <= 0) {
          return $this->forbidden();
        }
        $tokens = $this->aggregator->getUserTokens($uid, $period);
        break;

      default:
        return new JsonResponse(['error' => 'Unknown scope.'], 400);
    }

    $breakdown_uid = match ($scope) {
      'own'   => (int) $account->id(),
      'user'  => $uid,
      default => NULL,
    };
    return new JsonResponse([
      'tokens'    => $tokens,
      'scope'     => $scope,
      'period'    => $period,
      'breakdown' => $this->aggregator->getBreakdown($breakdown_uid, $period),
      'cost'      => $this->aggregator->getCostSummary($breakdown_uid, $period),
    ]);
  }

  /**
   * Returns a 403 JSON response.
   */
  private function forbidden(): JsonResponse {
    return new JsonResponse(['error' => 'Access denied.'], 403);
  }

}
