<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Tests;

use Glueful\Routing\Route;
use Glueful\Routing\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Pins rate limiting onto every public (unauthenticated) social-auth route.
 *
 * Why this matters: the social-auth endpoints are unauthenticated and include
 * token-grinding surfaces (the native POST endpoints). The framework's
 * EnhancedRateLimiterMiddleware reads its per-route limit ONLY from
 * Route::getRateLimitConfig() — which is populated by the ->rateLimit() builder.
 * The 'rate_limit:N,W' middleware-STRING form is parsed but its params are
 * ignored by the limiter, so it does NOT enforce a route-specific limit.
 *
 * This test therefore asserts BOTH facts for each route:
 *   1. the 'rate_limit' middleware is attached (so the limiter runs at all), and
 *   2. getRateLimitConfig() carries the expected attempts/window (so it enforces).
 *
 * The routes file only performs lazy route registration, so we can load it
 * against a real Router backed by a minimal container (no framework boot).
 */
final class PublicRouteRateLimitTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        // Minimal container: has()===false for everything, so the Router skips
        // route caching and never resolves a service during registration.
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("not expected to resolve: {$id}");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        $this->router = new Router($container);

        $routesFile = \dirname(__DIR__) . '/src/routes.php';
        (function (Router $router) use ($routesFile): void {
            require $routesFile;
        })($this->router);
    }

    /**
     * @return array<string, array{0:string,1:string,2:int}>
     */
    public static function publicRouteProvider(): array
    {
        // method, path, expected attempts (window is always 60s)
        return [
            // GET init redirects — 20/60
            'GET google init'       => ['GET',  '/auth/social/google', 20],
            'GET facebook init'     => ['GET',  '/auth/social/facebook', 20],
            'GET github init'       => ['GET',  '/auth/social/github', 20],
            'GET apple init'        => ['GET',  '/auth/social/apple', 20],
            // POST native token endpoints — 10/60 (token-grinding surface)
            'POST google native'    => ['POST', '/auth/social/google', 10],
            'POST facebook native'  => ['POST', '/auth/social/facebook', 10],
            'POST github native'    => ['POST', '/auth/social/github', 10],
            'POST apple native'     => ['POST', '/auth/social/apple', 10],
            // Callbacks — 20/60
            'GET google callback'   => ['GET',  '/auth/social/google/callback', 20],
            'GET facebook callback' => ['GET',  '/auth/social/facebook/callback', 20],
            'GET github callback'   => ['GET',  '/auth/social/github/callback', 20],
            'POST apple callback'   => ['POST', '/auth/social/apple/callback', 20],
        ];
    }

    /**
     * @dataProvider publicRouteProvider
     */
    public function test_public_route_enforces_rate_limit(string $method, string $path, int $attempts): void
    {
        $route = $this->findRoute($method, $path);
        self::assertNotNull($route, "route {$method} {$path} not registered");

        // 1. middleware must be attached so the limiter actually runs
        self::assertContains(
            'rate_limit',
            $route->getMiddleware(),
            "route {$method} {$path} must attach the 'rate_limit' middleware"
        );

        // 2. builder config must carry the route-specific limit so it enforces
        $config = $route->getRateLimitConfig();
        self::assertNotEmpty(
            $config,
            "route {$method} {$path} must set ->rateLimit() — the middleware string form is a no-op"
        );

        $first = $config[0];
        self::assertSame($attempts, $first['attempts'] ?? null, "wrong attempts for {$method} {$path}");
        self::assertSame(60, $first['decaySeconds'] ?? null, "expected a 60s window for {$method} {$path}");
        // Per-IP is the correct dimension for unauthenticated routes.
        self::assertSame('ip', $first['by'] ?? null, "expected per-IP limiting for {$method} {$path}");
    }

    private function findRoute(string $method, string $path): ?Route
    {
        // Static routes keyed as "METHOD:path".
        $static = $this->router->getStaticRoutes();
        if (isset($static["{$method}:{$path}"])) {
            return $static["{$method}:{$path}"];
        }

        // Dynamic routes keyed by method.
        $dynamic = $this->router->getDynamicRoutes();
        foreach ($dynamic[$method] ?? [] as $route) {
            if ($route->getPath() === $path) {
                return $route;
            }
        }

        return null;
    }
}
