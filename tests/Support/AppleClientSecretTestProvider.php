<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Entrada\Providers\AppleAuthProvider;

/**
 * Test double exposing AppleAuthProvider's client-secret generation (and the convertToBinary
 * helper it relies on) without the container-backed constructor or any HTTP. Mirrors
 * FacebookConfigTestProvider: bypass parent::__construct() and drive the real private
 * loadConfig() against an injected ApplicationContext so the production config resolution
 * (and the team_id/key_id/client_id/client_secret precedence) is exercised verbatim.
 */
final class AppleClientSecretTestProvider extends AppleAuthProvider
{
    /**
     * @param array<string, mixed> $appleConfig Values for the sauth.apple config block
     */
    public function __construct(array $appleConfig = [])
    {
        // Intentionally bypass parent::__construct() (which resolves the HTTP client from the
        // container); loadConfig() only needs $this->context to be set.
        $context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $context->mergeConfigDefaults('sauth', ['apple' => $appleConfig]);

        $this->context = $context;
        $this->providerName = self::PROVIDER;

        // Run the real private loadConfig() so the production resolution is tested, not a copy.
        $loadConfig = new \ReflectionMethod(AppleAuthProvider::class, 'loadConfig');
        $loadConfig->invoke($this);
    }

    public function exposedGenerateClientSecret(): string
    {
        $method = new \ReflectionMethod(AppleAuthProvider::class, 'generateClientSecret');
        return $method->invoke($this);
    }

    public function exposedConvertToBinary(string $value, int $length): string
    {
        $method = new \ReflectionMethod(AppleAuthProvider::class, 'convertToBinary');
        return $method->invoke($this, $value, $length);
    }
}
