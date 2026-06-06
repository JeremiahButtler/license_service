<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter_engine\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * A named cost rate table for one AI provider.
 *
 * Each table targets a single drupal/ai provider (e.g. "openai") and holds
 * per-model token rates. Administrators can add, edit, duplicate, enable, and
 * delete tables, and apply different tables to different providers. The cost
 * engine uses only the enabled tables; rates are resolved with wildcard
 * fallback: provider/model → provider/'*' → '*'/model → '*'/'*'.
 *
 * Author: Jeremiah Buttler.
 *
 * @ConfigEntityType(
 *   id = "pricing_table",
 *   label = @Translation("Pricing table"),
 *   label_collection = @Translation("Pricing tables"),
 *   label_singular = @Translation("pricing table"),
 *   label_plural = @Translation("pricing tables"),
 *   label_count = @PluralTranslation(
 *     singular = "@count pricing table",
 *     plural = "@count pricing tables",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\license_service_token_counter_engine\Entity\PricingTableListBuilder",
 *     "form" = {
 *       "add"       = "Drupal\license_service_token_counter_engine\Form\PricingTableForm",
 *       "edit"      = "Drupal\license_service_token_counter_engine\Form\PricingTableForm",
 *       "delete"    = "Drupal\license_service_token_counter_engine\Form\PricingTableDeleteForm",
 *       "duplicate" = "Drupal\license_service_token_counter_engine\Form\PricingTableDuplicateForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   admin_permission = "administer ai token counter",
 *   config_prefix = "pricing_table",
 *   entity_keys = {
 *     "id"     = "id",
 *     "label"  = "label",
 *     "status" = "status",
 *     "weight" = "weight",
 *   },
 *   links = {
 *     "collection"     = "/admin/config/ai/token-counter/pricing",
 *     "add-form"       = "/admin/config/ai/token-counter/pricing/add",
 *     "edit-form"      = "/admin/config/ai/token-counter/pricing/{pricing_table}/edit",
 *     "delete-form"    = "/admin/config/ai/token-counter/pricing/{pricing_table}/delete",
 *     "duplicate-form" = "/admin/config/ai/token-counter/pricing/{pricing_table}/duplicate",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "status",
 *     "weight",
 *     "provider",
 *     "unit",
 *     "rates",
 *   }
 * )
 */
class PricingTable extends ConfigEntityBase implements PricingTableInterface {

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
   * The drupal/ai provider id (e.g. 'openai') or '*' for fallback.
   */
  protected string $provider = self::PROVIDER_WILDCARD;

  /**
   * Tokens per priced unit (default 1,000,000).
   */
  protected int $unit = self::DEFAULT_UNIT;

  /**
   * Rate rows: sequence of {model, input, output, cached, reasoning}.
   *
   * @var array<int, array{model:string,input:float,output:float,cached:float|null,reasoning:float|null}>
   */
  protected array $rates = [];

  /**
   * {@inheritdoc}
   */
  public function getProvider(): string {
    return $this->provider ?: self::PROVIDER_WILDCARD;
  }

  /**
   * {@inheritdoc}
   */
  public function getUnit(): int {
    $unit = $this->unit ?: self::DEFAULT_UNIT;
    return $unit > 0 ? $unit : self::DEFAULT_UNIT;
  }

  /**
   * {@inheritdoc}
   */
  public function getRates(): array {
    return $this->rates;
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight(): int {
    return $this->weight;
  }

}
