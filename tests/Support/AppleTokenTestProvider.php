<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Tests\Support;

use Glueful\Cache\CacheStore;
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

    /** @var array<string, mixed> JWKS returned by the (overridden) network fetch. */
    private array $fetchJwks = ['keys' => []];

    /** @var int Number of times the simulated JWKS network fetch was invoked. */
    public int $fetchCount = 0;

    private ?CacheStore $injectedCache = null;

    public function __construct()
    {
        // Intentionally bypass parent::__construct() (which resolves the HTTP client and
        // reads Apple credentials from config); the verifier under test is self-contained.
    }

    /**
     * Configure the simulated network JWKS + the cache used by the caching path under test, so
     * getAppleJwks()/verifyAndDecodeIdToken() can run without a real container or HTTP client.
     *
     * @param array<string, mixed> $fetchJwks
     */
    public function withFetchJwks(array $fetchJwks, ?CacheStore $cache): self
    {
        $this->fetchJwks = $fetchJwks;
        $this->injectedCache = $cache;

        return $this;
    }

    /**
     * Mark the provider's Apple credentials as configured (so verifyNativeToken's config guard
     * passes) and supply the aud/iss used when verifying. The credential fields are private on the
     * parent, so they are populated via reflection — the production guard then sees them as set.
     */
    public function asConfigured(string $aud, string $iss): self
    {
        $this->injectedAud = $aud;
        $this->injectedIss = $iss;

        $reflection = new \ReflectionClass(AppleAuthProvider::class);
        foreach (['clientId' => $aud, 'teamId' => 'TEAMID', 'keyId' => 'KEYID'] as $prop => $value) {
            $property = $reflection->getProperty($prop);
            $property->setValue($this, $value);
        }

        return $this;
    }

    /** Expose the native nonce-binding decision for direct unit assertions. */
    public function exposedNativeNonceMatches(mixed $tokenNonce, string $rawNonce): bool
    {
        return $this->nativeNonceMatches($tokenNonce, $rawNonce);
    }

    /**
     * Drive the JWKS-caching decision (cache hit / fetch-and-cache / kid-miss refetch) using the
     * simulated fetch + injected cache from withFetchJwks().
     *
     * @return array<string, mixed>
     */
    public function exposedGetAppleJwks(string $idToken): array
    {
        return $this->getAppleJwks($idToken);
    }

    /** Return the injected cache (or null) instead of resolving from a container. */
    protected function resolveCache(): ?CacheStore
    {
        return $this->injectedCache;
    }

    /** Simulate the network JWKS fetch, counting invocations so tests can assert refetch behaviour. */
    protected function fetchAppleJwks(): array
    {
        $this->fetchCount++;

        return $this->fetchJwks;
    }

    /**
     * Drive verifyNativeToken() end to end, network-free: verification uses the injected JWKS and
     * user creation is stubbed (see findOrCreateUser below), so the nonce-binding decision is the
     * only behaviour under test.
     *
     * @return array<string, mixed>|null
     */
    public function exposedVerifyNativeToken(string $idToken, ?string $rawNonce = null): ?array
    {
        return $this->verifyNativeToken($idToken, $rawNonce);
    }

    /** Stub out the DB-backed user lookup; tests only assert acceptance/rejection. */
    protected function findOrCreateUser(array $socialData): ?array
    {
        return ['uuid' => 'test-uuid'] + $socialData;
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
