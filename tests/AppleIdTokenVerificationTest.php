<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Tests;

use Glueful\Extensions\Entrada\Tests\Support\AppleTokenTestProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Apple ID-token verifier must cryptographically verify the RS256 signature against the
 * JWKS public key and assert iss/aud/exp BEFORE any claim is trusted. These tests mint real
 * RSA-signed tokens and a matching JWKS, so they exercise the actual signature path — a forged
 * or wrongly-signed token must be rejected.
 */
final class AppleIdTokenVerificationTest extends TestCase
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
     * @param array<string, mixed> $headerOverride
     * @param array<string, mixed> $payloadOverride
     */
    private function makeToken(
        array $headerOverride = [],
        array $payloadOverride = [],
        bool $tamper = false,
        ?\OpenSSLAsymmetricKey $signWith = null
    ): string {
        $header = array_merge(['alg' => 'RS256', 'kid' => $this->kid, 'typ' => 'JWT'], $headerOverride);
        $payload = array_merge([
            'iss' => $this->iss,
            'aud' => $this->aud,
            'sub' => '000123.victimappleid.456',
            'email' => 'victim@example.com',
            'email_verified' => 'true',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $payloadOverride);

        $signingInput = $this->b64u((string) json_encode($header)) . '.' . $this->b64u((string) json_encode($payload));

        $signature = '';
        openssl_sign($signingInput, $signature, $signWith ?? $this->privateKey, OPENSSL_ALGO_SHA256);
        if ($tamper) {
            $signature = strrev($signature);
        }

        return $signingInput . '.' . $this->b64u($signature);
    }

    private function verifier(): AppleTokenTestProvider
    {
        return new AppleTokenTestProvider();
    }

    public function test_accepts_a_properly_signed_token_and_returns_claims(): void
    {
        $claims = $this->verifier()->exposedVerify($this->makeToken(), $this->jwks, $this->aud, $this->iss);

        self::assertSame('000123.victimappleid.456', $claims['sub']);
        self::assertSame('victim@example.com', $claims['email']);
    }

    public function test_rejects_a_tampered_signature(): void
    {
        $this->expectException(\Exception::class);
        $this->verifier()->exposedVerify($this->makeToken(tamper: true), $this->jwks, $this->aud, $this->iss);
    }

    public function test_rejects_a_token_signed_by_a_different_key(): void
    {
        $attackerKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]) ?: throw new \RuntimeException('Unable to generate attacker key');

        $token = $this->makeToken(signWith: $attackerKey);

        $this->expectException(\Exception::class);
        $this->verifier()->exposedVerify($token, $this->jwks, $this->aud, $this->iss);
    }

    public function test_rejects_alg_none(): void
    {
        $header = $this->b64u((string) json_encode(['alg' => 'none', 'kid' => $this->kid]));
        $payload = $this->b64u((string) json_encode([
            'iss' => $this->iss,
            'aud' => $this->aud,
            'sub' => 'x',
            'exp' => time() + 3600,
        ]));
        $forged = $header . '.' . $payload . '.';

        $this->expectException(\Exception::class);
        $this->verifier()->exposedVerify($forged, $this->jwks, $this->aud, $this->iss);
    }

    public function test_rejects_wrong_audience(): void
    {
        $token = $this->makeToken(payloadOverride: ['aud' => 'com.attacker.service']);

        $this->expectException(\Exception::class);
        $this->verifier()->exposedVerify($token, $this->jwks, $this->aud, $this->iss);
    }

    public function test_rejects_wrong_issuer(): void
    {
        $token = $this->makeToken(payloadOverride: ['iss' => 'https://evil.example.com']);

        $this->expectException(\Exception::class);
        $this->verifier()->exposedVerify($token, $this->jwks, $this->aud, $this->iss);
    }

    public function test_rejects_expired_token(): void
    {
        $token = $this->makeToken(payloadOverride: ['exp' => time() - 10]);

        $this->expectException(\Exception::class);
        $this->verifier()->exposedVerify($token, $this->jwks, $this->aud, $this->iss);
    }

    public function test_rejects_unknown_kid(): void
    {
        $token = $this->makeToken(headerOverride: ['kid' => 'no-such-kid']);

        $this->expectException(\Exception::class);
        $this->verifier()->exposedVerify($token, $this->jwks, $this->aud, $this->iss);
    }

    public function test_accepts_audience_array_containing_client_id(): void
    {
        $token = $this->makeToken(payloadOverride: ['aud' => ['other.app', $this->aud]]);

        $claims = $this->verifier()->exposedVerify($token, $this->jwks, $this->aud, $this->iss);
        self::assertSame($this->aud, $claims['aud'][1]);
    }

    public function test_profile_promotes_bool_true_email_verified_to_top_level(): void
    {
        $token = $this->makeToken(payloadOverride: ['email_verified' => true]);

        $profile = $this->verifier()
            ->withJwks($this->jwks, $this->aud, $this->iss)
            ->exposedExtractUserProfile($token);

        self::assertArrayHasKey('email_verified', $profile);
        self::assertTrue($this->verifier()->exposedIsVerifiedFlagPublic($profile['email_verified']));
    }

    public function test_profile_promotes_string_true_email_verified_to_top_level(): void
    {
        // Apple commonly sends email_verified as the JSON string "true".
        $token = $this->makeToken(payloadOverride: ['email_verified' => 'true']);

        $profile = $this->verifier()
            ->withJwks($this->jwks, $this->aud, $this->iss)
            ->exposedExtractUserProfile($token);

        self::assertSame('true', $profile['email_verified']);
        self::assertTrue($this->verifier()->exposedIsVerifiedFlagPublic($profile['email_verified']));
    }

    public function test_profile_keeps_false_email_verified_unverified(): void
    {
        $token = $this->makeToken(payloadOverride: ['email_verified' => false]);

        $profile = $this->verifier()
            ->withJwks($this->jwks, $this->aud, $this->iss)
            ->exposedExtractUserProfile($token);

        self::assertFalse($this->verifier()->exposedIsVerifiedFlagPublic($profile['email_verified']));
    }

    public function test_profile_treats_absent_email_verified_as_unverified(): void
    {
        // Remove the default email_verified claim entirely.
        $token = $this->makeToken(payloadOverride: ['email_verified' => null]);

        $profile = $this->verifier()
            ->withJwks($this->jwks, $this->aud, $this->iss)
            ->exposedExtractUserProfile($token);

        self::assertArrayHasKey('email_verified', $profile);
        self::assertFalse($this->verifier()->exposedIsVerifiedFlagPublic($profile['email_verified']));
    }
}
