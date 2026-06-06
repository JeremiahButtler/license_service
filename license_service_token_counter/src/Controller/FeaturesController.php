<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Renders the License Service Token Counter features list.
 *
 * Author: Jeremiah Buttler.
 */
final class FeaturesController extends ControllerBase {

  /**
   * Builds the features list page.
   */
  public function features(): array {
    $sections = [
      (string) $this->t('Usage Capture') => [
        [
          (string) $this->t('Provider-agnostic capture'),
          (string) $this->t('Records token usage from any AI provider connected to the AI module — OpenAI, Anthropic, Google/Gemini, Mistral, and any future provider — without per-provider configuration.'),
        ],
        [
          (string) $this->t('Automatic event capture'),
          (string) $this->t("Subscribes to the AI module's response events and records usage as each interaction completes, including streaming responses."),
        ],
        [
          (string) $this->t('Six token fields per record'),
          (string) $this->t("Stores input, output, cached, reasoning, and total tokens per call, using normalized getters where available and falling back to each provider's raw usage shape."),
        ],
        [
          (string) $this->t('Full call attribution'),
          (string) $this->t('Every record carries the initiating Drupal user, provider, model, operation type, request thread ID, route, and host module.'),
        ],
        [
          (string) $this->t('Operation-type allow-list'),
          (string) $this->t('Choose which AI operation types to capture — chat, embeddings, text-to-image, and others — or leave the list empty to capture everything.'),
        ],
        [
          (string) $this->t('Request deduplication'),
          (string) $this->t('Duplicate events for the same request thread are discarded so each AI interaction is counted exactly once.'),
        ],
      ],
      (string) $this->t('Cost Estimation') => [
        [
          (string) $this->t('Local, editable pricing table'),
          (string) $this->t('The Cost Engine carries its own pricing table in module configuration, with sensible defaults you can edit at Configuration › AI › Token Counter › Pricing. The License Server is never consulted for pricing — only for license status.'),
        ],
        [
          (string) $this->t('Per-provider and per-model rates'),
          (string) $this->t('Rates are defined at the provider+model level, with wildcard fallback to provider-wide or site-wide defaults.'),
        ],
        [
          (string) $this->t('Differentiated token billing'),
          (string) $this->t('Cached and reasoning tokens can carry separate rates from standard input and output tokens, matching the billing models of providers that price them independently.'),
        ],
        [
          (string) $this->t('Cost status badges'),
          (string) $this->t('Each record shows a status: Computed (cost calculated), Locked (license not active), or No price (no rate defined for this provider/model).'),
        ],
        [
          (string) $this->t('Configurable display currency'),
          (string) $this->t('The ISO currency code shown alongside cost estimates is configurable on the settings page.'),
        ],
      ],
      (string) $this->t('Usage Report') => [
        [
          (string) $this->t('Admin usage report'),
          (string) $this->t('A report at Reports › AI token usage shows summary cards and a paged table of individual records.'),
        ],
        [
          (string) $this->t('Summary statistics'),
          (string) $this->t('Cards summarise total AI calls, total tokens, input tokens, output tokens, and estimated cost at a glance.'),
        ],
        [
          (string) $this->t('Paged records table'),
          (string) $this->t('Records listed 50 per page, sorted newest-first, with provider, model, operation type, token counts, and cost status per row.'),
        ],
        [
          (string) $this->t('Per-user scoping'),
          (string) $this->t('Users with "view own AI token usage" see only their own records; "view AI token usage reports" grants the full site-wide view.'),
        ],
        [
          (string) $this->t('License status banner'),
          (string) $this->t('When the License Module is present but the license is inactive, or it carries warnings, a notice appears at the top of the report. Standalone sites with no License Module see no banner.'),
        ],
      ],
      (string) $this->t('Administration') => [
        [
          (string) $this->t('Capture toggle'),
          (string) $this->t('Token capture can be enabled or disabled without uninstalling the module; existing records are preserved.'),
        ],
        [
          (string) $this->t('Operation-type filter'),
          (string) $this->t('Restrict capture to specific AI operation types (one per line) or leave the setting empty to capture every type.'),
        ],
        [
          (string) $this->t('Configurable retention window'),
          (string) $this->t('Set a maximum age in days for usage records; older rows are deleted automatically during cron. Set to 0 to keep records indefinitely.'),
        ],
        [
          (string) $this->t('Cron-based cleanup'),
          (string) $this->t('The retention window is enforced during each Drupal cron run with a single parameterised database delete.'),
        ],
        [
          (string) $this->t('Typed configuration schema'),
          (string) $this->t("All settings are backed by a typed configuration schema and installation defaults, compatible with Drupal's configuration management workflow."),
        ],
      ],
      (string) $this->t('Permissions') => [
        [
          (string) $this->t('"Administer License Service Token Counter"'),
          (string) $this->t('Full access to settings and module configuration. Marked restrict-access — grant to administrators only.'),
        ],
        [
          (string) $this->t('"View AI token usage reports"'),
          (string) $this->t('Access to the full site-wide usage report. Marked restrict-access.'),
        ],
        [
          (string) $this->t('"View own AI token usage"'),
          (string) $this->t("Access to the usage report scoped to the current user's records only."),
        ],
      ],
      (string) $this->t('Site Status') => [
        [
          (string) $this->t('License status requirement'),
          (string) $this->t('The Drupal status report surfaces whether the License Service Token Counter license is active, with guidance when it is not.'),
        ],
        [
          (string) $this->t('License warnings'),
          (string) $this->t('Warnings from the License Module (expiring soon, offline grace) are surfaced on the usage report and on the status report.'),
        ],
      ],
      (string) $this->t('Licensing') => [
        [
          (string) $this->t('Standalone token counting'),
          (string) $this->t('Token capture requires no license; the base module records usage on its own.'),
        ],
        [
          (string) $this->t('License Module integration'),
          (string) $this->t('The optional Cost Engine integrates with the License Module (license_service) and an active subscription to the License Verification Server to unlock cost estimation.'),
        ],
        [
          (string) $this->t('Cost gating'),
          (string) $this->t('Without an active license cost is locked; token capture is unaffected and existing records are never deleted.'),
        ],
        [
          (string) $this->t('Self-contained operation'),
          (string) $this->t('Once licensed, the module does all of its work locally: it captures usage, holds its own pricing table, and computes cost on-site. The License Server only confirms the license is active — it does not run the module.'),
        ],
      ],
    ];

    $build = [
      '#attached' => ['library' => ['license_service_token_counter/features']],
    ];

    $build['search'] = [
      '#type' => 'html_tag',
      '#tag' => 'input',
      '#attributes' => [
        'type' => 'search',
        'id' => 'license-service-token-counter-features-search',
        'placeholder' => $this->t('Search features…'),
        'class' => ['license-service-token-counter-features-search'],
        'aria-label' => $this->t('Search features'),
        'autocomplete' => 'off',
      ],
    ];

    $build['list'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'license-service-token-counter-features-list'],
    ];

    foreach ($sections as $heading => $items) {
      $section_key = 'section-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($heading));
      $build['list'][$section_key] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['license-service-token-counter-features-section']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $heading,
          '#attributes' => ['class' => ['license-service-token-counter-features-heading']],
        ],
      ];

      foreach ($items as $index => [$name, $description]) {
        $build['list'][$section_key]['item_' . $index] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['license-service-token-counter-feature-item']],
          'name' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $name,
            '#attributes' => ['class' => ['license-service-token-counter-feature-name']],
          ],
          'sep' => ['#markup' => ' — '],
          'desc' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $description,
            '#attributes' => ['class' => ['license-service-token-counter-feature-desc']],
          ],
        ];
      }
    }

    // User-visible owner copyright notice (subordinate; never the author credit).
    $build['copyright'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => '&copy; ' . date('Y') . ' AideaMaker LLC',
      '#attributes' => ['class' => ['license-service-token-counter-features-copyright']],
    ];

    return $build;
  }

}
