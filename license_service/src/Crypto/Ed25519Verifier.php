<?php

namespace Drupal\license_service\Crypto;

/**
 * Verifies Ed25519-signed license tokens from the License Verification Server.
 *
 * Token format: b64url(json_payload).b64url(ed25519_signature)
 * This mirrors the Python license_client.py token contract exactly, using PHP's
 * built-in libsodium (sodium_crypto_sign_verify_detached).
 *
 * The signature MUST be verified before any payload claim is trusted.
 * This class performs no expiry or machine-ID check — callers enforce those.
 *
 * Author: Jeremiah Buttler
 */
final class Ed25519Verifier {

  /**
   * Verifies the token signature and returns the decoded payload.
   *
   * @param string $token
   *   The raw token string: b64url(json).b64url(sig).
   * @param string $publicKeyB64
   *   Standard base64-encoded raw 32-byte Ed25519 public key.
   *
   * @return array
   *   The verified JSON payload as an associative array.
   *
   * @throws \RuntimeException
   *   When the sodium extension is absent.
   * @throws \InvalidArgumentException
   *   On malformed format, bad public key, wrong signature length, or
   *   failed signature verification.
   */
  public function verify(string $token, string $publicKeyB64): array {
    if (!extension_loaded('sodium')) {
      throw new \RuntimeException('The PHP sodium extension is required for license token verification.');
    }

    $parts = explode('.', $token, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
      throw new \InvalidArgumentException('Malformed token: expected body.signature format.');
    }

    [$bodyB64, $sigB64] = $parts;
    $body               = $this->b64uDecode($bodyB64);
    $sig                = $this->b64uDecode($sigB64);
    $pubKey             = base64_decode($publicKeyB64, TRUE);

    if ($pubKey === FALSE || strlen($pubKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
      throw new \InvalidArgumentException('Invalid public key: expected a 32-byte Ed25519 key, base64-encoded.');
    }
    if (strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
      throw new \InvalidArgumentException('Invalid signature length.');
    }
    if (!sodium_crypto_sign_verify_detached($sig, $body, $pubKey)) {
      throw new \InvalidArgumentException('Token signature verification failed.');
    }

    try {
      $payload = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new \InvalidArgumentException('Token payload is not valid JSON: ' . $e->getMessage());
    }

    if (!is_array($payload)) {
      throw new \InvalidArgumentException('Token payload must be a JSON object.');
    }

    return $payload;
  }

  /**
   * Decodes the token body WITHOUT verifying the signature.
   *
   * Use only for inspecting the kid/header before selecting a public key.
   * Never trust any claim from this method without also calling verify().
   *
   * @param string $token
   *   The raw token string.
   *
   * @return array
   *   The unverified JSON payload.
   *
   * @throws \InvalidArgumentException
   */
  public function decode(string $token): array {
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
      throw new \InvalidArgumentException('Malformed token.');
    }
    try {
      return json_decode($this->b64uDecode($parts[0]), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new \InvalidArgumentException('Token payload is not valid JSON: ' . $e->getMessage());
    }
  }

  /**
   * Decodes a base64url-no-padding string to raw bytes.
   *
   * @throws \InvalidArgumentException
   *   On invalid base64url input.
   */
  public function b64uDecode(string $encoded): string {
    $base64 = strtr($encoded, '-_', '+/');
    $pad = strlen($base64) % 4;
    if ($pad === 2) {
      $base64 .= '==';
    }
    elseif ($pad === 3) {
      $base64 .= '=';
    }
    $decoded = base64_decode($base64, TRUE);
    if ($decoded === FALSE) {
      throw new \InvalidArgumentException('Invalid base64url encoding.');
    }
    return $decoded;
  }

  /**
   * Encodes raw bytes as base64url without padding.
   */
  public function b64uEncode(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
  }

}
