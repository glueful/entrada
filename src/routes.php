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
    /**
     * @route GET /auth/social/google
     * @summary Google OAuth Authentication
     * @description Initiates the OAuth flow with Google for user authentication
     * @tag Social Authentication
     * @response 302 "Redirects to Google's OAuth authorization page"
     */
    $router->get('/google', [SocialAuthController::class, 'googleInit']);

    /**
     * @route POST /auth/social/google
     * @summary Google Native Authentication
     * @description Authenticates a user with a Google ID token from a native mobile app
     * @tag Social Authentication
     * @requestBody id_token:string="ID token obtained from Google Sign-In SDK" {required=id_token}
     * @response 200 application/json "Successfully authenticated with Google" {
     *   user:object="User profile information",
     *   tokens:{
     *     access_token:string="JWT access token",
     *     refresh_token:string="JWT refresh token",
     *     expires_in:integer="Token expiration time in seconds"
     *   }
     * }
     * @response 400 "Missing ID token"
     * @response 401 "Failed to verify Google ID token"
     * @response 500 "Server error during authentication"
     */
    $router->post('/google', [SocialAuthController::class, 'googleNative']);

    /**
     * @route GET /auth/social/google/callback
     * @summary Google OAuth Callback
     * @description Callback endpoint that processes the OAuth response from Google
     * @tag Social Authentication
     * @param code query string true "Authorization code from Google"
     * @param state query string true "State token for CSRF protection"
     * @response 200 application/json "Successfully authenticated with Google" {
     *   access_token:string="JWT access token",
     *   refresh_token:string="JWT refresh token",
     *   expires_in:integer="Token expiration time in seconds",
     *   user:object="User profile information"
     * }
     * @response 400 "Bad request or invalid parameters"
     * @response 401 "Authentication failed"
     */
    $router->get('/google/callback', [SocialAuthController::class, 'googleCallback']);

    /**
     * @route GET /auth/social/facebook
     * @summary Facebook OAuth Authentication
     * @description Initiates the OAuth flow with Facebook for user authentication
     * @tag Social Authentication
     * @response 302 "Redirects to Facebook's OAuth authorization page"
     */
    $router->get('/facebook', [SocialAuthController::class, 'facebookInit']);

    /**
     * @route POST /auth/social/facebook
     * @summary Facebook Native Authentication
     * @description Authenticates a user with a Facebook access token from a native mobile app
     * @tag Social Authentication
     * @requestBody access_token:string="Access token obtained from Facebook Login SDK" {required=access_token}
     * @response 200 application/json "Successfully authenticated with Facebook" {
     *   user:object="User profile information",
     *   tokens:{
     *     access_token:string="JWT access token",
     *     refresh_token:string="JWT refresh token",
     *     expires_in:integer="Token expiration time in seconds"
     *   }
     * }
     * @response 400 "Missing access token"
     * @response 401 "Failed to verify Facebook access token"
     * @response 500 "Server error during authentication"
     */
    $router->post('/facebook', [SocialAuthController::class, 'facebookNative']);

    /**
     * @route GET /auth/social/facebook/callback
     * @summary Facebook OAuth Callback
     * @description Callback endpoint that processes the OAuth response from Facebook
     * @tag Social Authentication
     * @param code query string true "Authorization code from Facebook"
     * @param state query string true "State token for CSRF protection"
     * @response 200 application/json "Successfully authenticated with Facebook" {
     *   access_token:string="JWT access token",
     *   refresh_token:string="JWT refresh token",
     *   expires_in:integer="Token expiration time in seconds",
     *   user:object="User profile information"
     * }
     * @response 400 "Bad request or invalid parameters"
     * @response 401 "Authentication failed"
     */
    $router->get('/facebook/callback', [SocialAuthController::class, 'facebookCallback']);

    /**
     * @route GET /auth/social/github
     * @summary GitHub OAuth Authentication
     * @description Initiates the OAuth flow with GitHub for user authentication
     * @tag Social Authentication
     * @response 302 "Redirects to GitHub's OAuth authorization page"
     */
    $router->get('/github', [SocialAuthController::class, 'githubInit']);

    /**
     * @route POST /auth/social/github
     * @summary GitHub Native Authentication
     * @description Authenticates a user with a GitHub access token from a native mobile app
     * @tag Social Authentication
     * @requestBody access_token:string="Access token obtained from GitHub OAuth" {required=access_token}
     * @response 200 application/json "Successfully authenticated with GitHub" {
     *   user:object="User profile information",
     *   tokens:{
     *     access_token:string="JWT access token",
     *     refresh_token:string="JWT refresh token",
     *     expires_in:integer="Token expiration time in seconds"
     *   }
     * }
     * @response 400 "Missing access token"
     * @response 401 "Failed to verify GitHub access token"
     * @response 500 "Server error during authentication"
     */
    $router->post('/github', [SocialAuthController::class, 'githubNative']);

    /**
     * @route GET /auth/social/github/callback
     * @summary GitHub OAuth Callback
     * @description Callback endpoint that processes the OAuth response from GitHub
     * @tag Social Authentication
     * @param code query string true "Authorization code from GitHub"
     * @param state query string true "State token for CSRF protection"
     * @response 200 application/json "Successfully authenticated with GitHub" {
     *   access_token:string="JWT access token",
     *   refresh_token:string="JWT refresh token",
     *   expires_in:integer="Token expiration time in seconds",
     *   user:object="User profile information"
     * }
     * @response 400 "Bad request or invalid parameters"
     * @response 401 "Authentication failed"
     */
    $router->get('/github/callback', [SocialAuthController::class, 'githubCallback']);

    /**
     * @route GET /auth/social/apple
     * @summary Apple OAuth Authentication
     * @description Initiates the OAuth flow with Apple for user authentication
     * @tag Social Authentication
     * @response 302 "Redirects to Apple's OAuth authorization page"
     */
    $router->get('/apple', [SocialAuthController::class, 'appleInit']);

    /**
     * @route POST /auth/social/apple
     * @summary Apple Native Authentication
     * @description Authenticates a user with an Apple ID token from a native mobile app
     * @tag Social Authentication
     * @requestBody id_token:string="ID token obtained from Sign in with Apple SDK" {required=id_token}
     * @response 200 application/json "Successfully authenticated with Apple" {
     *   user:object="User profile information",
     *   tokens:{
     *     access_token:string="JWT access token",
     *     refresh_token:string="JWT refresh token",
     *     expires_in:integer="Token expiration time in seconds"
     *   }
     * }
     * @response 400 "Missing ID token"
     * @response 401 "Failed to verify Apple ID token"
     * @response 500 "Server error during authentication"
     */
    $router->post('/apple', [SocialAuthController::class, 'appleNative']);

    /**
     * @route POST /auth/social/apple/callback
     * @summary Apple OAuth Callback
     * @description Callback endpoint that processes the OAuth response from Apple
     * @tag Social Authentication
     * @requestBody code:string="Authorization code from Apple"
     * @requestBody state:string="State token for CSRF protection"
     * @requestBody user:string="JSON string containing user information (only provided on first login)"
     * {required=code,state}
     * @response 200 application/json "Successfully authenticated with Apple" {
     *   access_token:string="JWT access token",
     *   refresh_token:string="JWT refresh token",
     *   expires_in:integer="Token expiration time in seconds",
     *   user:object="User profile information"
     * }
     * @response 400 "Bad request or invalid parameters"
     * @response 401 "Authentication failed"
     */
    $router->post('/apple/callback', [SocialAuthController::class, 'appleCallback']);
});

// User social accounts management (requires authentication)
$router->group(['prefix' => '/user/social-accounts', 'middleware' => ['auth']], function (Router $router) {
    /**
     * @route GET /user/social-accounts
     * @summary Get Connected Social Accounts
     * @description Retrieve all social accounts connected to the authenticated user
     * @tag Social Account Management
     * @requiresAuth true
     * @response 200 application/json "Successfully retrieved social accounts" {
     *   status:string="success",
     *   message:string="Social accounts retrieved successfully",
     *   data:[{
     *     uuid:string="Unique identifier for the social account",
     *     provider:string="Social provider name (google, facebook, github, etc.)",
     *     created_at:string="When the account was connected",
     *     updated_at:string="When the account was last updated"
     *   }]
     * }
     * @response 401 "Unauthorized - User is not authenticated"
     * @response 500 "Server error retrieving social accounts"
     */
    $router->get('/', [SocialAccountController::class, 'index']);

    /**
     * @route DELETE /user/social-accounts/{uuid}
     * @summary Unlink Social Account
     * @description Remove a social provider connection from the authenticated user
     * @tag Social Account Management
     * @requiresAuth true
     * @param uuid path string true "UUID of the social account to unlink"
     * @response 200 application/json "Successfully unlinked social account" {
     *   status:string="success",
     *   message:string="Social account unlinked successfully"
     * }
     * @response 401 "Unauthorized - User is not authenticated"
     * @response 404 "Social account not found or not owned by user"
     * @response 500 "Server error unlinking social account"
     */
    $router->delete('/{uuid}', [SocialAccountController::class, 'destroy'])
        ->middleware(['auth', 'rate_limit:10,60']);
});
