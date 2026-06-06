<?php

namespace Drupal\Tests\license_service\Unit;

use Drupal\license_service\Crypto\Ed25519Verifier;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Ed25519Verifier.
 *
 * These tests use a freshly generated keypair so they run without any
 * live server, hardcoded keys, or network access.
 *
 * @group license_service
 * @coversDefaultClass \Drupal\license_service\Crypto\Ed25519Verifier
 *
 * Author: Jeremiah Buttler
 */
class Ed25519VerifierTest extends TestCase {

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
      $this->markTestSkipped('The sodium extension is required for Ed25519Verifier tests.');
    }
    $this->verifier = new Ed25519Verifier();

    // Generate a throw-away keypair for each test run.
    $keypair            = sodium_crypto_sign_keypair();
    $this->secretKey    = sodium_crypto_sign_secretkey($keypair);
    $pubKeyRaw          = sodium_crypto_sign_publickey($keypair);
    $this->publicKeyB64 = base64_encode($pubKeyRaw);
  }

  /**
   * Builds a valid signed token with the given payload.
   */
  private function buildToken(array $payload): string {
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $sig  = sodium_crypto_sign_detached($body, $this->secretKey);
    return $this->verifier->b64uEncode($body) . '.' . $this->verifier->b64uEncode($sig);
  }

  /**
   * @covers ::verify
   */
  public function testVerifyValidToken(): void {
    $payload = [
      'product_id' => 'drupal_license',
      'tier'       => 'pro',
      'licensed'   => TRUE,
    ];
    $token = $this->buildToken($payload);
    $result = $this->verifier->verify($token, $this->publicKeyB64);
    $this->assertSame('drupal_license', $result['product_id']);
    $this->assertSame('pro', $result['tier']);
  }

  /**
   * @covers ::verify
   */
  public function testVerifyForgedSignatureThrows(): void {
    $payload = ['product_id' => 'drupal_license'];
    $token   = $this->buildToken($payload);

    // Corrupt the signature part (everything after the dot).
    [$body, $sig] = explode('.', $token, 2);
    $badSig = $this->verifier->b64uEncode(str_repeat("\x00", SODIUM_CRYPTO_SIGN_BYTES));
    $badToken = $body . '.' . $badSig;

    $this->expectException(\InvalidArgumentException::class);
    $this->verifier->verify($badToken, $this->publicKeyB64);
  }

  /**
   * @covers ::verify
   */
  public function testVerifyMalformedTokenThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->verifier->verify('notavalidtoken', $this->publicKeyB64);
  }

  /**
   * @covers ::verify
   */
  public function testVerifyTamperedPayloadThrows(): void {
    $token = $this->buildToken(['product_id' => 'drupal_license', 'tier' => 'free']);

    // Decode body, change tier, re-encode with original (mismatching) signature.
    [$bodyB64, $sigB64] = explode('.', $token, 2);
    $body               = json_decode($this->verifier->b64uDecode($bodyB64), TRUE);
    // Tampered.
    $body['tier']  = 'enterprise';
    $newBodyB64    = $this->verifier->b64uEncode(json_encode($body));
    $tamperedToken = $newBodyB64 . '.' . $sigB64;

    $this->expectException(\InvalidArgumentException::class);
    $this->verifier->verify($tamperedToken, $this->publicKeyB64);
  }

  /**
   * @covers ::verify
   */
  public function testVerifyWrongPublicKeyThrows(): void {
    $payload = ['product_id' => 'drupal_license'];
    $token   = $this->buildToken($payload);

    // Generate a different keypair and use its public key.
    $otherKeypair = sodium_crypto_sign_keypair();
    $otherPub     = base64_encode(sodium_crypto_sign_publickey($otherKeypair));

    $this->expectException(\InvalidArgumentException::class);
    $this->verifier->verify($token, $otherPub);
  }

  /**
   * @covers ::decode
   */
  public function testDecodeWithoutVerification(): void {
    $payload = ['product_id' => 'drupal_license', 'tier' => 'pro'];
    $token   = $this->buildToken($payload);
    $result  = $this->verifier->decode($token);
    $this->assertSame('pro', $result['tier']);
  }

  /**
   * @covers ::b64uDecode
   * @covers ::b64uEncode
   */
  public function testRoundtripEncoding(): void {
    $raw     = random_bytes(64);
    $encoded = $this->verifier->b64uEncode($raw);
    $this->assertSame($raw, $this->verifier->b64uDecode($encoded));
    // Must not contain +, /, or = characters.
    $this->assertStringNotContainsString('+', $encoded);
    $this->assertStringNotContainsString('/', $encoded);
    $this->assertStringNotContainsString('=', $encoded);
  }

}
