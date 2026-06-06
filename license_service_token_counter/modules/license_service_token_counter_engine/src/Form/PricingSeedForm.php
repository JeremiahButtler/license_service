<?php

declare(strict_types=1);

namespace Drupal\license_service_token_counter_engine\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\license_service_token_counter_engine\Pricing\DefaultPricing;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Seeds default pricing tables for all currently-enabled AI providers.
 *
 * Enumerates the site's configured drupal/ai providers and creates a pricing
 * table for each that does not already have one. Providers with bundled rate
 * data use those defaults; all others receive a single wildcard placeholder
 * row (priced at 0) that the administrator can fill in.
 *
 * Existing tables are never modified.
 *
 * Author: Jeremiah Buttler.
 */
final class PricingSeedForm extends ConfirmFormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'license_service_token_counter_engine_pricing_seed';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Seed default pricing tables for enabled AI providers?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    $providers = $this->getEnabledProviders();
    if (empty($providers)) {
      return $this->t(
        'No enabled AI providers were found. Configure your providers under <em>Configuration › AI › AI Settings</em> first, then return here to seed their pricing tables.'
      );
    }
    return $this->t(
      'Tables will be created for the following enabled providers: <strong>@list</strong>. Providers that already have a table will be skipped.',
      ['@list' => implode(', ', array_keys($providers))]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('entity.pricing_table.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $providers = $this->getEnabledProviders();
    $storage   = $this->entityTypeManager->getStorage('pricing_table');
    $created   = 0;
    $skipped   = 0;

    foreach ($providers as $providerId => $label) {
      // Skip if any table already targets this provider.
      $existing = $storage->getQuery()
        ->condition('provider', $providerId)
        ->accessCheck(FALSE)
        ->execute();

      if (!empty($existing)) {
        $skipped++;
        continue;
      }

      $rates = DefaultPricing::ratesForProvider($providerId) ?? [
        // Placeholder: a single unpriced wildcard row; admin fills in rates.
        ['model' => '*', 'input' => 0.0, 'output' => 0.0, 'cached' => NULL, 'reasoning' => NULL],
      ];

      // Sanitize the provider id to a valid machine name.
      $id = preg_replace('/[^a-z0-9_]/', '_', strtolower($providerId));

      /** @var \Drupal\license_service_token_counter_engine\Entity\PricingTableInterface $table */
      $table = $storage->create([
        'id'       => $id,
        'label'    => $label . ' pricing',
        'status'   => TRUE,
        'weight'   => 0,
        'provider' => $providerId,
        'unit'     => 1000000,
        'rates'    => $rates,
      ]);
      $table->save();
      $created++;
    }

    if ($created > 0) {
      $this->messenger()->addStatus(
        $this->t('Created @count new pricing table(s).', ['@count' => $created])
      );
    }
    if ($skipped > 0) {
      $this->messenger()->addStatus(
        $this->t('Skipped @count provider(s) — tables already exist for them.', ['@count' => $skipped])
      );
    }
    if ($created === 0 && $skipped === 0) {
      $this->messenger()->addWarning(
        $this->t('No providers were found to seed. Check that at least one AI provider is configured and active.')
      );
    }

    $form_state->setRedirect('entity.pricing_table.collection');
  }

  /**
   * Returns the enabled, configured AI providers keyed by provider id.
   *
   * Uses the drupal/ai provider plugin manager to find providers that have
   * credentials configured and are usable for at least one operation type.
   *
   * @return array<string, string>
   *   Provider ID => human-readable label.
   */
  private function getEnabledProviders(): array {
    try {
      if (!\Drupal::hasService('ai.provider')) {
        return [];
      }
      /** @var \Drupal\ai\AiProviderPluginManager $manager */
      $manager   = \Drupal::service('ai.provider');
      $providers = $manager->getProvidersForOperationType('chat', TRUE);
      $result    = [];
      foreach ($providers as $id => $definition) {
        $result[(string) $id] = (string) ($definition['label'] ?? $id);
      }
      return $result;
    }
    catch (\Throwable) {
      return [];
    }
  }

}
