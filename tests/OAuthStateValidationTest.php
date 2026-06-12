<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Tests;

use Glueful\Extensions\Entrada\Tests\Support\StateTestProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The OAuth `state` parameter must be generated, persisted per-provider, and validated
 * fail-closed on callback (single-use), to prevent login-CSRF / session fixation.
 */
final class OAuthStateValidationTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    /** Build a callback request carrying `state` in the query string (Google/Facebook/GitHub). */
    private function queryCallback(?string $state): Request
    {
        return new Request($state === null ? [] : ['state' => $state]);
    }

    /** Build a callback request carrying `state` in the POST body (Apple form_post). */
    private function postCallback(?string $state): Request
    {
        return new Request([], $state === null ? [] : ['state' => $state]);
    }

    public function test_store_persists_state_under_provider_key_and_returns_it(): void
    {
        $provider = new StateTestProvider('google');
        $state = $provider->exposedStore();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $state);
        self::assertSame('google_oauth_state', $provider->sessionKey());
        self::assertSame($state, $_SESSION['google_oauth_state'] ?? null);
    }

    public function test_validate_accepts_matching_state_from_query(): void
    {
        $provider = new StateTestProvider('google');
        $state = $provider->exposedStore();

        self::assertTrue($provider->exposedValidate($this->queryCallback($state)));
    }

    public function test_validate_accepts_matching_state_from_post_body(): void
    {
        $provider = new StateTestProvider('apple');
        $state = $provider->exposedStore();

        self::assertTrue($provider->exposedValidate($this->postCallback($state)));
    }

    public function test_validate_rejects_mismatched_state(): void
    {
        $provider = new StateTestProvider('google');
        $provider->exposedStore();

        self::assertFalse($provider->exposedValidate($this->queryCallback('not-the-stored-state')));
    }

    public function test_validate_rejects_missing_state_in_request(): void
    {
        $provider = new StateTestProvider('google');
        $provider->exposedStore();

        self::assertFalse($provider->exposedValidate($this->queryCallback(null)));
    }

    public function test_validate_fails_closed_when_no_state_stored(): void
    {
        $provider = new StateTestProvider('google');
        // No exposedStore() — nothing in the session.

        self::assertFalse($provider->exposedValidate($this->queryCallback('anything')));
    }

    public function test_state_is_single_use(): void
    {
        $provider = new StateTestProvider('google');
        $state = $provider->exposedStore();

        self::assertTrue($provider->exposedValidate($this->queryCallback($state)));
        // The stored value is consumed on first validation; replay must fail.
        self::assertFalse($provider->exposedValidate($this->queryCallback($state)));
    }

    public function test_state_key_is_namespaced_per_provider(): void
    {
        $google = new StateTestProvider('google');
        $facebook = new StateTestProvider('facebook');

        $googleState = $google->exposedStore();

        self::assertSame('facebook_oauth_state', $facebook->sessionKey());
        // Facebook must not accept a state minted for Google.
        self::assertFalse($facebook->exposedValidate($this->queryCallback($googleState)));
    }
}
