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
}
