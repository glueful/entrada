<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Services;

use Glueful\Extensions\ServiceProvider;
use Glueful\Extensions\Entrada\Providers\GoogleAuthProvider;
use Glueful\Extensions\Entrada\Providers\FacebookAuthProvider;
use Glueful\Extensions\Entrada\Providers\GithubAuthProvider;
use Glueful\Extensions\Entrada\Providers\AppleAuthProvider;
use Glueful\Auth\AuthBootstrap;

class EntradaServiceProvider extends ServiceProvider
{
    public static function services(): array
    {
        return [
            GoogleAuthProvider::class => ['class' => GoogleAuthProvider::class, 'shared' => true],
            FacebookAuthProvider::class => ['class' => FacebookAuthProvider::class, 'shared' => true],
            GithubAuthProvider::class => ['class' => GithubAuthProvider::class, 'shared' => true],
            AppleAuthProvider::class => ['class' => AppleAuthProvider::class, 'shared' => true],
        ];
    }

    public function register(): void
    {
        // Merge default configuration under the 'sauth' key
        $this->mergeConfig('sauth', require __DIR__ . '/../../config/sauth.php');
    }

    public function boot(): void
    {
        // Register social auth providers with the core Auth manager
        try {
            $authManager = AuthBootstrap::getManager();
            $config = config('sauth', []);
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
                'version' => '1.0.2',
                'description' => 'Social Login & SSO for Glueful (OAuth/OIDC)',
            ]);
        } catch (\Throwable $e) {
            error_log('[Entrada] Failed to register extension metadata: ' . $e->getMessage());
        }
    }
}
