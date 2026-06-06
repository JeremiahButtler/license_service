<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Provides currency options and the current default for the settings form.
 *
 * When Drupal Commerce is installed, available currencies are sourced from
 * commerce_currency config entities and the default is the default store's
 * currency. Falls back to a curated static ISO list when Commerce is absent.
 *
 * Author: Jeremiah Buttler.
 */
final class CurrencyHelper {

  /**
   * Curated static currency list used when Commerce is not installed.
   *
   * @var array<string, string>  currency_code => human-readable label.
   */
  private const FALLBACK_CURRENCIES = [
    'USD' => 'US Dollar (USD)',
    'EUR' => 'Euro (EUR)',
    'GBP' => 'British Pound (GBP)',
    'CAD' => 'Canadian Dollar (CAD)',
    'AUD' => 'Australian Dollar (AUD)',
    'JPY' => 'Japanese Yen (JPY)',
    'CHF' => 'Swiss Franc (CHF)',
    'CNY' => 'Chinese Yuan (CNY)',
    'INR' => 'Indian Rupee (INR)',
    'MXN' => 'Mexican Peso (MXN)',
    'BRL' => 'Brazilian Real (BRL)',
    'NZD' => 'New Zealand Dollar (NZD)',
    'SEK' => 'Swedish Krona (SEK)',
    'NOK' => 'Norwegian Krone (NOK)',
    'DKK' => 'Danish Krone (DKK)',
    'SGD' => 'Singapore Dollar (SGD)',
    'HKD' => 'Hong Kong Dollar (HKD)',
    'KRW' => 'South Korean Won (KRW)',
    'ZAR' => 'South African Rand (ZAR)',
  ];

  /**
   * Constructs the helper.
   */
  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns a key-value array of currency options for a select widget.
   *
   * @return array<string, string>
   *   Currency code => human-readable label.
   */
  public function getCurrencyOptions(): array {
    if ($this->moduleHandler->moduleExists('commerce_price')) {
      try {
        $currencies = $this->entityTypeManager
          ->getStorage('commerce_currency')
          ->loadMultiple();
        if (!empty($currencies)) {
          $options = [];
          foreach ($currencies as $currency) {
            /** @var \Drupal\commerce_price\Entity\CurrencyInterface $currency */
            $code           = $currency->getCurrencyCode();
            $options[$code] = $currency->getName() . ' (' . $code . ')';
          }
          asort($options);
          return $options;
        }
      }
      catch (\Throwable) {
        // Fall through to the static list.
      }
    }
    return self::FALLBACK_CURRENCIES;
  }

  /**
   * Returns the default currency code for this site.
   *
   * When Commerce is present, the first default store's currency is used.
   * Falls back to the configured license_service_token_counter display_currency, then USD.
   */
  public function getDefaultCurrency(): string {
    if ($this->moduleHandler->moduleExists('commerce_store')) {
      try {
        $stores = $this->entityTypeManager
          ->getStorage('commerce_store')
          ->loadMultiple();
        foreach ($stores as $store) {
          /** @var \Drupal\commerce_store\Entity\StoreInterface $store */
          $code = $store->getDefaultCurrencyCode();
          if ($code !== NULL && $code !== '') {
            return (string) $code;
          }
        }
      }
      catch (\Throwable) {
        // Fall through.
      }
    }
    $configured = (string) $this->configFactory
      ->get('license_service_token_counter.settings')
      ->get('display_currency');
    return $configured !== '' ? $configured : 'USD';
  }

}
