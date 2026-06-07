<?php

namespace Drupal\license_service\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\license_service\Key\LicenseKeyProvider;
use Drupal\license_service\LicenseClient;
use Drupal\license_service\LicenseManagerService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Site license settings: enforcement and API key (Key module storage).
 *
 * Security notes:
 * - The API key is never typed into or stored by this form. It is held in a
 *   Key module entity and selected here by id, so the raw key is never echoed
 *   back nor written to exported configuration.
 * - The license server URL is fixed in code (LicenseClient::DEFAULT_SERVER_URL)
 *   and is NOT configurable — there is no admin override to swap it, which
 *   removes the SSRF / server-swap vector entirely.
 * - All form submissions are CSRF-protected by Drupal's Form API automatically.
 *
 * Author: Jeremiah Buttler
 */
class SettingsForm extends ConfigFormBase {

  public function __construct(
    protected readonly LicenseKeyProvider $keyProvider,
    protected readonly LicenseClient $licenseClient,
    protected readonly LicenseManagerService $licenseManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static(
      $container->get('license_service.key_provider'),
      $container->get('license_service.license_client'),
      $container->get('license_service.license_manager'),
    );
    $instance->setConfigFactory($container->get('config.factory'));
    $instance->setStringTranslation($container->get('string_translation'));
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'license_service_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['license_service.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('license_service.settings');
    $status = $this->licenseManager->getStatus();

    // ---- Enable License Service (enforcement) — top of page, open ----------
    $form['enforcement'] = [
      '#type'  => 'details',
      '#title' => $this->t('Enable License Service'),
      '#open'  => TRUE,
    ];

    $form['enforcement']['enforcement_enabled'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Enable site-wide enforcement'),
      '#default_value' => $config->get('enforcement_enabled') ?? FALSE,
      '#description'   => $this->t('<strong>Off (default):</strong> the module never reacts site-wide to license status. Content stays open no matter what the license does.<br><strong>On:</strong> on every page load the module checks the license, and if the license is inactive or expired, it applies the mode you pick below.'),
    ];

    $form['enforcement']['enforcement_mode'] = [
      '#type'          => 'radios',
      '#title'         => $this->t('Enforcement mode'),
      '#options'       => [
        'warn_only' => $this->t('Warn only — show a warning message to administrators and users, but do not block access.'),
        'enforce'   => $this->t('Enforce — redirect non-admin users away from content when the license is inactive.'),
      ],
      '#default_value' => $config->get('enforcement_mode') ?? 'warn_only',
      '#states'        => [
        'visible' => [':input[name="enforcement_enabled"]' => ['checked' => TRUE]],
      ],
    ];

    // ---- Current license status --------------------------------------------
    $form['status'] = [
      '#type'  => 'details',
      '#title' => $this->t('Current license status'),
      '#open'  => TRUE,
    ];

    $stateLabel = $status['licensed']
      ? '<strong class="ok">' . $this->t('Licensed') . '</strong>'
      : '<strong class="error">' . $this->t('Unlicensed') . '</strong>';

    $statusRows = [
      [$this->t('Status'), ['#markup' => $stateLabel]],
      [$this->t('State'), $status['state'] ?? 'unlicensed'],
      [$this->t('Tier'), ucfirst($status['tier'] ?? 'free')],
      [$this->t('Expires'), $status['expires_at'] ?? $this->t('N/A')],
    ];
    if (!empty($status['warnings'])) {
      foreach ($status['warnings'] as $w) {
        $statusRows[] = [$this->t('Warning'), $w];
      }
    }

    $form['status']['status_table'] = [
      '#type'  => 'table',
      '#rows'  => $statusRows,
      '#cache' => ['tags' => ['license_service']],
    ];

    $form['status']['status_link'] = [
      '#type'  => 'link',
      '#title' => $this->t('View full status dashboard'),
      '#url'   => Url::fromRoute('license_service.status'),
    ];

    // ---- API key — bottom of page, closed ----------------------------------
    $form['key_section'] = [
      '#type'  => 'details',
      '#title' => $this->t('API key'),
      '#open'  => FALSE,
    ];

    // How to obtain and store the API key.
    $form['key_section']['intro'] = [
      '#markup' => '<p>' . $this->t('This site connects to the License Verification Server with a <strong>tenant API key</strong>. To obtain it: sign in to your tenant portal on the <a href=":lvs" target="_blank" rel="noopener noreferrer">License Verification Server</a> and copy the API key shown for your site. Then store it in the <a href=":keys">Key module</a> (choose a key type such as <em>Authentication</em>) and select that key below.', [
        ':lvs'  => 'https://www.licenseverificationserver.com/',
        ':keys' => Url::fromUserInput('/admin/config/system/keys')->toString(),
      ]) . '</p>',
    ];

    if (!$this->keyProvider->hasKeyModuleSupport()) {
      $form['key_section']['no_key_module'] = [
        '#markup' => '<p><strong>' . $this->t('The Key module is required but is not enabled.') . '</strong> '
          . $this->t('Install it with <code>composer require drupal/key</code> and enable it on the <a href=":modules">Extend</a> page, then return here to select your key.', [
            ':modules' => Url::fromRoute('system.modules_list')->toString(),
          ]) . '</p>',
      ];
    }
    else {
      $form['key_section']['key_id'] = [
        '#type'          => 'select',
        '#title'         => $this->t('API key'),
        '#options'       => $this->getKeyModuleOptions(),
        '#default_value' => $config->get('key_id') ?? '',
        '#description'   => $this->t('Select the <a href=":keys">Key module</a> key that holds your tenant API key. <a href=":add">Add a key</a> if you have not created one yet.', [
          ':keys' => Url::fromUserInput('/admin/config/system/keys')->toString(),
          ':add'  => Url::fromUserInput('/admin/config/system/keys/add')->toString(),
        ]),
      ];

      if ($this->keyProvider->getKey() !== '') {
        $form['key_section']['current_key'] = [
          '#markup' => '<p>' . $this->t('Current key: <code>@masked</code>', [
            '@masked' => $this->keyProvider->getMaskedKey(),
          ]) . '</p>',
        ];
      }
    }

    // ---- Terms acceptance --------------------------------------------------
    $form['key_section']['terms_accept'] = [
      '#type'  => 'checkbox',
      '#title' => $this->t('I accept the <a href="https://www.licenseverificationserver.com/terms" target="_blank" rel="noopener noreferrer">terms and conditions</a> of usage'),
    ];

    // ---- Test connection / Activate / Deactivate buttons -------------------
    $form['key_section']['actions_key'] = [
      '#type' => 'container',
    ];
    // Test connection only checks reachability, so it skips form validation.
    $form['key_section']['actions_key']['test'] = [
      '#type'                    => 'submit',
      '#value'                   => $this->t('Test connection'),
      '#submit'                  => ['::submitTestConnection'],
      '#limit_validation_errors' => [],
    ];
    $form['key_section']['actions_key']['activate'] = [
      '#type'        => 'submit',
      '#value'       => $this->t('Activate'),
      '#submit'      => ['::submitActivate'],
      '#validate'    => ['::validateActivate'],
      '#button_type' => 'primary',
      '#access'      => $this->keyProvider->hasKeyModuleSupport(),
    ];
    $form['key_section']['actions_key']['deactivate'] = [
      '#type'        => 'submit',
      '#value'       => $this->t('Deactivate'),
      '#submit'      => ['::submitDeactivate'],
      '#button_type' => 'danger',
      '#access'      => $this->keyProvider->getKey() !== '',
    ];

    $form = parent::buildForm($form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('license_service.settings');

    $config
      // Drop legacy keys that are no longer configurable.
      ->clear('server_url')
      ->clear('expiry_warning_days')
      // The key is always stored in the Key module now.
      ->set('key_provider', 'key_module')
      ->set('key_id', $form_state->getValue('key_id', ''))
      ->set('enforcement_enabled', (bool) $form_state->getValue('enforcement_enabled', FALSE))
      ->set('enforcement_mode', $form_state->getValue('enforcement_mode', 'warn_only'));

    $config->save();
    $this->licenseManager->invalidateCache();

    parent::submitForm($form, $form_state);
  }

  /**
   * Validate callback for the Activate button.
   */
  public function validateActivate(array &$form, FormStateInterface $form_state): void {
    if (!$this->keyProvider->hasKeyModuleSupport()) {
      $form_state->setErrorByName('key_id', $this->t('The Key module must be installed and enabled before you can activate.'));
      return;
    }

    if ((string) $form_state->getValue('key_id', '') === '') {
      $form_state->setErrorByName('key_id', $this->t('Select the Key that holds your API key.'));
    }

    if (!$form_state->getValue('terms_accept')) {
      $form_state->setErrorByName('terms_accept', $this->t('You must accept the terms and conditions before activating.'));
    }
  }

  /**
   * Submit handler: save settings then activate against the server.
   */
  public function submitActivate(array &$form, FormStateInterface $form_state): void {
    // Persist settings first so the client reads the selected key.
    $this->submitForm($form, $form_state);

    $result = $this->licenseClient->activate();
    if ($result['ok']) {
      $this->messenger()->addStatus($this->t('License activated successfully. Tier: @tier.', [
        '@tier' => ucfirst($result['license']['tier'] ?? 'standard'),
      ]));
    }
    else {
      $this->messenger()->addError($this->t('Activation failed: @error', ['@error' => $result['error'] ?? '']));
    }

    $this->licenseManager->invalidateCache();
  }

  /**
   * Submit handler: probe the License Verification Server for reachability.
   */
  public function submitTestConnection(array &$form, FormStateInterface $form_state): void {
    $result = $this->licenseClient->testConnection();
    if ($result['ok']) {
      $this->messenger()->addStatus($this->t('Connection OK — reached the License Verification Server at @url.', [
        '@url' => $this->licenseClient->getServerUrl(),
      ]));
    }
    else {
      $this->messenger()->addError($this->t('Connection failed: @error', [
        '@error' => $result['error'] ?? $this->t('unknown error'),
      ]));
    }
    $form_state->setRebuild();
  }

  /**
   * Submit handler: deactivate and clear the cached token.
   */
  public function submitDeactivate(array &$form, FormStateInterface $form_state): void {
    $ok = $this->licenseClient->deactivate();
    if ($ok) {
      $this->messenger()->addStatus($this->t('License deactivated. This site\'s seat has been released.'));
    }
    else {
      $this->messenger()->addWarning($this->t('Deactivation request could not reach the server; local cache has been cleared.'));
    }
    $this->licenseManager->invalidateCache();
  }

  // --------------------------------------------------------------------------
  // Private helpers
  // --------------------------------------------------------------------------

  /**
   * Returns options for the Key module key selector.
   */
  protected function getKeyModuleOptions(): array {
    if (!\Drupal::hasService('key.repository')) {
      return [];
    }
    $keys = \Drupal::service('key.repository')->getKeys();
    $opts = ['' => $this->t('- Select a key -')];
    foreach ($keys as $key) {
      $opts[$key->id()] = $key->label();
    }
    return $opts;
  }

}
