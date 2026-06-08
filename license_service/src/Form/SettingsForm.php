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
    // Optional Key module integration — NULL when the contrib module is not
    // installed. Author: Jeremiah Buttler
    protected readonly ?object $keyRepository = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static(
      $container->get('license_service.key_provider'),
      $container->get('license_service.license_client'),
      $container->get('license_service.license_manager'),
      $container->has('key.repository') ? $container->get('key.repository') : NULL,
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
      '#type'        => 'details',
      '#title'       => $this->t('Enable License Service'),
      '#open'        => TRUE,
      '#description' => $this->t('These settings control what your Drupal site does <strong>only when its License Verification Server (LVS) license becomes inactive or expired</strong> — for example, a lapsed subscription, a revoked API key, or the LVS being unreachable past the offline grace window. They are <strong>about your LVS license, not about your Drupal users</strong>. The day-to-day gating of who sees what content (role → license level → content type) is handled by <em>Role Levels</em> and <em>Content Rules</em>, which always apply while the license is active and are unaffected by anything in this section. Leave this section disabled if you do not want LVS-side problems — non-payment, key revocation, LVS downtime — to ever change what your site visitors see.'),
    ];

    $form['enforcement']['enforcement_enabled'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Enable site-wide enforcement'),
      '#default_value' => $config->get('enforcement_enabled') ?? FALSE,
      '#description'   => $this->t('<strong>Off (default):</strong> Your site never reacts site-wide to its own LVS license status. Even if your LVS subscription lapses, the public site keeps running exactly as before; only administrators see a quiet expiry warning in the back-end. Choose this if you do not want a problem with the LVS itself — lapsed subscription, revoked key, server outage — to ever affect what your site visitors see.<br><br><strong>On:</strong> Your site checks its LVS license on every page load. If the license is <strong>active</strong>, nothing changes for anyone. If the license is <strong>inactive or expired</strong>, the <em>Enforcement mode</em> below decides what happens to non-admin visitors. Administrators are never blocked, so you can always reach this page to renew or fix the license.'),
    ];

    $form['enforcement']['enforcement_mode'] = [
      '#type'          => 'radios',
      '#title'         => $this->t('Enforcement mode'),
      '#options'       => [
        'warn_only' => $this->t('Warn only — when your LVS license is inactive or expired, show a warning to administrators and logged-in users browsing admin pages, but do not block any visitor. The public site continues to serve content normally. Use this as a polite "renew your subscription" notice.'),
        'enforce'   => $this->t('Enforce — when your LVS license is inactive or expired, redirect non-admin visitors to the front page until the license is restored. Administrators, the login page, and the License Service admin pages remain reachable so you can always renew. Use this when you want the public site closed until your LVS license is valid again.'),
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
      '#markup' => $this->t('<p>Connect this site to the <a href=":lvs" target="_blank" rel="noopener noreferrer">License Verification Server</a> (LVS) using an API key from your LVS customer account. If this is your first time, follow these steps in order:</p><ol><li><strong>Create an LVS account</strong> (skip if you already have one). Open the <a href=":register" target="_blank" rel="noopener noreferrer">registration page</a>, register an email address and password, and confirm the account. If signups are closed on your vendor\'s instance, ask the vendor to invite you instead.</li><li><strong>Sign in to the LVS portal</strong> at <a href=":login" target="_blank" rel="noopener noreferrer">www.licenseverificationserver.com/account/login</a>.</li><li><strong>Open the API Keys page.</strong> In the portal sidebar click <em>API Keys</em> (direct link: <a href=":apikeys" target="_blank" rel="noopener noreferrer">account/api-keys</a>).</li><li><strong>Generate a new key.</strong> Under "Generate a new API key", give it a recognizable name — your site\'s domain works well so you can identify and revoke it later — then click <em>Generate API key</em>. The form is pre-set for the <code>drupal_license</code> product; leave it.</li><li><strong>Copy the key immediately.</strong> The full key is shown exactly once, with a built-in <em>Copy</em> button. Only a hash is stored on the server, so if you lose the key you must revoke it from the same page and generate a new one — it cannot be retrieved.</li><li><strong>Store the key in Drupal\'s Key module.</strong> Go to <a href=":keys_add">Configuration → System → Keys → Add key</a>, set <em>Key type</em> to <em>Authentication</em>, choose a <em>Key provider</em> (Configuration is fine; File if you prefer file-based storage), paste the API key as the value, give the key a label such as "License Verification Server", and save.</li><li><strong>Select that key below</strong> in the <em>API key</em> dropdown.</li><li><strong>Accept the terms and click Activate</strong> at the bottom of this page. The Status panel above should switch to <em>Active</em> and show your license details.</li></ol>', [
        ':lvs'      => 'https://www.licenseverificationserver.com/',
        ':register' => 'https://www.licenseverificationserver.com/account/register',
        ':login'    => 'https://www.licenseverificationserver.com/account/login',
        ':apikeys'  => 'https://www.licenseverificationserver.com/account/api-keys',
        ':keys_add' => Url::fromUserInput('/admin/config/system/keys/add')->toString(),
      ]),
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
    if ($this->keyRepository === NULL) {
      return [];
    }
    $keys = $this->keyRepository->getKeys();
    $opts = ['' => $this->t('- Select a key -')];
    foreach ($keys as $key) {
      $opts[$key->id()] = $key->label();
    }
    return $opts;
  }

}
