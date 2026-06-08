<?php

declare(strict_types=1);

namespace Drupal\license_service_subscriptions\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines the License Subscription Plan config entity.
 *
 * Maps one or more Commerce product variations to a license_service tier.
 * Drives role grants at subscription activation and the plan-deprecation
 * migration pipeline when the plan is deactivated.
 *
 * Author: Jeremiah Buttler.
 *
 * @ConfigEntityType(
 *   id = "license_subscription_plan",
 *   label = @Translation("Subscription Plan"),
 *   label_collection = @Translation("Subscription Plans"),
 *   label_singular = @Translation("subscription plan"),
 *   label_plural = @Translation("subscription plans"),
 *   label_count = @PluralTranslation(
 *     singular = "@count subscription plan",
 *     plural = "@count subscription plans",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\license_service_subscriptions\Entity\LicenseSubscriptionPlanListBuilder",
 *     "form" = {
 *       "add" = "Drupal\license_service_subscriptions\Form\LicenseSubscriptionPlanForm",
 *       "edit" = "Drupal\license_service_subscriptions\Form\LicenseSubscriptionPlanForm",
 *       "delete" = "Drupal\license_service_subscriptions\Form\LicenseSubscriptionPlanDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   config_prefix = "plan",
 *   admin_permission = "administer license subscriptions",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *   },
 *   links = {
 *     "collection" = "/admin/config/license-service/subscriptions/plans",
 *     "add-form" = "/admin/config/license-service/subscriptions/plans/add",
 *     "edit-form" = "/admin/config/license-service/subscriptions/plans/{license_subscription_plan}/edit",
 *     "delete-form" = "/admin/config/license-service/subscriptions/plans/{license_subscription_plan}/delete",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "uuid",
 *     "tier_id",
 *     "product_variation_ids",
 *     "active",
 *     "type",
 *     "fallback_tier",
 *   },
 * )
 */
class LicenseSubscriptionPlan extends ConfigEntityBase implements LicenseSubscriptionPlanInterface {

  /**
   * The license tier machine name this plan maps to.
   *
   * @var string
   */
  protected string $tier_id = '';

  /**
   * Commerce product variation IDs linked to this plan.
   *
   * Multiple variations (e.g. monthly + annual) may map to one plan; they
   * all confer the same tier entitlements.
   *
   * @var string[]
   */
  protected array $product_variation_ids = [];

  /**
   * Whether this plan accepts new subscriptions.
   *
   * FALSE triggers the deprecation migration pipeline at next renewal.
   *
   * @var bool
   */
  protected bool $active = TRUE;

  /**
   * Plan type: 'subscription' or 'perpetual'.
   *
   * Perpetual plans are never forcibly migrated; deactivation only blocks new
   * sales.
   *
   * @var string
   */
  protected string $type = 'subscription';

  /**
   * Per-plan fallback tier override.
   *
   * Empty string means use the module-level default_fallback_tier setting.
   *
   * @var string
   */
  protected string $fallback_tier = '';

  /**
   * {@inheritdoc}
   */
  public function getTierId(): string {
    return $this->tier_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getProductVariationIds(): array {
    return $this->product_variation_ids;
  }

  /**
   * {@inheritdoc}
   */
  public function isActive(): bool {
    return $this->active;
  }

  /**
   * {@inheritdoc}
   */
  public function getType(): string {
    return $this->type;
  }

  /**
   * {@inheritdoc}
   */
  public function getFallbackTier(): string {
    return $this->fallback_tier;
  }

  /**
   * {@inheritdoc}
   */
  public function isPerpetual(): bool {
    return $this->type === 'perpetual';
  }

}
