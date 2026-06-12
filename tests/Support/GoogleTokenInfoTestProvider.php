<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Tests\Support;

use Glueful\Extensions\Entrada\Providers\GoogleAuthProvider;

/**
 * Test double exposing GoogleAuthProvider's network-free tokeninfo-claims validator without the
 * container-backed constructor. The HTTP round-trip is not exercised here; only the aud/iss
 * fail-closed checks and the profile mapping are. The expected client ID is injected via
 * reflection because it is private on the parent.
 */
final class GoogleTokenInfoTestProvider extends GoogleAuthProvider
{
    public function __construct(string $clientId)
    {
        // Intentionally bypass parent::__construct() (which resolves the HTTP client and reads
        // Google credentials from config); the validator under test is self-contained.
        $ref = new \ReflectionProperty(GoogleAuthProvider::class, 'clientId');
        $ref->setAccessible(true);
        $ref->setValue($this, $clientId);
    }

    /**
     * @param array<string, mixed> $tokenInfo
     * @return array<string, mixed>
     */
    public function exposedValidate(array $tokenInfo): array
    {
        return $this->validateTokenInfoClaims($tokenInfo);
    }
}
