<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Tests;

use Glueful\Extensions\Entrada\Tests\Support\StateTestProvider;
use PHPUnit\Framework\TestCase;

/**
 * PKCE (RFC 7636) and OIDC nonce helpers: the code_challenge must be the S256 transform of the
 * verifier, the verifier/nonce are stored per-provider and single-use, and nonce validation is
 * fail-closed.
 */
final class PkceNonceTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function test_pkce_challenge_matches_rfc7636_test_vector(): void
    {
        // RFC 7636, Appendix B.
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $expectedChallenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

        self::assertSame($expectedChallenge, (new StateTestProvider())->exposedPkceChallenge($verifier));
    }

    public function test_start_pkce_stores_verifier_and_returns_its_challenge(): void
    {
        $provider = new StateTestProvider('google');

        $challenge = $provider->exposedStartPkce();
        $verifier = $_SESSION['google_pkce_verifier'] ?? null;

        self::assertIsString($verifier);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9\-_]{43,128}$/', $verifier);
        self::assertSame($provider->exposedPkceChallenge($verifier), $challenge);
    }

    public function test_consume_pkce_verifier_is_single_use(): void
    {
        $provider = new StateTestProvider('google');
        $provider->exposedStartPkce();

        $first = $provider->exposedConsumePkceVerifier();
        self::assertIsString($first);
        // Consumed — a second read returns null.
        self::assertNull($provider->exposedConsumePkceVerifier());
    }

    public function test_nonce_validation_accepts_match_and_is_single_use(): void
    {
        $provider = new StateTestProvider('apple');
        $nonce = $provider->exposedStartNonce();

        self::assertTrue($provider->exposedValidateNonce($nonce));
        // Single-use: a replay of the same nonce fails.
        self::assertFalse($provider->exposedValidateNonce($nonce));
    }

    public function test_nonce_validation_rejects_mismatch_and_missing(): void
    {
        $provider = new StateTestProvider('apple');
        $provider->exposedStartNonce();

        self::assertFalse($provider->exposedValidateNonce('not-the-nonce'));

        $fresh = new StateTestProvider('apple');
        // No stored nonce -> fail closed.
        self::assertFalse($fresh->exposedValidateNonce('anything'));
    }
}
