<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter_engine\Pricing;

/**
 * Bundled default pricing for known drupal/ai provider plugins.
 *
 * These rates are best-effort estimates verified at module release time.
 * AI providers change their model offerings and prices frequently — always
 * verify against each provider's current pricing page before relying on
 * these figures for billing or budget planning.
 *
 * Each entry is a list of rate rows compatible with the PricingTable entity's
 * `rates` field: { model, input, output, cached?, reasoning? }. All amounts
 * are in USD per 1,000,000 tokens (unit = 1000000).
 *
 * Keys correspond to drupal/ai provider plugin IDs. If a provider is installed
 * under a different ID, the row will not auto-seed but can still be added
 * manually via the pricing table admin UI.
 *
 * Author: Jeremiah Buttler.
 */
final class DefaultPricing {

  /**
   * Returns the default rate rows for a drupal/ai provider, or NULL if unknown.
   *
   * @param string $providerId
   *   The drupal/ai provider plugin id (e.g. 'openai').
   *
   * @return list<array{model:string,input:float,output:float,cached:float|null,reasoning:float|null}>|null
   *   Rate rows for the provider, or NULL when no bundled data is available.
   */
  public static function ratesForProvider(string $providerId): ?array {
    return self::RATES[$providerId] ?? NULL;
  }

  /**
   * Returns every provider id that has bundled default rates.
   *
   * @return list<string>
   *   Indexed list of provider IDs that have bundled rate data.
   */
  public static function allProviders(): array {
    return array_keys(self::RATES);
  }

  // ---------------------------------------------------------------------------
  // Bundled rate data — per 1,000,000 tokens, USD.
  // Verified against provider pricing pages, 2025–2026.
  // Each provider array ends with a '*' wildcard row as a catch-all fallback.
  // ---------------------------------------------------------------------------

  /**
   * Bundled rate data indexed by provider ID.
   *
   * @var array<string, list<array{model:string,input:float,output:float,cached:float|null,reasoning:float|null}>>
   */
  private const RATES = [

    // -------------------------------------------------------------------------
    // OpenAI  (platform.openai.com/docs/pricing)
    // -------------------------------------------------------------------------
    'openai' => [
      ['model' => 'gpt-4o', 'input' => 2.5, 'output' => 10.0, 'cached' => 1.25, 'reasoning' => NULL],
      ['model' => 'gpt-4o-mini', 'input' => 0.15, 'output' => 0.6, 'cached' => 0.075, 'reasoning' => NULL],
      ['model' => 'gpt-4.1', 'input' => 2.0, 'output' => 8.0, 'cached' => 0.5, 'reasoning' => NULL],
      ['model' => 'gpt-4.1-mini', 'input' => 0.4, 'output' => 1.6, 'cached' => 0.1, 'reasoning' => NULL],
      ['model' => 'o1', 'input' => 15.0, 'output' => 60.0, 'cached' => NULL, 'reasoning' => 60.0],
      ['model' => 'o3-mini', 'input' => 1.1, 'output' => 4.4, 'cached' => NULL, 'reasoning' => 4.4],
      ['model' => '*', 'input' => 2.5, 'output' => 10.0, 'cached' => NULL, 'reasoning' => NULL],
    ],

    // -------------------------------------------------------------------------
    // Anthropic  (anthropic.com/pricing)
    // -------------------------------------------------------------------------
    'anthropic' => [
      ['model' => 'claude-sonnet-4-5', 'input' => 3.0, 'output' => 15.0, 'cached' => 0.3, 'reasoning' => NULL],
      ['model' => 'claude-3-5-sonnet', 'input' => 3.0, 'output' => 15.0, 'cached' => 0.3, 'reasoning' => NULL],
      ['model' => 'claude-3-5-haiku', 'input' => 0.8, 'output' => 4.0, 'cached' => 0.08, 'reasoning' => NULL],
      ['model' => 'claude-3-opus', 'input' => 15.0, 'output' => 75.0, 'cached' => 1.5, 'reasoning' => NULL],
      ['model' => '*', 'input' => 3.0, 'output' => 15.0, 'cached' => NULL, 'reasoning' => NULL],
    ],

    // -------------------------------------------------------------------------
    // Google Gemini  (ai.google.dev/pricing)
    // drupal/ai provider plugin id is 'gemini'.
    // -------------------------------------------------------------------------
    'gemini' => [
      ['model' => 'gemini-1.5-pro', 'input' => 1.25, 'output' => 5.0, 'cached' => NULL, 'reasoning' => NULL],
      ['model' => 'gemini-1.5-flash', 'input' => 0.075, 'output' => 0.3, 'cached' => NULL, 'reasoning' => NULL],
      ['model' => 'gemini-2.0-flash', 'input' => 0.1, 'output' => 0.4, 'cached' => NULL, 'reasoning' => NULL],
      ['model' => '*', 'input' => 0.1, 'output' => 0.4, 'cached' => NULL, 'reasoning' => NULL],
    ],

    // -------------------------------------------------------------------------
    // Mistral AI  (mistral.ai/technology/#pricing)
    // -------------------------------------------------------------------------
    'mistral' => [
      ['model' => 'mistral-large', 'input' => 2.0, 'output' => 6.0, 'cached' => NULL, 'reasoning' => NULL],
      ['model' => 'mistral-small', 'input' => 0.2, 'output' => 0.6, 'cached' => NULL, 'reasoning' => NULL],
      ['model' => '*', 'input' => 2.0, 'output' => 6.0, 'cached' => NULL, 'reasoning' => NULL],
    ],

    // -------------------------------------------------------------------------
    // Groq  (groq.com/pricing)
    // -------------------------------------------------------------------------
    'groq' => [
      ['model' => 'llama-3.3-70b-versatile', 'input' => 0.59, 'output' => 0.79, 'cached' => NULL, 'reasoning' => NULL],
      ['model' => 'llama-3.1-8b-instant', 'input' => 0.05, 'output' => 0.08, 'cached' => NULL, 'reasoning' => NULL],
      ['model' => 'gemma2-9b-it', 'input' => 0.20, 'output' => 0.20, 'cached' => NULL, 'reasoning' => NULL],
      ['model' => 'mixtral-8x7b-32768', 'input' => 0.24, 'output' => 0.24, 'cached' => NULL, 'reasoning' => NULL],
      ['model' => '*', 'input' => 0.59, 'output' => 0.79, 'cached' => NULL, 'reasoning' => NULL],
    ],

    // -------------------------------------------------------------------------
    // AWS Bedrock  (aws.amazon.com/bedrock/pricing)
    // drupal/ai provider plugin id is 'bedrock' (module: ai_provider_aws_bedrock).
    // On-demand rates, us-east-1. Model IDs include the version suffix.
    // -------------------------------------------------------------------------
    'bedrock' => [
      [
        'model' => 'amazon.nova-pro-v1:0',
        'input' => 0.80,
        'output' => 3.20,
        'cached' => NULL,
        'reasoning' => NULL,
      ],
      [
        'model' => 'amazon.nova-lite-v1:0',
        'input' => 0.06,
        'output' => 0.24,
        'cached' => NULL,
        'reasoning' => NULL,
      ],
      [
        'model' => 'amazon.nova-micro-v1:0',
        'input' => 0.035,
        'output' => 0.14,
        'cached' => NULL,
        'reasoning' => NULL,
      ],
      [
        'model' => 'anthropic.claude-3-5-sonnet-20241022-v2:0',
        'input' => 3.0,
        'output' => 15.0,
        'cached' => NULL,
        'reasoning' => NULL,
      ],
      [
        'model' => 'anthropic.claude-3-haiku-20240307-v1:0',
        'input' => 0.80,
        'output' => 4.0,
        'cached' => NULL,
        'reasoning' => NULL,
      ],
      [
        'model' => 'meta.llama3-3-70b-instruct-v1:0',
        'input' => 0.72,
        'output' => 0.72,
        'cached' => NULL,
        'reasoning' => NULL,
      ],
      [
        'model' => '*',
        'input' => 0.80,
        'output' => 3.20,
        'cached' => NULL,
        'reasoning' => NULL,
      ],
    ],

    // -------------------------------------------------------------------------
    // Fireworks AI  (fireworks.ai/pricing)
    // Cached rates are ~50% of input (Fireworks context-caching discount).
    // -------------------------------------------------------------------------
    'fireworks' => [
      [
        'model' => 'accounts/fireworks/models/llama-v3p1-70b-instruct',
        'input' => 0.90,
        'output' => 0.90,
        'cached' => 0.45,
        'reasoning' => NULL,
      ],
      [
        'model' => 'accounts/fireworks/models/llama-v3p1-8b-instruct',
        'input' => 0.20,
        'output' => 0.20,
        'cached' => 0.10,
        'reasoning' => NULL,
      ],
      [
        'model' => 'accounts/fireworks/models/llama-v3p1-405b-instruct',
        'input' => 3.0,
        'output' => 3.0,
        'cached' => 1.50,
        'reasoning' => NULL,
      ],
      [
        'model' => 'accounts/fireworks/models/deepseek-v3',
        'input' => 0.90,
        'output' => 0.90,
        'cached' => 0.45,
        'reasoning' => NULL,
      ],
      [
        'model' => '*',
        'input' => 0.90,
        'output' => 0.90,
        'cached' => NULL,
        'reasoning' => NULL,
      ],
    ],

  ];

}
