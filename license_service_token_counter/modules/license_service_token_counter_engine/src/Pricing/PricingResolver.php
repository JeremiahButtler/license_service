<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter_engine\Pricing;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\license_service_token_counter_engine\Entity\PricingTableInterface;

/**
 * Resolves per-provider/per-model pricing from enabled PricingTable entities.
 *
 * Pricing is owned entirely by this module via PricingTable config entities.
 * Each enabled table targets one provider and holds per-model rate rows. On
 * first call, the resolver loads all enabled tables (sorted weight ASC) and
 * builds a flat lookup map using first-write-wins per provider/model pair.
 * The map is memoized for the lifetime of the request.
 *
 * Rate resolution uses wildcard fallback in priority order:
 *   exact provider + exact model → exact provider + '*'
 *   → '*' + exact model          → '*' + '*'
 *
 * Absent cached/reasoning fields fall back to the input/output rate so
 * callers always receive numeric values and can compute cost unconditionally.
 *
 * The returned 'unit' key is per-table, not global — callers must use it when
 * dividing the raw token-count product to get the currency amount.
 *
 * The License Verification Server is NOT consulted for pricing; it only
 * verifies license status.
 *
 * Author: Jeremiah Buttler.
 */
final class PricingResolver {

  /**
   * Per-request memoized rate map.
   *
   * Provider_id → model_id → { input, output, cached, reasoning, unit }
   *
   * @var array<string, array<string, array{input:float,output:float,cached:float,reasoning:float,unit:int}>>|null
   */
  private ?array $map = NULL;

  /**
   * Constructs the resolver.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The display currency code (e.g. 'USD').
   */
  public function currency(): string {
    $currency = (string) $this->configFactory
      ->get('license_service_token_counter.settings')
      ->get('display_currency');
    return $currency !== '' ? $currency : 'USD';
  }

  /**
   * Returns the price rates for a provider/model pair, or NULL when unpriced.
   *
   * The returned array includes a 'unit' key (tokens per priced unit) that the
   * caller must use when computing the currency amount — units are per-table
   * and no longer a single global setting.
   *
   * @param string $providerId
   *   The drupal/ai provider plugin id (e.g. 'openai').
   * @param string $modelId
   *   The model identifier (e.g. 'gpt-4o').
   *
   * @return array{input:float,output:float,cached:float,reasoning:float,unit:int}|null
   *   Fully-normalised rates including the token unit, or NULL when unpriced.
   */
  public function rates(string $providerId, string $modelId): ?array {
    return $this->lookup($this->buildMap(), $providerId, $modelId);
  }

  /**
   * Builds and memoises the flat rate map from all enabled PricingTable entities.
   *
   * Tables are iterated weight ASC; first-write-wins per provider/model pair.
   *
   * @return array<string, array<string, array{input:float,output:float,cached:float,reasoning:float,unit:int}>>
   *   Nested map of provider => model => normalised rate row.
   */
  private function buildMap(): array {
    if ($this->map !== NULL) {
      return $this->map;
    }

    $this->map = [];
    $storage   = $this->entityTypeManager->getStorage('pricing_table');

    $ids = $storage->getQuery()
      ->condition('status', TRUE)
      ->sort('weight', 'ASC')
      ->accessCheck(FALSE)
      ->execute();

    if (empty($ids)) {
      return $this->map;
    }

    /** @var \Drupal\license_service_token_counter_engine\Entity\PricingTableInterface[] $tables */
    $tables = $storage->loadMultiple($ids);

    // loadMultiple() does not guarantee the query sort order — re-sort by weight.
    uasort($tables, static fn(PricingTableInterface $a, PricingTableInterface $b): int
      => $a->getWeight() <=> $b->getWeight()
    );

    foreach ($tables as $table) {
      $provider = $table->getProvider();
      $unit     = $table->getUnit();

      foreach ($table->getRates() as $row) {
        $model = (string) $row['model'];
        if ($model === '') {
          $model = PricingTableInterface::MODEL_WILDCARD;
        }

        // First-write-wins: a higher-priority table already claimed this slot.
        if (isset($this->map[$provider][$model])) {
          continue;
        }

        $this->map[$provider][$model] = $this->normalizeRow($row, $unit);
      }
    }

    return $this->map;
  }

  /**
   * Normalises a raw rate row into a fully-typed entry with a unit.
   *
   * Absent optional fields fall back to input/output so callers can multiply
   * token counts unconditionally without extra null checks.
   *
   * @return array{input:float,output:float,cached:float,reasoning:float,unit:int}
   *   Normalised rate row with all optional fields resolved.
   */
  private function normalizeRow(array $row, int $unit): array {
    $input  = isset($row['input'])  && is_numeric($row['input']) ? (float) $row['input'] : 0.0;
    $output = isset($row['output']) && is_numeric($row['output']) ? (float) $row['output'] : 0.0;
    return [
      'input'     => $input,
      'output'    => $output,
      'cached'    => isset($row['cached'])    && is_numeric($row['cached']) ? (float) $row['cached'] : $input,
      'reasoning' => isset($row['reasoning']) && is_numeric($row['reasoning']) ? (float) $row['reasoning'] : $output,
      'unit'      => $unit,
    ];
  }

  /**
   * Looks up a rate entry with provider/model wildcard fallback.
   *
   * Priority: exact/exact → exact/'*' → '*'/exact → '*'/'*'.
   */
  private function lookup(array $map, string $providerId, string $modelId): ?array {
    $providerKeys = [$providerId, PricingTableInterface::PROVIDER_WILDCARD];
    $modelKeys    = [$modelId, PricingTableInterface::MODEL_WILDCARD];

    foreach ($providerKeys as $pkey) {
      if (!isset($map[$pkey]) || !is_array($map[$pkey])) {
        continue;
      }
      foreach ($modelKeys as $mkey) {
        if (isset($map[$pkey][$mkey])) {
          return $map[$pkey][$mkey];
        }
      }
    }

    return NULL;
  }

}
