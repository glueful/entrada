<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Tests;

use Glueful\Extensions\Entrada\Tests\Support\AppleTokenTestProvider;
use Glueful\Extensions\Entrada\Tests\Support\InMemoryCacheStore;
use PHPUnit\Framework\TestCase;

/**
 * Apple's JWKS must be cached so it stays out of the critical path of every login. These tests
 * drive the cache decision (getAppleJwks) with an in-memory CacheStore and a counting simulated
 * fetch: a warm cache must not refetch, and a token whose kid is absent from the cached set (key
 * rotation) must trigger exactly one refetch. When no cache store is available, verification must
 * still work by fetching directly.
 */
final class AppleJwksCacheTest extends TestCase
{
    private string $aud = 'com.example.service';
    private string $iss = 'https://appleid.apple.com';

    /**
     * Build a token header carrying the given kid (only the header is read by the cache decision).
     */
    private function tokenWithKid(string $kid): string
    {
        $b64u = static fn(string $d): string => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
        $header = $b64u((string) json_encode(['alg' => 'RS256', 'kid' => $kid, 'typ' => 'JWT']));
        $payload = $b64u((string) json_encode(['sub' => 'x']));

        return $header . '.' . $payload . '.' . $b64u('sig');
    }

    /**
     * @param list<string> $kids
     * @return array<string, mixed>
     */
    private function jwksWithKids(array $kids): array
    {
        $keys = [];
        foreach ($kids as $kid) {
            $keys[] = ['kty' => 'RSA', 'kid' => $kid, 'alg' => 'RS256', 'n' => 'AQAB', 'e' => 'AQAB'];
        }

        return ['keys' => $keys];
    }

    private function provider(InMemoryCacheStore $cache, array $fetchJwks): AppleTokenTestProvider
    {
        return (new AppleTokenTestProvider())
            ->asConfigured($this->aud, $this->iss)
            ->withFetchJwks($fetchJwks, $cache);
    }

    public function test_first_call_fetches_and_caches_jwks(): void
    {
        $cache = new InMemoryCacheStore();
        $jwks = $this->jwksWithKids(['kid-1']);
        $provider = $this->provider($cache, $jwks);

        $result = $provider->exposedGetAppleJwks($this->tokenWithKid('kid-1'));

        self::assertSame(1, $provider->fetchCount);
        self::assertSame($jwks, $result);
        self::assertTrue($cache->has('entrada.apple.jwks'));
    }

    public function test_second_call_with_known_kid_hits_cache_without_refetch(): void
    {
        $cache = new InMemoryCacheStore();
        $jwks = $this->jwksWithKids(['kid-1']);
        $provider = $this->provider($cache, $jwks);

        $provider->exposedGetAppleJwks($this->tokenWithKid('kid-1'));
        $provider->exposedGetAppleJwks($this->tokenWithKid('kid-1'));

        // Cached JWKS served on the second call: no additional network round trip.
        self::assertSame(1, $provider->fetchCount);
    }

    public function test_unknown_kid_triggers_exactly_one_refetch(): void
    {
        $cache = new InMemoryCacheStore();
        // Cache is pre-warmed with the old key set; the live fetch returns the rotated set.
        $cache->set('entrada.apple.jwks', $this->jwksWithKids(['old-kid']), 3600);
        $rotated = $this->jwksWithKids(['new-kid']);
        $provider = $this->provider($cache, $rotated);

        $result = $provider->exposedGetAppleJwks($this->tokenWithKid('new-kid'));

        // Exactly one refetch on the kid miss, and the fresh set is returned + re-cached.
        self::assertSame(1, $provider->fetchCount);
        self::assertSame($rotated, $result);
        self::assertSame($rotated, $cache->get('entrada.apple.jwks'));
    }

    public function test_no_cache_store_falls_back_to_direct_fetch(): void
    {
        $jwks = $this->jwksWithKids(['kid-1']);
        // No cache injected (null) — degrade gracefully to a direct fetch every time.
        $provider = (new AppleTokenTestProvider())
            ->asConfigured($this->aud, $this->iss)
            ->withFetchJwks($jwks, null);

        $result = $provider->exposedGetAppleJwks($this->tokenWithKid('kid-1'));

        self::assertSame(1, $provider->fetchCount);
        self::assertSame($jwks, $result);
    }
}
