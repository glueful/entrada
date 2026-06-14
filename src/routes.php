<?php

declare(strict_types=1);

use Glueful\Routing\Router;
use Glueful\Extensions\Entrada\Controllers\SocialAuthController;
use Glueful\Extensions\Entrada\Controllers\SocialAccountController;

/** @var Router $router Router instance injected by RouteManifest::load() */
/*
 * Social Login Routes
 *
 * This file defines routes for social authentication:
 * - Provider initialization endpoints
 * - OAuth callback handlers
 * - Account management endpoints
 */

// Social Login Initialization Routes
$router->group(['prefix' => '/auth/social'], function (Router $router) {
    // Google
    $router->get('/google', [SocialAuthController::class, 'googleInit'])
        ->rateLimit(20, 1)            // 20 requests / 60s, per-IP (default)
        ->middleware(['rate_limit']); // attach the limiter so it reads the config above

    $router->post('/google', [SocialAuthController::class, 'googleNative'])
        ->rateLimit(10, 1)            // 10 requests / 60s, per-IP — token-grinding surface
        ->middleware(['rate_limit']);

    $router->get('/google/callback', [SocialAuthController::class, 'googleCallback'])
        ->rateLimit(20, 1)            // 20 requests / 60s, per-IP
        ->middleware(['rate_limit']);

    // Facebook
    $router->get('/facebook', [SocialAuthController::class, 'facebookInit'])
        ->rateLimit(20, 1)            // 20 requests / 60s, per-IP (default)
        ->middleware(['rate_limit']);

    $router->post('/facebook', [SocialAuthController::class, 'facebookNative'])
        ->rateLimit(10, 1)            // 10 requests / 60s, per-IP — token-grinding surface
        ->middleware(['rate_limit']);

    $router->get('/facebook/callback', [SocialAuthController::class, 'facebookCallback'])
        ->rateLimit(20, 1)            // 20 requests / 60s, per-IP
        ->middleware(['rate_limit']);

    // GitHub
    $router->get('/github', [SocialAuthController::class, 'githubInit'])
        ->rateLimit(20, 1)            // 20 requests / 60s, per-IP (default)
        ->middleware(['rate_limit']);

    $router->post('/github', [SocialAuthController::class, 'githubNative'])
        ->rateLimit(10, 1)            // 10 requests / 60s, per-IP — token-grinding surface
        ->middleware(['rate_limit']);

    $router->get('/github/callback', [SocialAuthController::class, 'githubCallback'])
        ->rateLimit(20, 1)            // 20 requests / 60s, per-IP
        ->middleware(['rate_limit']);

    // Apple
    $router->get('/apple', [SocialAuthController::class, 'appleInit'])
        ->rateLimit(20, 1)            // 20 requests / 60s, per-IP (default)
        ->middleware(['rate_limit']);

    $router->post('/apple', [SocialAuthController::class, 'appleNative'])
        ->rateLimit(10, 1)            // 10 requests / 60s, per-IP — token-grinding surface
        ->middleware(['rate_limit']);

    $router->post('/apple/callback', [SocialAuthController::class, 'appleCallback'])
        ->rateLimit(20, 1)            // 20 requests / 60s, per-IP — OAuth callback
        ->middleware(['rate_limit']);
});

// User social accounts management (requires authentication)
$router->group(['prefix' => '/user/social-accounts', 'middleware' => ['auth']], function (Router $router) {
    $router->get('/', [SocialAccountController::class, 'index']);

    $router->delete('/{uuid}', [SocialAccountController::class, 'destroy'])
        ->rateLimit(10, 1)            // 10 requests / 60s, per-IP ('auth' already applied by the group)
        ->middleware(['rate_limit']); // builder sets the limit; middleware enforces it (the old
                                      // 'rate_limit:10,60' string form was a NO-OP — params ignored)
});
