<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Tests\Support;

use Glueful\Extensions\Entrada\Providers\AbstractSocialProvider;
use Symfony\Component\HttpFoundation\Request;

/**
 * Test double exposing AbstractSocialProvider::filterProfileData() (private) without the
 * container-backed constructor or a real DB. The full linkSocialAccount() write needs a database,
 * so the allowlist behaviour is pinned by exercising the helper directly.
 */
final class ProfileDataFilterTestProvider extends AbstractSocialProvider
{
    public function __construct(string $providerName = 'apple')
    {
        // Intentionally bypass parent::__construct() (which resolves the container/DB);
        // filterProfileData() needs neither.
        $this->providerName = $providerName;
    }

    /**
     * @param array<string, mixed> $userData
     * @return array<string, mixed>
     */
    public function exposedFilterProfileData(array $userData): array
    {
        $reflection = new \ReflectionMethod(AbstractSocialProvider::class, 'filterProfileData');
        $reflection->setAccessible(true);

        /** @var array<string, mixed> $result */
        $result = $reflection->invoke($this, $userData);

        return $result;
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
