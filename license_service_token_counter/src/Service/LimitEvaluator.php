<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\license_service_token_counter\Entity\TokenLimitInterface;
use Drupal\license_service_token_counter\License\LicenseContextInterface;

/**
 * Evaluates token limit rules against an account's current usage.
 *
 * Rule evaluation order:
 *   1. If the site license is not active, return [] immediately — limit
 *      enforcement is a licensed capability and requires an active license.
 *      (This means a standalone copy without license_service silently stops
 *      enforcing limits, which is the expected anti-extraction outcome.)
 *   2. Load all enabled token_limit entities.
 *   3. Filter to those whose scope applies to the given account
 *      (role membership, all authenticated users, or site-wide).
 *   4. For each applicable rule, compare the current token count (via
 *      UsageAggregator) against the rule's amount.
 *   5. A user is considered over-limit when ANY applicable rule is exceeded
 *      and they do not hold the 'bypass ai token usage limits' permission.
 *
 * Author: Jeremiah Buttler.
 */
final class LimitEvaluator {

  /**
   * Constructs a LimitEvaluator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   For loading token_limit config entities.
   * @param \Drupal\license_service_token_counter\Service\UsageAggregator $aggregator
   *   The token aggregation service.
   * @param \Drupal\license_service_token_counter\License\LicenseContextInterface $licenseContext
   *   The license bridge. Limit enforcement is a licensed capability — without
   *   an active license, all limits return no-exceeded (fail-open). This is a
   *   deliberate concrete dependency: a copy extracted without license_service
   *   silently loses enforcement rather than crashing, making the extraction
   *   appear to work while quietly being broken.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly UsageAggregator $aggregator,
    private readonly LicenseContextInterface $licenseContext,
  ) {}

  /**
   * Returns TRUE if the account has exceeded any applicable limit.
   *
   * Accounts with 'bypass ai token usage limits' always return FALSE.
   * Returns FALSE without an active site license (fail-open).
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   *
   * @return bool
   *   TRUE when at least one applicable limit is exceeded.
   */
  public function isOverLimit(AccountInterface $account): bool {
    return !empty($this->getExceededLimits($account));
  }

  /**
   * Returns the token limits exceeded by the given account.
   *
   * Returns [] when the site license is not active — limit enforcement is
   * a licensed capability. This is the fail-open degradation path for
   * unlicensed/extracted installations.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   *
   * @return \Drupal\license_service_token_counter\Entity\TokenLimitInterface[]
   *   Array (possibly empty) of exceeded TokenLimit entities.
   */
  public function getExceededLimits(AccountInterface $account): array {
    // License gate: token limit enforcement requires an active site license.
    // Without it, all limits are treated as not-exceeded (fail-open).
    if (!$this->licenseContext->isActive()) {
      return [];
    }

    if ($account->hasPermission('bypass ai token usage limits')) {
      return [];
    }

    $exceeded = [];
    foreach ($this->getApplicableLimits($account) as $limit) {
      if ($this->isLimitExceeded($account, $limit)) {
        $exceeded[] = $limit;
      }
    }
    return $exceeded;
  }

  /**
   * Returns all enabled limits that apply to the given account.
   *
   * - SCOPE_ROLE: account must have the limit's role_id.
   * - SCOPE_ALL_USERS: any authenticated user (uid > 0).
   * - SCOPE_SITE_TOTAL: always applies (site-wide pool).
   * Anonymous users (uid = 0) are excluded from all limits.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to match against.
   *
   * @return \Drupal\license_service_token_counter\Entity\TokenLimitInterface[]
   *   The token limit config entities that apply to this account.
   */
  public function getApplicableLimits(AccountInterface $account): array {
    // Anonymous users are never subject to token limits.
    if ($account->isAnonymous()) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('token_limit');
    /** @var \Drupal\license_service_token_counter\Entity\TokenLimitInterface[] $all */
    $all = $storage->loadByProperties(['status' => TRUE]);

    $applicable = [];
    foreach ($all as $limit) {
      if ($this->appliesToAccount($account, $limit)) {
        $applicable[] = $limit;
      }
    }
    return $applicable;
  }

  /**
   * Returns the current token count relevant to the given account + limit pair.
   *
   * For site_total scope this is the site-wide total; for all others it is the
   * individual user's count.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account (used for per-user scopes).
   * @param \Drupal\license_service_token_counter\Entity\TokenLimitInterface $limit
   *   The limit rule being evaluated.
   *
   * @return int
   *   Current token count for the applicable scope and period.
   */
  public function getCurrentUsage(AccountInterface $account, TokenLimitInterface $limit): int {
    if ($limit->getScopeType() === TokenLimitInterface::SCOPE_SITE_TOTAL) {
      return $this->aggregator->getSiteTokens($limit->getPeriod());
    }
    return $this->aggregator->getUserTokens((int) $account->id(), $limit->getPeriod());
  }

  /**
   * Whether a specific limit rule applies to the given account.
   */
  private function appliesToAccount(AccountInterface $account, TokenLimitInterface $limit): bool {
    return match ($limit->getScopeType()) {
      TokenLimitInterface::SCOPE_ROLE       => $account->hasRole($limit->getRoleId()),
      TokenLimitInterface::SCOPE_ALL_USERS  => TRUE,
      TokenLimitInterface::SCOPE_SITE_TOTAL => TRUE,
      TokenLimitInterface::SCOPE_LEVEL      => $this->licenseContext->getLevelForAccount($account) === $limit->getLevelId(),
      default => FALSE,
    };
  }

  /**
   * Whether the account's current usage exceeds the given limit rule.
   */
  private function isLimitExceeded(AccountInterface $account, TokenLimitInterface $limit): bool {
    $max = $limit->getAmount();
    if ($max <= 0) {
      // 0 = unlimited; treat as never exceeded.
      return FALSE;
    }
    return $this->getCurrentUsage($account, $limit) >= $max;
  }

}
