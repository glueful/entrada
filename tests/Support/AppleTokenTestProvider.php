<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Tests\Support;

use Glueful\Extensions\Entrada\Providers\AppleAuthProvider;

/**
 * Test double exposing AppleAuthProvider's network-free ID-token verifier without the
 * container-backed constructor. The verifier takes the JWKS + expected aud/iss as
 * arguments, so no provider configuration is needed.
 */
final class AppleTokenTestProvider extends AppleAuthProvider
{
    /** @var array<string, mixed> JWKS injected for the network-free profile-extraction path. */
    private array $injectedJwks = ['keys' => []];
    private string $injectedAud = 'com.example.service';
    private string $injectedIss = 'https://appleid.apple.com';

    public function __construct()
    {
        // Intentionally bypass parent::__construct() (which resolves the HTTP client and
        // reads Apple credentials from config); the verifier under test is self-contained.
    }

    /**
     * @param array<string, mixed> $jwks
     * @return array<string, mixed>
     */
    public function exposedVerify(string $idToken, array $jwks, string $aud, string $iss, ?int $now = null): array
    {
        return $this->verifyIdTokenWithJwks($idToken, $jwks, $aud, $iss, $now);
    }

    /**
     * Supply the JWKS + expected aud/iss used by the overridden verify step so extractUserProfile()
     * can be exercised without a network fetch or container-backed credentials.
     *
     * @param array<string, mixed> $jwks
     */
    public function withJwks(array $jwks, string $aud, string $iss): self
    {
        $this->injectedJwks = $jwks;
        $this->injectedAud = $aud;
        $this->injectedIss = $iss;

        return $this;
    }

    /**
     * @param array<string, mixed>|null $userData
     * @return array<string, mixed>
     */
    public function exposedExtractUserProfile(string $idToken, ?array $userData = null): array
    {
        return $this->extractUserProfile($idToken, $userData);
    }

    public function exposedIsVerifiedFlagPublic(mixed $value): bool
    {
        return $this->isVerifiedFlag($value);
    }

    /**
     * Override the network/config-backed verify step with the injected JWKS so the profile-shaping
     * logic in extractUserProfile() runs against a real, cryptographically verified payload.
     *
     * @return array<string, mixed>
     */
    protected function verifyAndDecodeIdToken(string $idToken): array
    {
        return $this->verifyIdTokenWithJwks(
            $idToken,
            $this->injectedJwks,
            $this->injectedAud,
            $this->injectedIss
        );
    }
}
