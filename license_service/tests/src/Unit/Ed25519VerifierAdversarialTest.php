<?php

namespace Drupal\Tests\license_service\Unit;

use Drupal\license_service\Crypto\Ed25519Verifier;
use PHPUnit\Framework\TestCase;

/**
 * Adversarial tests for Ed25519Verifier: forged, expired, wrong-device, replay.
 *
 * All scenarios use a freshly generated keypair; no live server or hardcoded
 * keys needed. Tests verify that each attack vector is rejected before any
 * token claim is trusted.
 *
 * @group license_service
 * @coversDefaultClass \Drupal\license_service\Crypto\Ed25519Verifier
 *
 * Author: Jeremiah Buttler
 */
class Ed25519VerifierAdversarialTest extends TestCase {

  /**
   * The verifier under test.
   *
   * @var \Drupal\license_service\Crypto\Ed25519Verifier
   */
  protected Ed25519Verifier $verifier;

  /**
   * The base64-encoded Ed25519 public key.
   *
   * @var string
   */
  protected string $publicKeyB64;

  /**
   * The raw Ed25519 secret key used to sign test tokens.
   *
   * @var string
   */
  protected string $secretKey;

  /**
   * Sets up the verifier and a fresh signing keypair for each test.
   */
  protected function setUp(): void {
    parent::setUp();
    if (!extension_loaded('sodium')) {
      $this->markTestSkipped('The sodium extension is required for these tests.');
    }
    $this->verifier = new Ed25519Verifier();

    $keypair            = sodium_crypto_sign_keypair();
    $this->secretKey    = sodium_crypto_sign_secretkey($keypair);
    $this->publicKeyB64 = base64_encode(sodium_crypto_sign_publickey($keypair));
  }

  // --------------------------------------------------------------------------
  // Helpers

  /**
   * Builds a signed token from the given payload.
   */
  private function buildToken(array $payload): string {
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $sig  = sodium_crypto_sign_detached($body, $this->secretKey);
    return $this->verifier->b64uEncode($body) . '.' . $this->verifier->b64uEncode($sig);
  }

  /**
   * Returns a Unix timestamp offset from now by the given seconds.
   */
  private function timestamp(int $offsetSeconds = 0): int {
    return time() + $offsetSeconds;
  }

  // --------------------------------------------------------------------------
  // Expired token
  // --------------------------------------------------------------------------

  /**
   * A past-expiry token must still pass signature verification.
   *
   * Expiry is a business-logic check, not a crypto check.
   * (LicenseClient::buildStatus() rejects it; the verifier just checks the sig.)
   *
   * @covers ::verify
   */
  public function testExpiredTokenPassesCryptoButCarriesExpiry(): void {
    $payload = [
      'product_id' => 'drupal_license',
      'licensed'   => TRUE,
    // 1 hour ago
      'expires_at' => date('c', $this->timestamp(-3600)),
    ];
    $token = $this->buildToken($payload);
    $result = $this->verifier->verify($token, $this->publicKeyB64);
    // Signature is valid — verifier returns payload.
    $this->assertTrue($result['licensed']);
    // Expiry claim is present for the caller to enforce.
    $this->assertArrayHasKey('expires_at', $result);
  }

  /**
   * An expired token with a forged longer expiry must fail signature verification.
   *
   * @covers ::verify
   */
  public function testForgingExpiryOnExpiredTokenFails(): void {
    $payload = [
      'product_id' => 'drupal_license',
      'licensed'   => TRUE,
      'expires_at' => date('c', $this->timestamp(-3600)),
    ];
    $token = $this->buildToken($payload);

    // Decode body, extend expires_at, re-encode keeping original signature.
    [$bodyB64, $sigB64] = explode('.', $token, 2);
    $body               = json_decode($this->verifier->b64uDecode($bodyB64), TRUE);
    // fake: 1 day future.
    $body['expires_at'] = date('c', $this->timestamp(86400));
    $tamperedBodyB64    = $this->verifier->b64uEncode(json_encode($body));
    $tamperedToken      = $tamperedBodyB64 . '.' . $sigB64;

    $this->expectException(\InvalidArgumentException::class);
    $this->verifier->verify($tamperedToken, $this->publicKeyB64);
  }

  // --------------------------------------------------------------------------
  // Wrong device (machine_id mismatch)
  // --------------------------------------------------------------------------

  /**
   * A token issued for a different machine_id passes crypto.
   *
   * The caller must compare machine_id to the site's own ID.
   *
   * @covers ::verify
   */
  public function testWrongMachineIdPassesCryptoButExposesId(): void {
    $payload = [
      'product_id' => 'drupal_license',
      'licensed'   => TRUE,
      'machine_id' => 'other-site-machine-id',
    ];
    $token = $this->buildToken($payload);
    $result = $this->verifier->verify($token, $this->publicKeyB64);
    // Verifier returns payload; mismatch detection is the caller's responsibility.
    $this->assertSame('other-site-machine-id', $result['machine_id']);
  }

  /**
   * Forging the machine_id to match the current site must fail crypto.
   *
   * @covers ::verify
   */
  public function testForgingMachineIdFails(): void {
    $payload = ['product_id' => 'drupal_license', 'machine_id' => 'original-id'];
    $token   = $this->buildToken($payload);

    [$bodyB64, $sigB64] = explode('.', $token, 2);
    $body = json_decode($this->verifier->b64uDecode($bodyB64), TRUE);
    // Attacker replaces machine_id.
    $body['machine_id'] = 'my-site-id';
    $tamperedToken = $this->verifier->b64uEncode(json_encode($body)) . '.' . $sigB64;

    $this->expectException(\InvalidArgumentException::class);
    $this->verifier->verify($tamperedToken, $this->publicKeyB64);
  }

  // --------------------------------------------------------------------------
  // Wrong product_id
  // --------------------------------------------------------------------------

  /**
   * A token for a different product passes crypto — caller must check product_id.
   *
   * @covers ::verify
   */
  public function testWrongProductIdPassesCryptoButExposesId(): void {
    $payload = ['product_id' => 'some_other_product', 'licensed' => TRUE];
    $token   = $this->buildToken($payload);
    $result  = $this->verifier->verify($token, $this->publicKeyB64);
    $this->assertSame('some_other_product', $result['product_id']);
  }

  /**
   * Replacing product_id in an existing token body must fail crypto.
   *
   * @covers ::verify
   */
  public function testForgingProductIdFails(): void {
    $payload = ['product_id' => 'some_other_product', 'licensed' => FALSE];
    $token   = $this->buildToken($payload);

    [$bodyB64, $sigB64] = explode('.', $token, 2);
    $body = json_decode($this->verifier->b64uDecode($bodyB64), TRUE);
    // Attacker upgrades product_id.
    $body['product_id'] = 'drupal_license';
    $tamperedToken = $this->verifier->b64uEncode(json_encode($body)) . '.' . $sigB64;

    $this->expectException(\InvalidArgumentException::class);
    $this->verifier->verify($tamperedToken, $this->publicKeyB64);
  }

  // --------------------------------------------------------------------------
  // Replay attack — re-using a revoked token
  // --------------------------------------------------------------------------

  /**
   * A previously valid token re-presented later still passes crypto verification.
   *
   * This is by design: revocation is enforced at LicenseClient::verify() level
   * (server call), not at the cryptographic layer. The test documents that the
   * verifier does NOT implement replay prevention — that's the caller's job.
   *
   * @covers ::verify
   */
  public function testReplayedTokenPassesCrypto(): void {
    $payload = [
      'product_id' => 'drupal_license',
      'licensed'   => TRUE,
    // Issued 2h ago.
      'issued_at'  => date('c', $this->timestamp(-7200)),
    ];
    $token = $this->buildToken($payload);
    $result = $this->verifier->verify($token, $this->publicKeyB64);
    // Still verifies — revocation must be handled by LicenseClient::verify().
    $this->assertTrue($result['licensed']);
  }

  /**
   * Forging a nonce or issued_at on a replayed token must fail crypto.
   *
   * @covers ::verify
   */
  public function testForgingReplayNonceFails(): void {
    $payload = [
      'product_id' => 'drupal_license',
      'licensed'   => TRUE,
      'issued_at'  => date('c', $this->timestamp(-7200)),
      'nonce'      => 'abc123',
    ];
    $token = $this->buildToken($payload);

    [$bodyB64, $sigB64] = explode('.', $token, 2);
    $body = json_decode($this->verifier->b64uDecode($bodyB64), TRUE);
    // Attacker changes nonce to avoid replay detection.
    $body['nonce'] = 'xyz999';
    $tamperedToken = $this->verifier->b64uEncode(json_encode($body)) . '.' . $sigB64;

    $this->expectException(\InvalidArgumentException::class);
    $this->verifier->verify($tamperedToken, $this->publicKeyB64);
  }

  // --------------------------------------------------------------------------
  // Tier/feature elevation attacks
  // --------------------------------------------------------------------------

  /**
   * Upgrading tier in a token body must fail crypto.
   *
   * @covers ::verify
   */
  public function testForgingTierElevationFails(): void {
    $payload = ['product_id' => 'drupal_license', 'licensed' => TRUE, 'tier' => 'free'];
    $token   = $this->buildToken($payload);

    [$bodyB64, $sigB64] = explode('.', $token, 2);
    $body = json_decode($this->verifier->b64uDecode($bodyB64), TRUE);
    // Attacker claims enterprise tier.
    $body['tier'] = 'enterprise';
    $tamperedToken = $this->verifier->b64uEncode(json_encode($body)) . '.' . $sigB64;

    $this->expectException(\InvalidArgumentException::class);
    $this->verifier->verify($tamperedToken, $this->publicKeyB64);
  }

  /**
   * Adding a feature flag not present in the original payload must fail crypto.
   *
   * @covers ::verify
   */
  public function testInjectingFeatureFlagFails(): void {
    $payload = [
      'product_id' => 'drupal_license',
      'licensed'   => TRUE,
      'tier'       => 'free',
      'features'   => [],
    ];
    $token = $this->buildToken($payload);

    [$bodyB64, $sigB64] = explode('.', $token, 2);
    $body = json_decode($this->verifier->b64uDecode($bodyB64), TRUE);
    // Attacker enables premium feature.
    $body['features']['field_gating'] = TRUE;
    $tamperedToken = $this->verifier->b64uEncode(json_encode($body)) . '.' . $sigB64;

    $this->expectException(\InvalidArgumentException::class);
    $this->verifier->verify($tamperedToken, $this->publicKeyB64);
  }

  /**
   * Changing licensed=false to licensed=true must fail crypto.
   *
   * @covers ::verify
   */
  public function testForgingLicensedFlagFails(): void {
    $payload = ['product_id' => 'drupal_license', 'licensed' => FALSE];
    $token   = $this->buildToken($payload);

    [$bodyB64, $sigB64] = explode('.', $token, 2);
    $body = json_decode($this->verifier->b64uDecode($bodyB64), TRUE);
    // Attacker unlocks the site.
    $body['licensed'] = TRUE;
    $tamperedToken = $this->verifier->b64uEncode(json_encode($body)) . '.' . $sigB64;

    $this->expectException(\InvalidArgumentException::class);
    $this->verifier->verify($tamperedToken, $this->publicKeyB64);
  }

  // --------------------------------------------------------------------------
  // Structural / encoding attacks
  // --------------------------------------------------------------------------

  /**
   * A token with no dot separator must fail.
   *
   * @covers ::verify
   */
  public function testTokenWithNoDotFails(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->verifier->verify(
      $this->verifier->b64uEncode('{"product_id":"drupal_license"}'),
      $this->publicKeyB64,
    );
  }

  /**
   * A token with multiple dots must fail (not a valid two-part structure).
   *
   * @covers ::verify
   */
  public function testTokenWithMultipleDotsFails(): void {
    $payload = ['product_id' => 'drupal_license'];
    $token   = $this->buildToken($payload);
    // Inject an extra dot into the body part to create three segments.
    $extra = $token . '.extragarbage';
    // This is still two parts after explode(..., 2); the sig part is corrupted.
    $this->expectException(\InvalidArgumentException::class);
    $this->verifier->verify($extra, $this->publicKeyB64);
  }

  /**
   * An all-zero signature (64 null bytes) must fail.
   *
   * @covers ::verify
   */
  public function testAllZeroSignatureFails(): void {
    $body    = $this->verifier->b64uEncode('{"product_id":"drupal_license"}');
    $zeroSig = $this->verifier->b64uEncode(str_repeat("\x00", SODIUM_CRYPTO_SIGN_BYTES));
    $this->expectException(\InvalidArgumentException::class);
    $this->verifier->verify($body . '.' . $zeroSig, $this->publicKeyB64);
  }

  /**
   * An empty body segment must fail.
   *
   * @covers ::verify
   */
  public function testEmptyBodyFails(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->verifier->verify('.validlookingsig', $this->publicKeyB64);
  }

}
