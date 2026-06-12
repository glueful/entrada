<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Tests\Support;

use Glueful\Extensions\Entrada\Providers\AbstractSocialProvider;
use Symfony\Component\HttpFoundation\Request;

/**
 * Test double exposing AbstractSocialProvider::formatUserData() without the container-backed
 * constructor or a real DB. getSauthConfig() is stubbed with the default storage mapping so the
 * column-name resolution (storage.users.columns.email_verified_at) runs exactly as in production,
 * but without resolving config() from a container.
 */
final class SocialFormatTestProvider extends AbstractSocialProvider
{
    /** @var array<string, mixed> Storage column overrides merged over the defaults. */
    private array $columnOverrides;

    /**
     * @param array<string, string> $columnOverrides
     */
    public function __construct(string $providerName = 'apple', array $columnOverrides = [])
    {
        // Intentionally bypass parent::__construct() (which resolves UserProviderInterface and the
        // database from the container); formatUserData() only needs $providerName and the config.
        $this->providerName = $providerName;
        $this->columnOverrides = $columnOverrides;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getSauthConfig(): array
    {
        return [
            'storage' => [
                'users' => [
                    'table' => 'users',
                    'columns' => array_merge([
                        'uuid' => 'uuid',
                        'username' => 'username',
                        'email' => 'email',
                        'email_verified_at' => 'email_verified_at',
                    ], $this->columnOverrides),
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function exposedFormatUserData(array $user): array
    {
        return $this->formatUserData($user);
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
