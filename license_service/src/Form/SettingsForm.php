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
 * Site license settings: server URL, key provider, offline grace, enforcement.
 *
 * Security notes:
 * - The license key field uses #type => 'password' so it is never echoed back.
 * - Server URL is validated to HTTPS before save (SSRF prevention).
 * - Key module integration: when key_provider = 'key_module', a key_id selector
 *   is shown; the raw key is never stored in exported config.
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
    $config   = $this->config('license_service.settings');
    $provider = (string) ($config->get('key_provider') ?? 'config');
    $status   = $this->licenseManager->getStatus();

    // ---- API Key -----------------------------------------------------------
    $form['key_section'] = [
      '#type'  => 'details',
      '#title' => $this->t('API key'),
      '#open'  => TRUE,
    ];

    $form['key_section']['key_provider'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Key storage method'),
      '#options'       => $this->buildKeyProviderOptions(),
      '#default_value' => $provider,
      '#description'   => $this->t('For production, use the <a href=":key_url">Key module</a> or <code>settings.php</code> to avoid storing the key in exported configuration.', [
        ':key_url' => Url::fromUserInput('/admin/config/system/keys')->toString(),
      ]),
    ];

    if ($provider === 'key_module' && $this->keyProvider->hasKeyModuleSupport()) {
      $form['key_section']['key_id'] = [
        '#type'          => 'select',
        '#title'         => $this->t('Key'),
        '#options'       => $this->getKeyModuleOptions(),
        '#default_value' => $config->get('key_id') ?? '',
        '#description'   => $this->t('Select the Key module key that stores your API key.'),
        '#states'        => [
          'visible' => [':input[name="key_provider"]' => ['value' => 'key_module']],
        ],
      ];
    }

    if ($provider !== 'settings_php') {
      // Show password field only when not using settings.php (which is set externally).
      $form['key_section']['license_key'] = [
        '#type'        => 'password',
        '#title'       => $this->t('API key'),
        '#description' => $this->t('Enter your API key from the <a href="https://www.licenseverificationserver.com/account/api-keys" target="_blank" rel="noopener noreferrer">API Keys page of your account</a> on the License Verification Server (or a license key issued for this product). Leave blank to keep the current key.'),
        '#maxlength'   => 255,
        '#attributes'  => ['autocomplete' => 'off'],
        '#states'      => [
          'visible' => [':input[name="key_provider"]' => ['!value' => 'settings_php']],
        ],
      ];

      if ($this->keyProvider->getKey() !== '') {
        $form['key_section']['current_key'] = [
          '#markup' => '<p>' . $this->t('Current key: <code>@masked</code>', [
            '@masked' => $this->keyProvider->getMaskedKey(),
          ]) . '</p>',
        ];
      }
    }
    else {
      $form['key_section']['settings_php_note'] = [
        '#markup' => '<p>' . $this->t('The API key is set in <code>settings.php</code> via <code>$settings[\'license_service_key\']</code>.') . '</p>',
      ];
    }

    // ---- Terms acceptance --------------------------------------------------
    $form['key_section']['terms_accept'] = [
      '#type'  => 'checkbox',
      '#title' => $this->t('I accept the <a href="https://www.licenseverificationserver.com/terms" target="_blank" rel="noopener noreferrer">terms and conditions</a> of usage'),
    ];

    // ---- Activate / Deactivate buttons ------------------------------------
    $form['key_section']['actions_key'] = [
      '#type' => 'container',
    ];
    $form['key_section']['actions_key']['activate'] = [
      '#type'                  => 'submit',
      '#value'                 => $this->t('Activate'),
      '#submit'                => ['::submitActivate'],
      '#validate'              => ['::validateActivate'],
      '#button_type'           => 'primary',
    ];
    $form['key_section']['actions_key']['deactivate'] = [
      '#type'        => 'submit',
      '#value'       => $this->t('Deactivate'),
      '#submit'      => ['::submitDeactivate'],
      '#button_type' => 'danger',
      '#access'      => $this->keyProvider->getKey() !== '',
    ];

    // ---- Status panel -------------------------------------------------------
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

    // ---- Server settings ---------------------------------------------------
    $form['server'] = [
      '#type'  => 'details',
      '#title' => $this->t('Server settings'),
      '#open'  => FALSE,
    ];

    $form['server']['server_url'] = [
      '#type'          => 'url',
      '#title'         => $this->t('License server URL'),
      '#default_value' => $config->get('server_url') ?? LicenseClient::DEFAULT_SERVER_URL,
      '#description'   => $this->t('Must be HTTPS. Leave as-is to use the default production server.'),
      '#maxlength'     => 255,
    ];

    $form['server']['expiry_warning_days'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Expiry warning (days before)'),
      '#default_value' => $config->get('expiry_warning_days') ?? 7,
      '#min'           => 1,
      '#max'           => 90,
      '#description'   => $this->t('Show a warning this many days before the license expires.'),
    ];

    // ---- Enforcement settings ----------------------------------------------
    $form['enforcement'] = [
      '#type'  => 'details',
      '#title' => $this->t('Enforcement'),
      '#open'  => FALSE,
    ];

    $form['enforcement']['enforcement_enabled'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Enable site-wide enforcement'),
      '#default_value' => $config->get('enforcement_enabled') ?? FALSE,
      '#description'   => $this->t('When enabled, the site enforces the selected mode below when the license is inactive or expired. Admin pages are never blocked.'),
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

    $form = parent::buildForm($form, $form_state);

    // The parent adds a generic Save button; move it after our action buttons.
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $serverUrl = trim($form_state->getValue('server_url', ''));
    if ($serverUrl !== '' && !str_starts_with($serverUrl, 'https://')) {
      $form_state->setErrorByName('server_url', $this->t('The license server URL must start with https://.'));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('license_service.settings');
    $provider = $form_state->getValue('key_provider', 'config');

    $config
      ->set('key_provider', $provider)
      ->set('server_url', trim($form_state->getValue('server_url', LicenseClient::DEFAULT_SERVER_URL)))
      ->set('expiry_warning_days', (int) $form_state->getValue('expiry_warning_days', 7))
      ->set('enforcement_enabled', (bool) $form_state->getValue('enforcement_enabled', FALSE))
      ->set('enforcement_mode', $form_state->getValue('enforcement_mode', 'warn_only'));

    if ($provider === 'key_module') {
      $config->set('key_id', $form_state->getValue('key_id', ''));
    }

    // Store a newly entered key (only if it's not empty and provider = config/state).
    $rawKey = trim($form_state->getValue('license_key', ''));
    if ($rawKey !== '' && $provider !== 'settings_php' && $provider !== 'key_module') {
      $this->keyProvider->setKey($rawKey);
    }

    $config->save();
    $this->licenseManager->invalidateCache();

    parent::submitForm($form, $form_state);
  }

  /**
   * Validate callback for the Activate button.
   */
  public function validateActivate(array &$form, FormStateInterface $form_state): void {
    $rawKey = trim($form_state->getValue('license_key', ''));
    $provider = $form_state->getValue('key_provider', 'config');

    if ($rawKey === '' && $provider !== 'settings_php' && $provider !== 'key_module') {
      if ($this->keyProvider->getKey() === '') {
        $form_state->setErrorByName('license_key', $this->t('Enter an API key to activate.'));
      }
    }

    if (!$form_state->getValue('terms_accept')) {
      $form_state->setErrorByName('terms_accept', $this->t('You must accept the terms and conditions before activating.'));
    }
  }

  /**
   * Submit handler: save key then activate against the server.
   */
  public function submitActivate(array &$form, FormStateInterface $form_state): void {
    // Persist settings first so the client uses the correct server URL.
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
   * Builds the key_provider select options based on what is available.
   */
  protected function buildKeyProviderOptions(): array {
    $opts = [
      'config'      => $this->t('Module state (not exported to config)'),
      'settings_php' => $this->t('settings.php ($settings[\'license_service_key\'])'),
    ];
    if ($this->keyProvider->hasKeyModuleSupport()) {
      $opts['key_module'] = $this->t('Key module');
    }
    return $opts;
  }

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
