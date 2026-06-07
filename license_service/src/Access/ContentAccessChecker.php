<?php

namespace Drupal\license_service\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\license_service\Entitlements\EntitlementResolver;
use Drupal\license_service\LicenseManagerService;
use Drupal\node\NodeInterface;

/**
 * Evaluates content access decisions based on the user's license level.
 *
 * All returned AccessResult objects carry correct cacheability metadata so
 * Drupal's render cache cannot leak higher-level content to a lower-level user:
 * - Cache context: user.roles (level is role-derived).
 * - Cache context: user (for per-user quota/metered decisions).
 * - Cache tag: license_service (invalidated on any license or rule change).
 *
 * This is the highest-risk class in the module for access bypass and render-cache
 * leaks; every method must use addCacheableDependency() and must never return
 * allowed() when the level check has not passed.
 *
 * Author: Jeremiah Buttler
 */
class ContentAccessChecker {

  public function __construct(
    protected readonly LicenseManagerService $licenseManager,
    protected readonly EntitlementResolver $entitlementResolver,
    protected readonly Connection $database,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  // --------------------------------------------------------------------------
  // Node / entity access
  // --------------------------------------------------------------------------

  /**
   * Checks view/update/delete access on an existing node.
   *
   * Returns forbidden() when the user's level doesn't permit the operation,
   * neutral() otherwise (allows other modules to make the final decision).
   * Cache contexts: user.roles + tag license_service.
   */
  public function checkNodeAccess(NodeInterface $node, string $op, AccountInterface $account): AccessResultInterface {
    $level       = $this->licenseManager->getLevelForAccount($account);
    if ($level === 'no_access') {
      return $this->forbiddenWithCacheability('Your account does not have access to site content.');
    }
    $contentType = $node->bundle();

    switch ($op) {
      case 'view':
        if (!$this->entitlementResolver->canView($level, $contentType)) {
          return $this->forbiddenWithCacheability('Your license level does not permit viewing this content.');
        }
        // Metered view limit check (recording happens in hook_node_view, not here).
        $result = $this->checkMeteredView($account, $contentType, $level);
        if ($result !== NULL) {
          return $result;
        }
        // When a metered limit applies, the under-limit decision still depends
        // on this user's own view count, so the neutral result must vary by
        // user — otherwise the render cache could serve it to a same-role user
        // who is over their limit, bypassing the cap.
        if ($this->entitlementResolver->getViewLimit($level, $contentType) > 0) {
          return $this->neutralWithCacheability()->addCacheContexts(['user']);
        }
        break;

      case 'update':
        if (!$this->entitlementResolver->canEdit($level, $contentType)) {
          return $this->forbiddenWithCacheability('Your license level does not permit editing this content type.');
        }
        // Edit quota check (per-period cap).
        $result = $this->checkEditQuota($account, $contentType, $level);
        if ($result !== NULL) {
          return $result;
        }
        // Under quota, but the decision depends on this user's own quota usage;
        // vary the neutral result by user to avoid a render-cache bypass.
        if ($this->entitlementResolver->getEditQuota($level, $contentType) > 0) {
          return $this->neutralWithCacheability()->addCacheContexts(['user']);
        }
        break;

      case 'delete':
        if (!$this->entitlementResolver->canDelete($level, $contentType)) {
          return $this->forbiddenWithCacheability('Your license level does not permit deleting this content type.');
        }
        break;
    }

    return $this->neutralWithCacheability();
  }

  /**
   * Checks whether a content type can be created.
   *
   * Checks the entitlement, then the create quota.
   */
  public function checkCreateAccess(AccountInterface $account, array $context, string $entityBundle): AccessResultInterface {
    $level = $this->licenseManager->getLevelForAccount($account);
    if ($level === 'no_access') {
      return $this->forbiddenWithCacheability('Your account does not have access to site content.');
    }

    if (!$this->entitlementResolver->canCreate($level, $entityBundle)) {
      return $this->forbiddenWithCacheability('Your license level does not permit creating this content type.');
    }

    // Create quota.
    $quota = $this->entitlementResolver->getCreateQuota($level, $entityBundle);
    if ($quota > 0) {
      $used = $this->getQuotaUsage($account->id(), $entityBundle, 'create');
      if ($used >= $quota) {
        return $this->forbiddenWithCacheability(
          "Your license level limits you to {$quota} created items of this type. You have reached the limit."
        )->addCacheContexts(['user']);
      }
      // Under quota, but the decision depends on this user's own quota usage;
      // vary the neutral result by user to avoid a render-cache bypass.
      return $this->neutralWithCacheability()->addCacheContexts(['user']);
    }

    return $this->neutralWithCacheability();
  }

  /**
   * Checks access on non-node content entities.
   *
   * Uses the entity's bundle as the content type key. Nodes are handled by
   * checkNodeAccess() and should not be passed here.
   */
  public function checkEntityAccess(EntityInterface $entity, string $op, AccountInterface $account): AccessResultInterface {
    $level       = $this->licenseManager->getLevelForAccount($account);
    if ($level === 'no_access') {
      return $this->forbiddenWithCacheability('Your account does not have access to site content.');
    }
    $contentType = $entity->bundle();

    $ok = match($op) {
      'view'   => $this->entitlementResolver->canView($level, $contentType),
      'update' => $this->entitlementResolver->canEdit($level, $contentType),
      'delete' => $this->entitlementResolver->canDelete($level, $contentType),
      default  => TRUE,
    };

    if (!$ok) {
      return $this->forbiddenWithCacheability('Your license level does not permit this operation on this content type.');
    }

    return $this->neutralWithCacheability();
  }

  /**
   * Checks field-level view access.
   *
   * Returns forbidden() if the field is in the gated_fields list for the
   * user's level and content type. Only applies to 'view' operations.
   * Cache contexts: user.roles + tag license_service.
   */
  public function checkFieldAccess(string $operation, FieldDefinitionInterface $fieldDefinition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    if ($operation !== 'view') {
      return $this->neutralWithCacheability();
    }

    $level     = $this->licenseManager->getLevelForAccount($account);
    if ($level === 'no_access') {
      return $this->forbiddenWithCacheability('Your account does not have access to site content.');
    }
    $fieldName = $fieldDefinition->getName();

    // Determine the content type from the items (entity) if available.
    // FieldItemListInterface::getEntity() is non-nullable by contract.
    $contentType = '';
    if ($items !== NULL) {
      $contentType = $items->getEntity()->bundle();
    }
    if ($contentType === '') {
      $contentType = $fieldDefinition->getTargetBundle() ?? '';
    }
    if ($contentType === '') {
      return $this->neutralWithCacheability();
    }

    $gatedFields = $this->entitlementResolver->getGatedFields($level, $contentType);
    if (in_array($fieldName, $gatedFields, TRUE)) {
      return $this->forbiddenWithCacheability('This field is restricted to a higher license level.')
        ->addCacheTags($items !== NULL
          ? $items->getEntity()->getCacheTags()
          : []);
    }

    return $this->neutralWithCacheability();
  }

  /**
   * Checks private file download access.
   *
   * Returns -1 to deny, NULL to abstain.
   *
   * @return array|int|null
   *   -1 to deny access, or a neutral cacheability array (NULL-equivalent) to
   *   abstain.
   */
  public function checkFileDownload(string $uri): array|int|null {
    // Find the content type that owns this file by checking field references.
    // For each gating rule that enables gate_file_downloads, we need to check
    // whether the current user's level gates this file.
    $account = $this->currentUser;
    $level   = $this->licenseManager->getLevelForAccount($account);
    if ($level === 'no_access') {
      return -1;
    }

    // Query nodes that reference this URI via any file/image field.
    // If any referencing node's content type gates downloads for this level, deny.
    $contentTypes = $this->getContentTypesReferencingFile($uri);
    foreach ($contentTypes as $contentType) {
      if ($this->entitlementResolver->gatesFileDownloads($level, $contentType)) {
        // Deny.
        return -1;
      }
    }

    // Abstain.
    return NULL;
  }

  // --------------------------------------------------------------------------
  // Metered views (Task #7)
  // --------------------------------------------------------------------------

  /**
   * Returns the metered-view count for a user/content-type/period.
   */
  public function getMeteredViewCount(int $uid, string $contentType, string $period): int {
    try {
      return (int) $this->database
        ->select('license_service_meter', 'm')
        ->fields('m', ['view_count'])
        ->condition('uid', $uid)
        ->condition('content_type', $contentType)
        ->condition('period', $period)
        ->execute()
        ->fetchField();
    }
    catch (\Exception) {
      return 0;
    }
  }

  /**
   * Increments the metered-view counter for a user/content-type/period.
   */
  public function recordMeteredView(int $uid, string $contentType, string $period): void {
    try {
      $this->database->merge('license_service_meter')
        ->keys(['uid' => $uid, 'content_type' => $contentType, 'period' => $period])
        ->fields(['view_count' => 1, 'updated' => \Drupal::time()->getRequestTime()])
        ->expression('view_count', 'view_count + 1')
        ->execute();
    }
    catch (\Exception) {
      // Non-fatal: best-effort metering.
    }
  }

  // --------------------------------------------------------------------------
  // Quotas (Task #7)
  // --------------------------------------------------------------------------

  /**
   * Returns the current create/edit quota usage for a user/content-type/op.
   */
  public function getQuotaUsage(int $uid, string $contentType, string $operation): int {
    try {
      return (int) $this->database
        ->select('license_service_quota', 'q')
        ->fields('q', ['count'])
        ->condition('uid', $uid)
        ->condition('content_type', $contentType)
        ->condition('operation', $operation)
        ->execute()
        ->fetchField();
    }
    catch (\Exception) {
      return 0;
    }
  }

  /**
   * Increments the quota counter for a user/content-type/op.
   */
  public function recordQuotaUsage(int $uid, string $contentType, string $operation): void {
    try {
      $this->database->merge('license_service_quota')
        ->keys(['uid' => $uid, 'content_type' => $contentType, 'operation' => $operation])
        ->fields(['count' => 1, 'updated' => \Drupal::time()->getRequestTime()])
        ->expression('count', 'count + 1')
        ->execute();
    }
    catch (\Exception) {
      // Non-fatal.
    }
  }

  // --------------------------------------------------------------------------
  // Private helpers
  // --------------------------------------------------------------------------

  /**
   * Checks a metered view against the limit.
   *
   * Returns a forbidden result if the user has hit the view cap, NULL otherwise.
   * Recording the actual view happens in hook_node_view() so counters only
   * increment on genuine full-page renders, not on every access check.
   */
  protected function checkMeteredView(AccountInterface $account, string $contentType, string $level): ?AccessResultInterface {
    $limit = $this->entitlementResolver->getViewLimit($level, $contentType);
    if ($limit <= 0) {
      // Unlimited.
      return NULL;
    }

    $period    = $this->entitlementResolver->getViewPeriod($level, $contentType);
    $periodKey = $this->entitlementResolver->getCurrentPeriodKey($period);
    $uid       = (int) $account->id();
    $count     = $this->getMeteredViewCount($uid, $contentType, $periodKey);

    if ($count >= $limit) {
      return $this->forbiddenWithCacheability(
        "You have reached your {$period} view limit of {$limit} for this content type."
      )->addCacheContexts(['user']);
    }

    return NULL;
  }

  /**
   * Checks the edit quota for the account/contentType/level combination.
   *
   * Returns a forbidden result if over quota, NULL otherwise.
   */
  protected function checkEditQuota(AccountInterface $account, string $contentType, string $level): ?AccessResultInterface {
    $quota = $this->entitlementResolver->getEditQuota($level, $contentType);
    if ($quota <= 0) {
      // Unlimited.
      return NULL;
    }

    $uid  = (int) $account->id();
    $used = $this->getQuotaUsage($uid, $contentType, 'edit');
    if ($used >= $quota) {
      return $this->forbiddenWithCacheability(
        "Your license level limits you to {$quota} edits of this content type. You have reached the limit."
      )->addCacheContexts(['user']);
    }

    return NULL;
  }

  /**
   * Returns content type machine names that have file fields referencing the URI.
   *
   * Queries the managed_file table to find nodes that reference the given
   * private file, then returns the distinct bundle names. This is best-effort
   * for private-file gating; false negatives mean we abstain (NULL) rather
   * than incorrectly deny.
   *
   * @return string[]
   *   Distinct content type machine names that reference the given file URI.
   */
  protected function getContentTypesReferencingFile(string $uri): array {
    try {
      // Look up the fid for this URI.
      $fid = $this->database
        ->select('file_managed', 'f')
        ->fields('f', ['fid'])
        ->condition('uri', $uri)
        ->execute()
        ->fetchField();

      if (!$fid) {
        return [];
      }

      // file_usage records the referencing entity's TYPE id (e.g. 'node') and
      // its entity id — NOT the bundle. Fetch the (type, id) pairs, then load
      // the entities to resolve each one's bundle, which is what the
      // entitlement rules are keyed on.
      $rows = $this->database
        ->select('file_usage', 'fu')
        ->fields('fu', ['type', 'id'])
        ->condition('fid', $fid)
        ->execute()
        ->fetchAll();

      if (!$rows) {
        return [];
      }

      // Group entity ids by entity type id so we can load each type in bulk.
      $idsByType = [];
      foreach ($rows as $row) {
        $entityTypeId = (string) $row->type;
        $entityId     = (string) $row->id;
        if ($entityTypeId === '' || $entityId === '') {
          continue;
        }
        $idsByType[$entityTypeId][$entityId] = $entityId;
      }

      $bundles = [];
      foreach ($idsByType as $entityTypeId => $ids) {
        if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
          continue;
        }
        try {
          $entities = $this->entityTypeManager
            ->getStorage($entityTypeId)
            ->loadMultiple(array_values($ids));
        }
        catch (\Exception) {
          continue;
        }
        foreach ($entities as $entity) {
          $bundles[] = $entity->bundle();
        }
      }

      return array_values(array_unique(array_filter($bundles)));
    }
    catch (\Exception) {
      return [];
    }
  }

  /**
   * Returns a neutral AccessResult with the standard license gate cacheability.
   *
   * Used as a safe default. All real implementations must build on
   * this or equivalent — never return a bare AccessResult::neutral().
   */
  protected function neutralWithCacheability(): AccessResultInterface {
    return AccessResult::neutral()
      ->addCacheTags(['license_service'])
      ->addCacheContexts(['user.roles']);
  }

  /**
   * Returns a forbidden AccessResult with the standard license gate cacheability.
   */
  protected function forbiddenWithCacheability(string $reason = ''): AccessResultInterface {
    return AccessResult::forbidden($reason)
      ->addCacheTags(['license_service'])
      ->addCacheContexts(['user.roles']);
  }

}
