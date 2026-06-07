<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * A named quota rule: token amount, period, and the scope it applies to.
 *
 * Each rule captures exactly one limit: for example "authenticated users may
 * use 50,000 tokens per month" or "the Staff role may use 500,000 per year."
 * The site administrator creates as many rules as needed; all enabled rules
 * that apply to a user are evaluated, and any exceeded rule triggers enforcement.
 *
 * Author: Jeremiah Buttler.
 *
 * @ConfigEntityType(
 *   id = "token_limit",
 *   label = @Translation("Token limit"),
 *   label_collection = @Translation("Token limits"),
 *   label_singular = @Translation("token limit"),
 *   label_plural = @Translation("token limits"),
 *   label_count = @PluralTranslation(
 *     singular = "@count token limit",
 *     plural = "@count token limits",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\license_service_token_counter\Entity\TokenLimitListBuilder",
 *     "form" = {
 *       "add" = "Drupal\license_service_token_counter\Form\TokenLimitForm",
 *       "edit" = "Drupal\license_service_token_counter\Form\TokenLimitForm",
 *       "delete" = "Drupal\license_service_token_counter\Form\TokenLimitDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   admin_permission = "administer ai token usage limits",
 *   config_prefix = "token_limit",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "status" = "status",
 *     "weight" = "weight",
 *   },
 *   links = {
 *     "collection" = "/admin/config/ai/token-counter/limits",
 *     "add-form" = "/admin/config/ai/token-counter/limits/add",
 *     "edit-form" = "/admin/config/ai/token-counter/limits/{token_limit}/edit",
 *     "delete-form" = "/admin/config/ai/token-counter/limits/{token_limit}/delete",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "status",
 *     "weight",
 *     "scope_type",
 *     "role_id",
 *     "level_id",
 *     "amount",
 *     "period",
 *   }
 * )
 */
class TokenLimit extends ConfigEntityBase implements TokenLimitInterface {

  /**
   * The entity machine name.
   */
  protected string $id = '';

  /**
   * The human-readable label.
   */
  protected string $label = '';

  /**
   * Display weight (lower = appears higher in the admin list).
   */
  protected int $weight = 0;

  /**
   * Scope type: 'role', 'all_users', or 'site_total'.
   */
  protected string $scope_type = self::SCOPE_ROLE;

  /**
   * Role machine name (only used when scope_type = 'role').
   */
  protected string $role_id = '';

  /**
   * License level id (only used when scope_type = 'level').
   */
  protected string $level_id = '';

  /**
   * Token limit amount. 0 means unlimited.
   */
  protected int $amount = 0;

  /**
   * Period key: day, week, month, year, or lifetime.
   */
  protected string $period = 'month';

  /**
   * {@inheritdoc}
   */
  public static function scopeLabels(): array {
    return [
      self::SCOPE_ROLE        => 'Per role (each user in the role gets their own limit)',
      self::SCOPE_ALL_USERS   => 'All authenticated users (each user gets their own limit)',
      self::SCOPE_SITE_TOTAL  => 'Site total (a single shared pool for the entire site)',
      self::SCOPE_LEVEL       => 'Per license level (each user at the chosen level gets their own limit)',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getScopeType(): string {
    return $this->scope_type;
  }

  /**
   * {@inheritdoc}
   */
  public function getRoleId(): string {
    return $this->role_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getLevelId(): string {
    return $this->level_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getAmount(): int {
    return $this->amount;
  }

  /**
   * {@inheritdoc}
   */
  public function getPeriod(): string {
    return $this->period;
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight(): int {
    return $this->weight;
  }

}
