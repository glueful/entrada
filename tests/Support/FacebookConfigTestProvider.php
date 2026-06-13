<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Entrada\Providers\FacebookAuthProvider;

/**
 * Test double exposing FacebookAuthProvider's config resolution and the appsecret_proof helper
 * without the container-backed constructor or any HTTP. Mirrors StateTestProvider's approach:
 * bypass parent::__construct() and drive the real private loadConfig() against an injected
 * ApplicationContext so the production precedence (config -> env -> default) is exercised verbatim.
 *
 * @param array<string, mixed> $facebookConfig
 */
final class FacebookConfigTestProvider extends FacebookAuthProvider
{
    /**
     * @param array<string, mixed> $facebookConfig Values for the sauth.facebook config block
     */
    public function __construct(array $facebookConfig = [])
    {
        // Intentionally bypass parent::__construct() (which resolves the HTTP client from the
        // container and starts no session); loadConfig() only needs $this->context.
        $context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $context->mergeConfigDefaults('sauth', ['facebook' => $facebookConfig]);

        $this->context = $context;
        $this->providerName = self::PROVIDER;

        // Run the real private loadConfig() so we test production resolution, not a copy of it.
        $loadConfig = new \ReflectionMethod(FacebookAuthProvider::class, 'loadConfig');
        $loadConfig->invoke($this);
    }

    public function resolvedApiVersion(): string
    {
        return $this->readPrivate('apiVersion');
    }

    public function resolvedAppSecret(): string
    {
        return $this->readPrivate('appSecret');
    }

    public function exposedAppSecretProof(string $accessToken): string
    {
        $method = new \ReflectionMethod(FacebookAuthProvider::class, 'appSecretProof');
        return $method->invoke($this, $accessToken);
    }

    private function readPrivate(string $name): string
    {
        $property = new \ReflectionProperty(FacebookAuthProvider::class, $name);
        return $property->getValue($this);
    }
}
