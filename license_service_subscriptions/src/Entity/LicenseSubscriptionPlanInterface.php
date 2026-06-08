<?php

declare(strict_types=1);

namespace Drupal\license_service_subscriptions\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Interface for the LicenseSubscriptionPlan config entity.
 *
 * A subscription plan maps one or more Commerce product variations to a
 * license_service tier. When a user purchases a variation that belongs to this
 * plan, they receive the roles/entitlements for the mapped tier. When the plan
 * is deactivated (active = FALSE), existing subscribers finish their current
 * period and are then migrated to the fallback tier.
 *
 * Author: Jeremiah Buttler.
 */
interface LicenseSubscriptionPlanInterface extends ConfigEntityInterface {

  /**
   * Returns the license tier machine name this plan maps to.
   *
   * @return string
   *   Tier machine name (matches a key in license_service.tiers config).
   */
  public function getTierId(): string;

  /**
   * Returns the Commerce product variation IDs linked to this plan.
   *
   * Multiple variations (e.g. monthly + annual billing cycles) may map to one
   * plan; role grants and tier entitlements are the same for all.
   *
   * @return string[]
   *   Array of commerce_product_variation entity IDs.
   */
  public function getProductVariationIds(): array;

  /**
   * Returns TRUE when this plan accepts new subscriptions.
   *
   * When FALSE, Commerce cart-add is blocked for all linked product variations
   * and the deprecation migration pipeline fires at the next renewal or after
   * the grace window.
   *
   * @return bool
   *   TRUE = plan is active; FALSE = plan is deprecated.
   */
  public function isActive(): bool;

  /**
   * Returns the plan type.
   *
   * @return string
   *   'subscription' or 'perpetual'. Perpetual plans are never subject to
   *   forced migration; deactivating them only blocks new sales.
   */
  public function getType(): string;

  /**
   * Returns the per-plan fallback tier override.
   *
   * @return string
   *   Tier machine name to use when this plan is deprecated, or an empty
   *   string to fall back to the module-level default_fallback_tier setting.
   */
  public function getFallbackTier(): string;

  /**
   * Returns TRUE when this plan is a perpetual / one-time purchase.
   *
   * @return bool
   *   TRUE for perpetual plans; FALSE for recurring subscriptions.
   */
  public function isPerpetual(): bool;

}
