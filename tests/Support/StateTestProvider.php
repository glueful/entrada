<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Tests\Support;

use Glueful\Extensions\Entrada\Providers\AbstractSocialProvider;
use Symfony\Component\HttpFoundation\Request;

/**
 * Test double exposing AbstractSocialProvider's OAuth-state CSRF helpers without the
 * container-backed constructor or a real PHP session. Only $providerName is needed by
 * the helpers under test.
 */
final class StateTestProvider extends AbstractSocialProvider
{
    public function __construct(string $providerName = 'google')
    {
        // Intentionally bypass parent::__construct() (which resolves UserProviderInterface
        // and the database from the container); the state helpers only need $providerName.
        $this->providerName = $providerName;
    }

    /** Operate directly on the $_SESSION array in tests; never start a real session. */
    protected function ensureSession(): void
    {
    }

    public function exposedStore(): string
    {
        return $this->storeOAuthState();
    }

    public function exposedValidate(Request $request): bool
    {
        return $this->validateOAuthState($request);
    }

    public function sessionKey(): string
    {
        return $this->oauthStateSessionKey();
    }

    public function exposedIsVerifiedFlag(mixed $value): bool
    {
        return $this->isVerifiedFlag($value);
    }

    public function exposedPkceChallenge(string $verifier): string
    {
        return $this->pkceChallenge($verifier);
    }

    public function exposedStartPkce(): string
    {
        return $this->startPkce();
    }

    public function exposedConsumePkceVerifier(): ?string
    {
        return $this->consumePkceVerifier();
    }

    public function exposedStartNonce(): string
    {
        return $this->startNonce();
    }

    public function exposedValidateNonce(mixed $tokenNonce): bool
    {
        return $this->validateNonce($tokenNonce);
    }

    protected function isOAuthCallback(Request $request): bool
    {
        return false;
    }

    protected function isOAuthInitRequest(Request $request): bool
    {
        return false;
    }

    protected function handleCallback(Request $request): ?array
    {
        return null;
    }

    protected function initiateOAuthFlow(Request $request): void
    {
    }
}
