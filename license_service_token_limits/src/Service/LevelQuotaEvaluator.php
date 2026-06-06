<?php

declare(strict_types=1);

namespace Drupal\license_service_token_limits\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\license_service\LicenseFeatureProviderInterface;
use Drupal\license_service_token_counter\Service\UsageAggregatorInterface;

/**
 * Evaluates whether an account has exceeded its per-level token quota.
 *
 * Checks — in order — the module's enabled flag, the license envelope
 * (quotas permitted), bypass permissions, the account's resolved license
 * level, and the configured quota for that level against actual token usage.
 * Returns NULL on every early-exit so callers have a single, cheap NULL check.
 *
 * Author: Jeremiah Buttler.
 */
class LevelQuotaEvaluator {

  /**
   * Config object name for this module's settings.
   */
  private const SETTINGS = 'license_service_token_limits.settings';

  /**
   * Constructs a LevelQuotaEvaluator.
   *
   * @param \Drupal\license_service\LicenseFeatureProviderInterface $licenseProvider
   *   The cross-module license entitlement facade.
   * @param \Drupal\license_service_token_counter\Service\UsageAggregatorInterface $usageAggregator
   *   Aggregates token usage from the recording table.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory for reading module settings and quota definitions.
   */
  public function __construct(
    private readonly LicenseFeatureProviderInterface $licenseProvider,
    private readonly UsageAggregatorInterface $usageAggregator,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns quota-exceeded info for the account, or NULL when not exceeded.
   *
   * Returns NULL when any of the following are true:
   *  - The account is anonymous (uid 0).
   *  - The module's 'enabled' setting is FALSE.
   *  - The license envelope does not permit quota enforcement.
   *  - The account holds 'bypass ai token usage limits' or 'bypass license gate'.
   *  - No quota is configured for the account's level, or the amount is 0.
   *  - The account's token usage is strictly below the configured quota.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to evaluate.
   *
   * @return array{level: string, amount: int, used: int, period: string}|null
   *   Associative array with quota details when the quota is exceeded, or NULL.
   */
  public function getExceededInfo(AccountInterface $account): ?array {
    // Anonymous users are not subject to per-level quotas.
    if ($account->isAnonymous()) {
      return NULL;
    }

    $config = $this->configFactory->get(self::SETTINGS);

    // Bail early when quota enforcement is disabled in settings.
    if (!$config->get('enabled')) {
      return NULL;
    }

    // Bail when the signed license envelope does not grant quota features.
    $envelope = $this->licenseProvider->getEnvelope();
    if (empty($envelope['quotas'])) {
      return NULL;
    }

    // Accounts with a bypass permission skip quota enforcement entirely.
    if ($account->hasPermission('bypass ai token usage limits')
      || $account->hasPermission('bypass license gate')
    ) {
      return NULL;
    }

    // Resolve the account's effective license level from the role→level map.
    $level = $this->licenseProvider->getLevelForAccount($account);

    // Fetch the configured quota for this level; 0 means unlimited.
    $quota = $this->getQuota($level);
    if ($quota['amount'] <= 0) {
      return NULL;
    }

    $used = $this->usageAggregator->getUserTokens((int) $account->id(), $quota['period']);
    if ($used < $quota['amount']) {
      return NULL;
    }

    return [
      'level'  => $level,
      'amount' => $quota['amount'],
      'used'   => $used,
      'period' => $quota['period'],
    ];
  }

  /**
   * Returns TRUE if the account has exceeded its configured level quota.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to evaluate.
   *
   * @return bool
   *   TRUE when the account is over quota.
   */
  public function isOverQuota(AccountInterface $account): bool {
    return $this->getExceededInfo($account) !== NULL;
  }

  /**
   * Returns the configured quota for a license level.
   *
   * @param string $level
   *   License level name, e.g. 'free', 'standard', 'premium'.
   *
   * @return array{amount: int, period: string}
   *   The quota: amount in tokens (0 = unlimited) and the period key.
   */
  public function getQuota(string $level): array {
    $quotas = $this->configFactory->get(self::SETTINGS)->get('quotas') ?? [];
    $entry  = (array) ($quotas[$level] ?? []);
    return [
      'amount' => (int) ($entry['amount'] ?? 0),
      'period' => (string) ($entry['period'] ?? 'month'),
    ];
  }

}
