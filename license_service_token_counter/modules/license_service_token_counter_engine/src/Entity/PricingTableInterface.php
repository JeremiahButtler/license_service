<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter_engine\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Interface for the pricing_table config entity.
 *
 * Author: Jeremiah Buttler.
 */
interface PricingTableInterface extends ConfigEntityInterface {

  /**
   * Provider wildcard: matches any provider not otherwise covered.
   */
  public const PROVIDER_WILDCARD = '*';

  /**
   * Model wildcard: matches any model within a provider.
   */
  public const MODEL_WILDCARD = '*';

  /**
   * Default tokens per priced unit (1,000,000).
   */
  public const DEFAULT_UNIT = 1000000;

  /**
   * Returns the drupal/ai provider id this table prices.
   *
   * Returns '*' for a catch-all fallback that covers any provider not
   * explicitly priced by another enabled table.
   */
  public function getProvider(): string;

  /**
   * Returns the number of tokens per priced unit.
   */
  public function getUnit(): int;

  /**
   * Returns the rate rows for this table.
   *
   * Each row is an array with keys:
   *   - model: string — model id or '*' for a provider-wide fallback.
   *   - input: float — input-token rate per unit.
   *   - output: float — output-token rate per unit.
   *   - cached: float|null — cached-input rate (NULL = fall back to input rate).
   *   - reasoning: float|null — reasoning-token rate (NULL = fall back to output).
   *
   * @return array<int, array{model:string,input:float,output:float,cached:float|null,reasoning:float|null}>
   *   Indexed list of rate rows for this pricing table.
   */
  public function getRates(): array;

  /**
   * Returns the display weight used to order tables in the admin list.
   */
  public function getWeight(): int;

}
