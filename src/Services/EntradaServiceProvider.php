<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Services;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use Glueful\Extensions\Entrada\Providers\GoogleAuthProvider;
use Glueful\Extensions\Entrada\Providers\FacebookAuthProvider;
use Glueful\Extensions\Entrada\Providers\GithubAuthProvider;
use Glueful\Extensions\Entrada\Providers\AppleAuthProvider;
use Glueful\Auth\AuthBootstrap;

class EntradaServiceProvider extends ServiceProvider
{
    private static ?string $cachedVersion = null;

    /**
     * Read the extension version from composer.json (cached)
     */
    public static function composerVersion(): string
    {
        if (self::$cachedVersion === null) {
            $path = __DIR__ . '/../../composer.json';
            $composer = json_decode(file_get_contents($path), true);
            self::$cachedVersion = $composer['version'] ?? '0.0.0';
        }

        return self::$cachedVersion;
    }

    public static function services(): array
    {
        return [
            GoogleAuthProvider::class => ['class' => GoogleAuthProvider::class, 'shared' => true, 'autowire' => true],
            FacebookAuthProvider::class => ['class' => FacebookAuthProvider::class, 'shared' => true, 'autowire' => true],
            GithubAuthProvider::class => ['class' => GithubAuthProvider::class, 'shared' => true, 'autowire' => true],
            AppleAuthProvider::class => ['class' => AppleAuthProvider::class, 'shared' => true, 'autowire' => true],
        ];
    }

    public function register(ApplicationContext $context): void
    {
        // Merge default configuration under the 'sauth' key
        $this->mergeConfig('sauth', require __DIR__ . '/../../config/sauth.php');
    }

    public function boot(ApplicationContext $context): void
    {
        // Register social auth providers with the core Auth manager
        try {
            $authManager = app($context, AuthBootstrap::class)->getManager();
            $config = config($context, 'sauth', []);
            $enabled = $config['enabled_providers'] ?? ['google', 'facebook', 'github', 'apple'];

            $map = [
                'google' => GoogleAuthProvider::class,
                'facebook' => FacebookAuthProvider::class,
                'github' => GithubAuthProvider::class,
                'apple' => AppleAuthProvider::class,
            ];

            foreach ($enabled as $name) {
                if (!isset($map[$name])) {
                    continue;
                }
                try {
                    $provider = $this->app->get($map[$name]);
                    $authManager->registerProvider($name, $provider);
                } catch (\Throwable $e) {
                    error_log("Entrada: Failed to register {$name} provider: " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            // AuthBootstrap not available or another initialization issue
            error_log('Entrada: Auth initialization error: ' . $e->getMessage());
        }

        // Load routes and migrations
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        $this->loadMigrationsFrom(dirname(__DIR__, 2) . '/migrations');

        // Register extension metadata for CLI and diagnostics
        try {
            $this->app->get(\Glueful\Extensions\ExtensionManager::class)->registerMeta(self::class, [
                'slug' => 'entrada',
                'name' => 'Entrada',
                'version' => self::composerVersion(),
                'description' => 'Social Login & SSO for Glueful (OAuth/OIDC)',
            ]);
        } catch (\Throwable $e) {
            error_log('[Entrada] Failed to register extension metadata: ' . $e->getMessage());
        }
    }
}
