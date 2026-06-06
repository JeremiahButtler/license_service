<?php

namespace Drupal\license_service\Entitlements;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\license_service\LicenseManagerService;
use Drupal\license_service\Period\PeriodManager;

/**
 * Resolves content access entitlements for a given license level and content type.
 *
 * Reads the admin-configured content rules (license_service.content_rules config)
 * and translates them into structured entitlement objects the ContentAccessChecker
 * consumes. All returned values are validated against the license envelope so
 * the admin cannot configure capabilities the license does not permit.
 *
 * Config structure (license_service.content_rules):
 *   rules:
 *     -  level:              'premium'
 *        content_type:       'article'
 *        can_view:           true
 *        can_create:         true
 *        can_edit:           true
 *        can_delete:         false
 *        create_quota:       10       # 0 = unlimited
 *        edit_quota:         0
 *        metered_views:      0        # 0 = unlimited
 *        metered_period:     'monthly'
 *        gated_fields:       ['field_premium_body']
 *        gate_file_downloads: false
 *
 * Author: Jeremiah Buttler
 */
class EntitlementResolver {

  public function __construct(
    protected readonly LicenseManagerService $licenseManager,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly PeriodManager $periodManager,
  ) {}

  /**
   * Returns the full entitlement rule for a level + content type combination.
   *
   * Returns sensible deny-all defaults when no rule is configured.
   *
   * @param string $level
   *   License level (e.g. 'free', 'premium').
   * @param string $contentType
   *   Content type machine name (e.g. 'article').
   *
   * @return array{
   *   can_view: bool,
   *   can_create: bool,
   *   can_edit: bool,
   *   can_delete: bool,
   *   create_quota: int,
   *   edit_quota: int,
   *   metered_views: int,
   *   metered_period: string,
   *   gated_fields: string[],
   *   gate_file_downloads: bool,
   *   }
   */
  public function getEntitlementsForLevel(string $level, string $contentType): array {
    $rules = $this->configFactory->get('license_service.content_rules')->get('rules') ?? [];
    if (!is_array($rules)) {
      return $this->defaultDenyEntitlements();
    }

    // Exact match: level + content_type.
    foreach ($rules as $rule) {
      if (!is_array($rule)) {
        continue;
      }
      if (($rule['level'] ?? '') === $level && ($rule['content_type'] ?? '') === $contentType) {
        return $this->normalizeRule($rule);
      }
    }

    // Wildcard: level = '*' matches any content type for this level.
    foreach ($rules as $rule) {
      if (!is_array($rule)) {
        continue;
      }
      if (($rule['level'] ?? '') === $level && ($rule['content_type'] ?? '') === '*') {
        return $this->normalizeRule($rule);
      }
    }

    // No rule found: deny all by default (safe).
    return $this->defaultDenyEntitlements();
  }

  /**
   * Returns TRUE if the given level may view the given content type.
   */
  public function canView(string $level, string $contentType): bool {
    return $this->getEntitlementsForLevel($level, $contentType)['can_view'];
  }

  /**
   * Returns TRUE if the given level may create the given content type.
   */
  public function canCreate(string $level, string $contentType): bool {
    return $this->getEntitlementsForLevel($level, $contentType)['can_create'];
  }

  /**
   * Returns TRUE if the given level may edit the given content type.
   */
  public function canEdit(string $level, string $contentType): bool {
    return $this->getEntitlementsForLevel($level, $contentType)['can_edit'];
  }

  /**
   * Returns TRUE if the given level may delete the given content type.
   */
  public function canDelete(string $level, string $contentType): bool {
    return $this->getEntitlementsForLevel($level, $contentType)['can_delete'];
  }

  /**
   * Returns the create-node quota for the given level + content type.
   *
   * 0 means unlimited.
   */
  public function getCreateQuota(string $level, string $contentType): int {
    return $this->getEntitlementsForLevel($level, $contentType)['create_quota'];
  }

  /**
   * Returns the edit-node quota for the given level + content type.
   *
   * 0 means unlimited.
   */
  public function getEditQuota(string $level, string $contentType): int {
    return $this->getEntitlementsForLevel($level, $contentType)['edit_quota'];
  }

  /**
   * Returns the metered-view limit per period. 0 means unlimited.
   */
  public function getViewLimit(string $level, string $contentType): int {
    return $this->getEntitlementsForLevel($level, $contentType)['metered_views'];
  }

  /**
   * Returns the metered-view period string ('daily', 'weekly', 'monthly').
   */
  public function getViewPeriod(string $level, string $contentType): string {
    return $this->getEntitlementsForLevel($level, $contentType)['metered_period'];
  }

  /**
   * Returns the list of field names hidden at this level for this content type.
   *
   * @return string[]
   *   Field machine names hidden at this level for this content type.
   */
  public function getGatedFields(string $level, string $contentType): array {
    return $this->getEntitlementsForLevel($level, $contentType)['gated_fields'];
  }

  /**
   * Returns TRUE if file downloads for this content type require this level.
   */
  public function gatesFileDownloads(string $level, string $contentType): bool {
    return $this->getEntitlementsForLevel($level, $contentType)['gate_file_downloads'];
  }

  /**
   * Returns the period key for a DateTime calculation ('daily', 'weekly', 'monthly').
   *
   * Delegates to PeriodManager — the canonical period implementation for the
   * License Service ecosystem. All sub-modules that need calendar-period math
   * must use PeriodManager rather than reimplementing the logic themselves.
   *
   * Used by ContentAccessChecker to compute the current period boundary for
   * metered views keyed in the license_service_meter table.
   *
   * @return string
   *   Period start in 'Y-m-d' (daily), 'o-W' (ISO week-year, weekly) or
   *   'Y-m' (monthly).
   */
  public function getCurrentPeriodKey(string $period): string {
    return $this->periodManager->getCurrentPeriodKey($period);
  }

  // --------------------------------------------------------------------------
  // Private helpers
  // --------------------------------------------------------------------------

  /**
   * Normalizes a raw config rule array to the canonical entitlement shape.
   */
  protected function normalizeRule(array $rule): array {
    $envelope = $this->licenseManager->getEnvelope();

    $canView   = (bool) ($rule['can_view'] ?? FALSE);
    $canCreate = (bool) ($rule['can_create'] ?? FALSE);
    $canEdit   = (bool) ($rule['can_edit'] ?? FALSE);
    $canDelete = (bool) ($rule['can_delete'] ?? FALSE);

    // Respect license envelope caps on advanced features.
    $gatedFields   = $envelope['field_gating'] ? (array) ($rule['gated_fields'] ?? []) : [];
    $gateDownloads = $envelope['download_gating'] ? (bool) ($rule['gate_file_downloads'] ?? FALSE) : FALSE;
    $meteredViews  = $envelope['metered_views'] ? max(0, (int) ($rule['metered_views'] ?? 0)) : 0;
    $createQuota   = $envelope['quotas'] ? max(0, (int) ($rule['create_quota'] ?? 0)) : 0;
    $editQuota     = $envelope['quotas'] ? max(0, (int) ($rule['edit_quota'] ?? 0)) : 0;

    $period = (string) ($rule['metered_period'] ?? 'monthly');
    if (!in_array($period, ['daily', 'weekly', 'monthly'], TRUE)) {
      $period = 'monthly';
    }

    // Sanitize gated_fields to plain strings.
    $gatedFields = array_values(array_filter(
      array_map('strval', $gatedFields),
      static fn(string $f) => $f !== '',
    ));

    return [
      'can_view'           => $canView,
      'can_create'         => $canCreate,
      'can_edit'           => $canEdit,
      'can_delete'         => $canDelete,
      'create_quota'       => $createQuota,
      'edit_quota'         => $editQuota,
      'metered_views'      => $meteredViews,
      'metered_period'     => $period,
      'gated_fields'       => $gatedFields,
      'gate_file_downloads' => $gateDownloads,
    ];
  }

  /**
   * Returns a safe default deny-all entitlement structure.
   */
  protected function defaultDenyEntitlements(): array {
    return [
      'can_view'           => FALSE,
      'can_create'         => FALSE,
      'can_edit'           => FALSE,
      'can_delete'         => FALSE,
      'create_quota'       => 0,
      'edit_quota'         => 0,
      'metered_views'      => 0,
      'metered_period'     => 'monthly',
      'gated_fields'       => [],
      'gate_file_downloads' => FALSE,
    ];
  }

}
