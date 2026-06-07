<?php

namespace Drupal\license_service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\license_service\Crypto\Ed25519Verifier;
use Drupal\license_service\Key\LicenseKeyProvider;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * HTTP client for the License Verification Server.
 *
 * Handles activation, verification, heartbeat, and caching of the signed
 * license token. Implements 24h offline grace and expiry warnings that mirror
 * the Python license_client.py contract from the licensed-program-client skill.
 *
 * Security:
 * - All HTTP calls enforce HTTPS, TLS cert verification, connect/read timeouts,
 *   and response-size caps.
 * - The license key is never written to logs or returned in public responses.
 * - Signatures are verified before any token claim is trusted.
 * - Server URL is validated against private IP ranges (SSRF prevention).
 *
 * Author: Jeremiah Buttler
 */
class LicenseClient {

  /**
   * Production License Verification Server URL (default; overridable in config).
   *
   * Must use the www host: the production server terminates TLS only for
   * www.licenseverificationserver.com. The bare apex fails the TLS handshake
   * with WRONG_VERSION_NUMBER, so every LVS URL the client emits uses www.
   */
  const DEFAULT_SERVER_URL = 'https://www.licenseverificationserver.com';

  /**
   * The Drupal module's product_id registered on the server.
   */
  const PRODUCT_ID = 'drupal_license';

  /**
   * Maximum allowed response body size in bytes (1 MB).
   */
  const MAX_RESPONSE_BYTES = 1_048_576;

  /**
   * HTTP connect timeout in seconds.
   */
  const CONNECT_TIMEOUT = 10;

  /**
   * HTTP read timeout in seconds.
   */
  const READ_TIMEOUT = 15;

  // Drupal state keys.
  const STATE_MACHINE_ID    = 'license_service.machine_id';
  const STATE_CACHED_TOKEN  = 'license_service.cached_token';
  const STATE_SUMMARY       = 'license_service.license_summary';
  const STATE_OFFLINE_SINCE = 'license_service.offline_since';
  const STATE_PUBLIC_KEY    = 'license_service.public_key';

  /**
   * The logger channel for this module.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The Ed25519 token signature verifier.
   *
   * @var \Drupal\license_service\Crypto\Ed25519Verifier
   */
  protected Ed25519Verifier $verifier;

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly StateInterface $state,
    protected readonly ClientInterface $httpClient,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
    protected readonly LicenseKeyProvider $keyProvider,
  ) {
    $this->logger   = $this->loggerFactory->get('license_service');
    $this->verifier = new Ed25519Verifier();
  }

  // --------------------------------------------------------------------------
  // Public API
  // --------------------------------------------------------------------------

  /**
   * Activates the site license on the License Verification Server.
   *
   * POSTs to /api/v1/activate. On success, caches the signed token and
   * license summary. Returns a status array; never throws on network failure.
   *
   * @return array
   *   A status array with keys: ok (bool), token (string, optional),
   *   license (array, optional), error (string, optional).
   */
  public function activate(): array {
    $key = $this->keyProvider->getKey();
    if ($key === '') {
      return ['ok' => FALSE, 'error' => 'No API key configured. Enter a key in the License Service settings.'];
    }

    try {
      $data = $this->post('/api/v1/activate', [
        // NOTE: do NOT uppercase. License keys use an uppercase-only alphabet,
        // but account API keys are mixed-case (base64url token_urlsafe); upper-
        // casing them changes their hash and the server returns "unknown license
        // key". Send the key exactly as entered (trimmed only). — Jeremiah Buttler
        'license_key'  => trim($key),
        'product_id'   => self::PRODUCT_ID,
        'machine_id'   => $this->getMachineId(),
        'machine_name' => \Drupal::request()->getHost(),
        'site_url'     => \Drupal::request()->getSchemeAndHttpHost(),
      ]);
    }
    catch (BadResponseException $e) {
      $msg = $this->extractErrorMessage($e);
      $this->logger->warning('License activation rejected: @msg', ['@msg' => $msg]);
      return ['ok' => FALSE, 'error' => $msg];
    }
    catch (\Exception $e) {
      $this->logger->warning('License activation failed (network): @msg', ['@msg' => $e->getMessage()]);
      return ['ok' => FALSE, 'error' => 'Cannot reach the licensing server: ' . $e->getMessage()];
    }

    if (empty($data['token'])) {
      return ['ok' => FALSE, 'error' => 'Server response did not include a license token.'];
    }

    $this->cacheToken($data['token'], $data['license'] ?? []);
    if (!empty($data['public_key'])) {
      $this->state->set(self::STATE_PUBLIC_KEY, $data['public_key']);
    }

    return ['ok' => TRUE, 'token' => $data['token'], 'license' => $data['license'] ?? []];
  }

  /**
   * Re-verifies the license online (POST /api/v1/verify).
   *
   * Called by cronRecheck() when the cached token is past refresh_by.
   *
   * @return array
   *   A status array with keys: ok (bool), token (string, optional),
   *   license (array, optional), error (string, optional).
   */
  public function verify(): array {
    $token = $this->state->get(self::STATE_CACHED_TOKEN, '');
    if ($token === '') {
      return ['ok' => FALSE, 'error' => 'No cached token to verify.'];
    }

    try {
      $data = $this->post('/api/v1/verify', ['token' => $token]);
    }
    catch (BadResponseException $e) {
      // 4xx = server actively rejected it (revoked/expired/not activated).
      $msg = $this->extractErrorMessage($e);
      $this->logger->warning('License verification rejected by server: @msg', ['@msg' => $msg]);
      return ['ok' => FALSE, 'error' => $msg, 'rejected' => TRUE];
    }
    catch (\Exception $e) {
      // Network failure — caller decides grace window.
      return ['ok' => FALSE, 'error' => $e->getMessage(), 'rejected' => FALSE];
    }

    if (empty($data['token'])) {
      return ['ok' => FALSE, 'error' => 'Server response did not include a license token.'];
    }

    $this->cacheToken($data['token'], $data['license'] ?? []);
    $this->state->delete(self::STATE_OFFLINE_SINCE);

    return ['ok' => TRUE, 'token' => $data['token'], 'license' => $data['license'] ?? []];
  }

  /**
   * Authorizes an individual user against the LVS per-user license ledger.
   *
   * Per-user licensing — Author: Jeremiah Buttler.
   *
   * @param string $external_user_id
   *   Stable opaque user identifier (e.g. Drupal uid cast to string).
   * @param string $kind
   *   Grant kind: 'user' for premium-role users, 'admin' for admin users.
   * @param string|null $display
   *   Optional label (email/username) shown in the LVS dashboard.
   *
   * @return array
   *   Response array with 'status' key: 'granted', 'rejected', or 'error'.
   *   On 'granted': also carries 'grant_token', 'expires_at', 'used', 'limit'.
   *   On 'rejected': also carries 'reason', 'used', 'limit', 'kind'.
   *   On 'error': also carries 'error' (human-readable message).
   */
  public function authorizeUser(string $external_user_id, string $kind = 'user', ?string $display = NULL): array {
    $key = $this->keyProvider->getKey();
    if ($key === '') {
      return ['status' => 'error', 'error' => 'No license key configured.'];
    }

    try {
      $data = $this->post('/api/v1/license/users/authorize', [
        // Send the key exactly as entered (trimmed); never uppercase — see
        // activate() note. Mixed-case API keys break under strtoupper.
        'license_key'      => trim($key),
        'external_user_id' => $external_user_id,
        'kind'             => $kind,
        'display'          => $display,
      ]);
    }
    catch (BadResponseException $e) {
      $msg = $this->extractErrorMessage($e);
      $this->logger->warning('LVS user authorize rejected for uid @uid: @msg', [
        '@uid' => $external_user_id,
        '@msg' => $msg,
      ]);
      return ['status' => 'error', 'error' => $msg];
    }
    catch (\Exception $e) {
      // Network failure — caller applies offline grace.
      return ['status' => 'error', 'error' => $e->getMessage()];
    }

    return $data;
  }

  /**
   * Releases a user's license grant back to the pool.
   *
   * Called on user deletion or when a premium role is stripped.
   * Author: Jeremiah Buttler.
   *
   * @param string $external_user_id
   *   The same opaque identifier passed to authorizeUser().
   *
   * @return array
   *   Response array with 'status' key: 'revoked', 'not_found', or 'error'.
   */
  public function revokeUser(string $external_user_id): array {
    $key = $this->keyProvider->getKey();
    if ($key === '') {
      return ['status' => 'error', 'error' => 'No license key configured.'];
    }

    try {
      $data = $this->post('/api/v1/license/users/revoke', [
        // Send the key exactly as entered (trimmed); never uppercase — see
        // activate() note. Mixed-case API keys break under strtoupper.
        'license_key'      => trim($key),
        'external_user_id' => $external_user_id,
      ]);
    }
    catch (BadResponseException $e) {
      $msg = $this->extractErrorMessage($e);
      $this->logger->warning('LVS user revoke rejected for uid @uid: @msg', [
        '@uid' => $external_user_id,
        '@msg' => $msg,
      ]);
      return ['status' => 'error', 'error' => $msg];
    }
    catch (\Exception $e) {
      // Best-effort; caller continues regardless.
      return ['status' => 'error', 'error' => $e->getMessage()];
    }

    return $data;
  }

  /**
   * Sends a heartbeat (POST /api/v1/heartbeat) to confirm the site is active.
   */
  public function heartbeat(): void {
    $token = $this->state->get(self::STATE_CACHED_TOKEN, '');
    if ($token === '') {
      return;
    }
    try {
      $pubKey = $this->getPublicKey();
      if ($pubKey === '') {
        return;
      }
      $payload = $this->verifier->verify($token, $pubKey);
      $this->post('/api/v1/heartbeat', [
        'license_key' => $payload['license_key'] ?? '',
        'machine_id'  => $this->getMachineId(),
      ]);
    }
    catch (\Exception) {
      // Heartbeat is best-effort; network errors are silent.
    }
  }

  /**
   * Deactivates the site seat on the server (POST /api/v1/deactivate).
   *
   * @return bool
   *   TRUE on success.
   */
  public function deactivate(): bool {
    $token = $this->state->get(self::STATE_CACHED_TOKEN, '');
    if ($token !== '') {
      try {
        $pubKey = $this->getPublicKey();
        if ($pubKey !== '') {
          $payload = $this->verifier->verify($token, $pubKey);
          $this->post('/api/v1/deactivate', [
            'license_key' => $payload['license_key'] ?? '',
            'machine_id'  => $this->getMachineId(),
          ]);
        }
      }
      catch (\Exception) {
        // Best-effort; clear state regardless.
      }
    }

    $this->state->delete(self::STATE_CACHED_TOKEN);
    $this->state->delete(self::STATE_SUMMARY);
    $this->state->delete(self::STATE_OFFLINE_SINCE);
    return TRUE;
  }

  /**
   * Cron hook that re-checks the license online and sends a heartbeat.
   *
   * Re-checks online when the token is past refresh_by, and sends a heartbeat.
   * Handles offline grace. Author: Jeremiah Buttler.
   */
  public function cronRecheck(): void {
    $token = $this->state->get(self::STATE_CACHED_TOKEN, '');
    if ($token === '') {
      return;
    }

    $pubKey = $this->getPublicKey();
    if ($pubKey === '') {
      return;
    }

    try {
      $payload = $this->verifier->verify($token, $pubKey);
    }
    catch (\Exception) {
      return;
    }

    $refreshBy = $this->parseIso($payload['refresh_by'] ?? NULL);
    $now       = new \DateTime('now', new \DateTimeZone('UTC'));

    if ($refreshBy !== NULL && $now <= $refreshBy) {
      // Token still fresh — only heartbeat.
      $this->heartbeat();
      return;
    }

    // Past refresh_by: attempt online re-verification.
    $result = $this->verify();
    if (!$result['ok']) {
      $this->logger->notice('License cron re-check failed: @msg', ['@msg' => $result['error'] ?? '']);
    }
  }

  /**
   * Returns the cached license status evaluated against the offline grace window.
   *
   * Verifies the cached token offline first; only goes online when past refresh_by.
   *
   * @return array{
   *   licensed: bool,
   *   tier: string,
   *   features: array,
   *   expires_at: string|null,
   *   trial: bool,
   *   warnings: string[],
   *   refresh_failed: bool,
   *   expiring_soon: bool,
   *   days_until_expiry: int|null,
   *   offline: bool,
   *   state: string,
   *   }
   */
  public function getStatus(): array {
    $token = $this->state->get(self::STATE_CACHED_TOKEN, '');
    if ($token === '') {
      return $this->unlicensedStatus('No API key activated on this site. Enter a key in License Service settings.');
    }

    $pubKey = $this->getPublicKey();
    if ($pubKey === '') {
      // No public key yet — try to fetch it now.
      $pubKey = $this->fetchAndCachePublicKey();
      if ($pubKey === '') {
        return $this->unlicensedStatus('Cannot verify license: public key not available. Connect to the internet and re-activate.');
      }
    }

    try {
      $payload = $this->verifier->verify($token, $pubKey);
    }
    catch (\Exception) {
      return $this->unlicensedStatus('Stored license token is invalid or tampered. Re-activate to restore service.');
    }

    if (($payload['product_id'] ?? '') !== self::PRODUCT_ID) {
      return $this->unlicensedStatus('License token is for a different product.');
    }

    // Machine binding: reject tokens for a different site.
    $machineId = $this->getMachineId();
    if (!empty($payload['machine_id']) && $payload['machine_id'] !== $machineId) {
      return $this->unlicensedStatus('License token is bound to a different site. Re-activate on this site.');
    }

    // Hard stop: a genuinely expired token is never valid.
    $now       = new \DateTime('now', new \DateTimeZone('UTC'));
    $expiresAt = $this->parseIso($payload['expires_at'] ?? NULL);
    if ($expiresAt !== NULL && $now > $expiresAt) {
      return $this->buildStatus($payload, licensed: FALSE, state: 'expired', offline: TRUE);
    }

    // Check if an online re-check is due.
    $refreshBy  = $this->parseIso($payload['refresh_by'] ?? NULL);
    $needOnline = ($refreshBy === NULL || $now > $refreshBy);

    if (!$needOnline) {
      return $this->buildStatus($payload, licensed: TRUE, offline: TRUE);
    }

    // Try online re-verification.
    $result = $this->verify();

    if ($result['ok'] && !empty($result['token'])) {
      try {
        $fresh = $this->verifier->verify($result['token'], $pubKey);
        return $this->buildStatus($fresh, licensed: TRUE, offline: FALSE);
      }
      catch (\Exception) {
        // Server returned a bad token; treat as network failure.
      }
    }

    // Server actively rejected (revoked/expired/not activated) — not a grace situation.
    if (!empty($result['rejected'])) {
      return $this->buildStatus($payload, licensed: FALSE, state: 'revoked', offline: FALSE,
        warning: $result['error'] ?? 'License was rejected by the server.');
    }

    // Network failure: apply offline grace window.
    $config     = $this->configFactory->get('license_service.settings');
    $graceHours = max(1, (int) ($config->get('offline_grace_hours') ?? 24));

    $offlineSince = $this->state->get(self::STATE_OFFLINE_SINCE, '');
    if ($offlineSince === '') {
      $this->state->set(self::STATE_OFFLINE_SINCE, $now->format(\DateTime::ATOM));
      $offlineSince = $now->format(\DateTime::ATOM);
    }

    $offlineSinceDate = new \DateTime($offlineSince);
    $graceUntil       = (clone $offlineSinceDate)->modify("+{$graceHours} hours");

    if ($now <= $graceUntil) {
      $st = $this->buildStatus($payload, licensed: TRUE, offline: TRUE, state: 'grace');
      $st['refresh_failed'] = TRUE;
      $when = $graceUntil->format('Y-m-d H:i') . ' UTC';
      $st['warnings'][] = "Couldn't reach the licensing server to refresh. "
        . "The site license keeps working offline until {$when} — "
        . "reconnect before then to stay activated.";
      return $st;
    }

    // Grace elapsed.
    return $this->unlicensedStatus(
      'Offline grace period has elapsed. Connect to the internet to re-verify the site license.',
      offline: TRUE,
      refreshFailed: TRUE,
    );
  }

  /**
   * Returns a stable machine ID for this Drupal site/environment.
   *
   * Generated once (UUID + site-URL hash) and stored in Drupal state.
   * This is the "device fingerprint" the server uses to track the activation.
   */
  public function getMachineId(): string {
    $id = $this->state->get(self::STATE_MACHINE_ID, '');
    if ($id === '') {
      $id = $this->generateMachineId();
      $this->state->set(self::STATE_MACHINE_ID, $id);
    }
    return $id;
  }

  /**
   * Returns the server URL from config, validated to be HTTPS and non-private.
   *
   * Falls back to the default production URL if the config value fails
   * validation, preventing SSRF via non-HTTPS or internal URLs.
   */
  public function getServerUrl(): string {
    $url = (string) ($this->configFactory->get('license_service.settings')->get('server_url') ?? '');
    if ($url === '' || !str_starts_with($url, 'https://')) {
      return self::DEFAULT_SERVER_URL;
    }
    $host = (string) parse_url($url, PHP_URL_HOST);
    if ($host === '' || $this->isPrivateHost($host)) {
      return self::DEFAULT_SERVER_URL;
    }
    return $this->normalizeServerUrl(rtrim($url, '/'));
  }

  /**
   * Rewrites the canonical LVS apex host to its www subdomain.
   *
   * The production License Verification Server terminates TLS only for the
   * www host; the bare apex (https://licenseverificationserver.com) fails the
   * handshake with WRONG_VERSION_NUMBER. Normalizing here means a stored or
   * hand-typed bare-domain value still connects, while dev/staging hosts are
   * left untouched.
   *
   * @param string $url
   *   An already-validated HTTPS server URL with no trailing slash.
   *
   * @return string
   *   The URL with the bare canonical apex rewritten to www, else unchanged.
   */
  protected function normalizeServerUrl(string $url): string {
    return (string) preg_replace(
      '#^https://licenseverificationserver\.com(?=/|$|:)#i',
      'https://www.licenseverificationserver.com',
      $url
    );
  }

  // --------------------------------------------------------------------------
  // Internal helpers
  // --------------------------------------------------------------------------

  /**
   * POSTs JSON to the server and returns the decoded response.
   *
   * Enforces HTTPS, cert verification, timeouts, and response size cap.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function post(string $path, array $body): array {
    $url = $this->getServerUrl() . $path;
    $resolve = $this->assertPublicHost($url);
    $response = $this->httpClient->post($url, [
      'json'            => $body,
      'connect_timeout' => self::CONNECT_TIMEOUT,
      'timeout'         => self::READ_TIMEOUT,
      'verify'          => TRUE,
      'headers'         => ['Accept' => 'application/json'],
    ] + $resolve);

    return $this->decodeResponse($response);
  }

  /**
   * GETs a path on the server and returns the decoded response.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function get(string $path): array {
    $url = $this->getServerUrl() . $path;
    $resolve = $this->assertPublicHost($url);
    $response = $this->httpClient->get($url, [
      'connect_timeout' => self::CONNECT_TIMEOUT,
      'timeout'         => self::READ_TIMEOUT,
      'verify'          => TRUE,
      'headers'         => ['Accept' => 'application/json'],
    ] + $resolve);

    return $this->decodeResponse($response);
  }

  /**
   * Decodes a server response, enforcing the response-size cap as it reads.
   *
   * Reads the body in bounded chunks instead of buffering the whole stream
   * first, so a malicious or runaway server cannot exhaust memory before the
   * size check runs. A declared Content-Length over the cap is rejected up
   * front; otherwise the streamed total is checked against the cap.
   *
   * @throws \RuntimeException
   *   If the body exceeds the size cap or is not valid JSON.
   */
  protected function decodeResponse(ResponseInterface $response): array {
    // Reject early when the server declares an oversized body.
    $declared = $response->getHeaderLine('Content-Length');
    if ($declared !== '' && ctype_digit($declared)
      && (int) $declared > self::MAX_RESPONSE_BYTES) {
      throw new \RuntimeException('Server response exceeded maximum allowed size.');
    }

    $stream = $response->getBody();
    $raw    = '';
    while (!$stream->eof()) {
      $chunk = $stream->read(8192);
      if ($chunk === '') {
        break;
      }
      $raw .= $chunk;
      if (strlen($raw) > self::MAX_RESPONSE_BYTES) {
        throw new \RuntimeException('Server response exceeded maximum allowed size.');
      }
    }

    $data = json_decode($raw, TRUE);
    if (!is_array($data)) {
      throw new \RuntimeException('Server returned an invalid JSON response.');
    }

    return $data;
  }

  /**
   * Extracts a human-readable error message from a Guzzle BadResponseException.
   */
  protected function extractErrorMessage(BadResponseException $e): string {
    try {
      $body = (string) $e->getResponse()->getBody();
      $data = json_decode($body, TRUE);
      if (is_array($data)) {
        return (string) ($data['detail'] ?? $data['error'] ?? $e->getMessage());
      }
    }
    catch (\Exception) {
    }
    return $e->getMessage();
  }

  /**
   * Saves the token and summary to Drupal state.
   */
  protected function cacheToken(string $token, array $summary): void {
    $this->state->set(self::STATE_CACHED_TOKEN, $token);
    $this->state->set(self::STATE_SUMMARY, $summary);
  }

  /**
   * Returns the cached public key from state, or empty string if not cached.
   */
  protected function getPublicKey(): string {
    return (string) $this->state->get(self::STATE_PUBLIC_KEY, '');
  }

  /**
   * Fetches the Ed25519 public key from GET /api/v1/pubkey and caches it.
   *
   * Returns the base64-encoded key, or '' on failure.
   */
  protected function fetchAndCachePublicKey(): string {
    try {
      $data = $this->get('/api/v1/pubkey');
      $key  = (string) ($data['public_key'] ?? '');
      if ($key !== '') {
        $this->state->set(self::STATE_PUBLIC_KEY, $key);
      }
      return $key;
    }
    catch (\Exception) {
      return '';
    }
  }

  /**
   * Builds a licensed/unlicensed status array from a token payload.
   *
   * @param array $payload
   *   Decoded token payload.
   * @param bool $licensed
   *   Whether the site is considered licensed.
   * @param string $state
   *   State string (active/expired/revoked/grace).
   * @param bool $offline
   *   Whether we validated offline.
   * @param string $warning
   *   Optional warning message to prepend.
   */
  protected function buildStatus(
    array $payload,
    bool $licensed,
    string $state = 'active',
    bool $offline = FALSE,
    string $warning = '',
  ): array {
    $config          = $this->configFactory->get('license_service.settings');
    $warnDays        = (int) ($config->get('expiry_warning_days') ?? 7);
    $expiresAt       = $payload['expires_at'] ?? NULL;
    $now             = new \DateTime('now', new \DateTimeZone('UTC'));
    $warnings        = [];
    $expiringSoon    = FALSE;
    $daysUntilExpiry = NULL;

    if ($warning !== '') {
      $warnings[] = $warning;
    }

    // Expiry warning (only while still licensed).
    if ($licensed && $expiresAt !== NULL) {
      $exp = $this->parseIso($expiresAt);
      if ($exp !== NULL) {
        $diff = $now->diff($exp);
        if ($exp > $now) {
          $daysUntilExpiry = (int) $diff->days;
          if ($daysUntilExpiry <= $warnDays) {
            $expiringSoon = TRUE;
            $kind = $payload['trial'] ?? FALSE ? 'trial' : 'license';
            $when = $exp->format('Y-m-d');
            $warnings[] = $daysUntilExpiry >= 1
              ? "Your {$kind} expires in {$daysUntilExpiry} day(s), on {$when}. Renew to avoid interruption."
              : "Your {$kind} expires today ({$when}). Renew to avoid interruption.";
          }
        }
      }
    }

    return [
      'licensed'          => $licensed,
      'tier'              => (string) ($payload['tier'] ?? 'free'),
      'features'          => (array) ($payload['features'] ?? []),
      'expires_at'        => $expiresAt,
      'trial'             => (bool) ($payload['trial'] ?? FALSE),
      'warnings'          => $warnings,
      'refresh_failed'    => FALSE,
      'expiring_soon'     => $expiringSoon,
      'days_until_expiry' => $daysUntilExpiry,
      'offline'           => $offline,
      // Preserve the caller-supplied state (e.g. 'grace') verbatim. Callers
      // pass the precise state: 'active' for a normal license, 'grace' during
      // the offline grace window, 'expired'/'revoked' when unlicensed. Forcing
      // 'active' whenever $licensed is TRUE masked the offline-grace state.
      'state'             => $state,
      'customer'          => (string) ($payload['customer'] ?? ''),
      'license_key'       => (string) ($payload['license_key'] ?? ''),
    ];
  }

  /**
   * Builds an unlicensed status array with a plain-English reason.
   */
  protected function unlicensedStatus(
    string $reason,
    bool $offline = FALSE,
    bool $refreshFailed = FALSE,
  ): array {
    return [
      'licensed'          => FALSE,
      'tier'              => 'free',
      'features'          => [],
      'expires_at'        => NULL,
      'trial'             => FALSE,
      'warnings'          => [$reason],
      'refresh_failed'    => $refreshFailed,
      'expiring_soon'     => FALSE,
      'days_until_expiry' => NULL,
      'offline'           => $offline,
      'state'             => 'unlicensed',
      'customer'          => '',
      'license_key'       => '',
    ];
  }

  /**
   * Parses an ISO 8601 UTC string into a DateTime, or returns NULL.
   */
  protected function parseIso(?string $s): ?\DateTime {
    if ($s === NULL || $s === '') {
      return NULL;
    }
    try {
      return new \DateTime($s, new \DateTimeZone('UTC'));
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Returns TRUE if the given host is a private/loopback address (SSRF guard).
   *
   * Blocks: localhost, 127.x.x.x, ::1, 10.x, 172.16-31.x, 192.168.x, 169.254.x.
   */
  protected function isPrivateHost(string $host): bool {
    $lower = strtolower($host);

    // Loopback and link-local hostnames.
    if (in_array($lower, ['localhost', 'localhost.localdomain', '::1', 'ip6-localhost'], TRUE)) {
      return TRUE;
    }
    if (str_ends_with($lower, '.local') || str_ends_with($lower, '.internal')) {
      return TRUE;
    }

    // If it looks like an IP, validate against private/reserved ranges.
    $ip = filter_var($host, FILTER_VALIDATE_IP);
    if ($ip !== FALSE) {
      // Returns FALSE if the IP is in a private or reserved range.
      return filter_var($ip, FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === FALSE;
    }

    return FALSE;
  }

  /**
   * Validates the URL's host and returns Guzzle options pinning resolved IPs.
   *
   * IsPrivateHost() blocks literal private/reserved IPs and well-known
   * private hostnames, but a public-looking hostname can still resolve to a
   * private address (DNS rebinding). This resolves the host's A and AAAA
   * records and rejects the request if any resolved IP is in a private or
   * reserved range.
   *
   * To close the residual TOCTOU window — where cURL would otherwise perform a
   * *second*, unvalidated DNS lookup at connect time and could be handed a
   * private IP — the validated addresses are pinned via CURLOPT_RESOLVE. cURL
   * then connects to exactly the IPs vetted here; the Host header and SNI keep
   * the original hostname, so TLS verification (verify: TRUE) is unaffected.
   *
   * @param string $url
   *   The absolute request URL.
   *
   * @return array<string, mixed>
   *   A Guzzle request-options fragment (possibly empty) to merge into the
   *   request — e.g. ['curl' => [CURLOPT_RESOLVE => ['host:443:1.2.3.4']]].
   *
   * @throws \RuntimeException
   *   If the host is private or resolves to a private/reserved address.
   */
  protected function assertPublicHost(string $url): array {
    $host = (string) parse_url($url, PHP_URL_HOST);
    if ($host === '') {
      // Empty host is handled (and defaulted) by getServerUrl() upstream.
      return [];
    }
    if ($this->isPrivateHost($host)) {
      throw new \RuntimeException('Refusing to contact a private or reserved host.');
    }

    // A literal IP host was already validated by isPrivateHost(); cURL uses it
    // directly with no second lookup, so there is nothing to pin.
    if (filter_var($host, FILTER_VALIDATE_IP) !== FALSE) {
      return [];
    }

    $ips = $this->resolveHostIps($host);
    // Be permissive when resolution yields nothing (transient DNS failure) —
    // let Guzzle attempt the connection and fail naturally rather than
    // blocking a legitimate server on a flaky resolver. Nothing to pin.
    if (empty($ips)) {
      return [];
    }

    foreach ($ips as $ip) {
      $public = filter_var($ip, FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
      if ($public === FALSE) {
        throw new \RuntimeException('Server hostname resolves to a private or reserved address.');
      }
    }

    // Pin the validated IPs so cURL connects to exactly these addresses,
    // eliminating the rebind window. Requires ext-curl (the constant is only
    // defined then) and the cURL handler; on other handlers this is a no-op
    // and the (already-vetted) hostname resolution stands.
    if (!defined('CURLOPT_RESOLVE')) {
      return [];
    }
    $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
    // Multiple comma-separated addresses are supported by cURL 7.59+ (2018);
    // Drupal 10/11 environments comfortably exceed that.
    return ['curl' => [CURLOPT_RESOLVE => [$host . ':' . $port . ':' . implode(',', $ips)]]];
  }

  /**
   * Resolves a hostname to its IPv4 and IPv6 addresses.
   *
   * @return string[]
   *   Resolved IP addresses (may be empty on resolution failure).
   */
  protected function resolveHostIps(string $host): array {
    $ips = [];

    $v4 = @gethostbynamel($host);
    if (is_array($v4)) {
      $ips = array_merge($ips, $v4);
    }

    $v6 = @dns_get_record($host, DNS_AAAA);
    if (is_array($v6)) {
      foreach ($v6 as $record) {
        if (!empty($record['ipv6'])) {
          $ips[] = (string) $record['ipv6'];
        }
      }
    }

    return array_values(array_unique($ips));
  }

  /**
   * Generates a stable site machine ID from a UUID + site URL hash.
   */
  protected function generateMachineId(): string {
    $uuid    = \Drupal::service('uuid')->generate();
    $siteUrl = \Drupal::request()->getSchemeAndHttpHost();
    return $uuid . '-' . substr(hash('sha256', $siteUrl), 0, 8);
  }

}
