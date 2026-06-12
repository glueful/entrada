<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Tests;

use Glueful\Extensions\Entrada\Tests\Support\AppleTokenTestProvider;
use PHPUnit\Framework\TestCase;

/**
 * Native (SDK) ID-token verification must support opt-in nonce binding to close the
 * captured-token replay window. When the client supplies the raw nonce it bound to its Sign in
 * with Apple request, the verified token's `nonce` claim must match it (the SDK convention is the
 * claim is sha256(rawNonce)); when no raw nonce is supplied, existing clients keep working
 * unchanged. These tests mint real RSA-signed tokens so verification runs the actual signature
 * path, then exercise the nonce decision.
 */
final class AppleNativeNonceTest extends TestCase
{
    private \OpenSSLAsymmetricKey $privateKey;
    /** @var array<string, mixed> */
    private array $jwks;
    private string $kid = 'test-key-1';
    private string $aud = 'com.example.service';
    private string $iss = 'https://appleid.apple.com';

    protected function setUp(): void
    {
        $this->privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]) ?: throw new \RuntimeException('Unable to generate test RSA key');

        $details = openssl_pkey_get_details($this->privateKey);
        $this->jwks = ['keys' => [[
            'kty' => 'RSA',
            'kid' => $this->kid,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => $this->b64u($details['rsa']['n']),
            'e' => $this->b64u($details['rsa']['e']),
        ]]];
    }

    private function b64u(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @param array<string, mixed> $payloadOverride
     */
    private function makeToken(array $payloadOverride = []): string
    {
        $header = ['alg' => 'RS256', 'kid' => $this->kid, 'typ' => 'JWT'];
        $payload = array_merge([
            'iss' => $this->iss,
            'aud' => $this->aud,
            'sub' => '000123.appleid.456',
            'email' => 'user@example.com',
            'email_verified' => 'true',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $payloadOverride);

        $signingInput = $this->b64u((string) json_encode($header)) . '.' . $this->b64u((string) json_encode($payload));

        $signature = '';
        openssl_sign($signingInput, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return $signingInput . '.' . $this->b64u($signature);
    }

    private function provider(): AppleTokenTestProvider
    {
        return (new AppleTokenTestProvider())
            ->withJwks($this->jwks, $this->aud, $this->iss)
            ->asConfigured($this->aud, $this->iss);
    }

    public function test_accepts_token_when_nonce_claim_is_sha256_of_supplied_raw_nonce(): void
    {
        $rawNonce = 'client-generated-nonce-123';
        $token = $this->makeToken(['nonce' => hash('sha256', $rawNonce)]);

        $result = $this->provider()->exposedVerifyNativeToken($token, $rawNonce);

        self::assertIsArray($result);
        self::assertSame('000123.appleid.456', $result['id']);
    }

    public function test_accepts_token_when_nonce_claim_equals_raw_value(): void
    {
        // Some clients set the raw value directly as the nonce claim.
        $rawNonce = 'raw-equality-nonce';
        $token = $this->makeToken(['nonce' => $rawNonce]);

        $result = $this->provider()->exposedVerifyNativeToken($token, $rawNonce);

        self::assertIsArray($result);
    }

    public function test_rejects_token_when_supplied_raw_nonce_does_not_match_claim(): void
    {
        $token = $this->makeToken(['nonce' => hash('sha256', 'the-real-nonce')]);

        $result = $this->provider()->exposedVerifyNativeToken($token, 'a-different-nonce');

        self::assertNull($result);
    }

    public function test_rejects_token_missing_nonce_claim_when_raw_nonce_supplied(): void
    {
        // No nonce claim on the token, but the client bound one — this is the replay it must block.
        $token = $this->makeToken();

        $result = $this->provider()->exposedVerifyNativeToken($token, 'client-nonce');

        self::assertNull($result);
    }

    public function test_accepts_token_with_no_raw_nonce_supplied_preserving_existing_clients(): void
    {
        // Current behaviour: without a supplied raw nonce, the nonce claim is not enforced.
        $token = $this->makeToken();

        $result = $this->provider()->exposedVerifyNativeToken($token);

        self::assertIsArray($result);
        self::assertSame('000123.appleid.456', $result['id']);
    }

    public function test_accepts_token_with_empty_raw_nonce_treated_as_not_supplied(): void
    {
        $token = $this->makeToken();

        $result = $this->provider()->exposedVerifyNativeToken($token, '');

        self::assertIsArray($result);
    }

    public function test_nonce_decision_uses_constant_time_match_for_hash_and_raw(): void
    {
        $provider = new AppleTokenTestProvider();

        self::assertTrue($provider->exposedNativeNonceMatches(hash('sha256', 'abc'), 'abc'));
        self::assertTrue($provider->exposedNativeNonceMatches('abc', 'abc'));
        self::assertFalse($provider->exposedNativeNonceMatches('wrong', 'abc'));
        self::assertFalse($provider->exposedNativeNonceMatches(null, 'abc'));
        self::assertFalse($provider->exposedNativeNonceMatches('', 'abc'));
    }
}
