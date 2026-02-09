<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Controllers;

use Glueful\Http\Response;
use Glueful\Extensions\Entrada\Providers\GoogleAuthProvider;
use Glueful\Extensions\Entrada\Providers\FacebookAuthProvider;
use Glueful\Extensions\Entrada\Providers\GithubAuthProvider;
use Glueful\Extensions\Entrada\Providers\AppleAuthProvider;
use Symfony\Component\HttpFoundation\Request;

/**
 * Social Authentication Controller
 *
 * Handles OAuth authentication flows for social providers:
 * - Google, Facebook, GitHub, Apple
 * - Web-based OAuth redirects
 * - Native mobile token verification
 * - OAuth callbacks
 */
class SocialAuthController
{
    private GoogleAuthProvider $googleProvider;
    private FacebookAuthProvider $facebookProvider;
    private GithubAuthProvider $githubProvider;
    private AppleAuthProvider $appleProvider;

    public function __construct(
        GoogleAuthProvider $googleProvider,
        FacebookAuthProvider $facebookProvider,
        GithubAuthProvider $githubProvider,
        AppleAuthProvider $appleProvider
    ) {
        $this->googleProvider = $googleProvider;
        $this->facebookProvider = $facebookProvider;
        $this->githubProvider = $githubProvider;
        $this->appleProvider = $appleProvider;
    }

    // =========================================================================
    // Google Authentication
    // =========================================================================

    /**
     * Initiate Google OAuth flow
     *
     * @route GET /auth/social/google
     */
    public function googleInit(Request $request): Response
    {
        try {
            // The authenticate method handles web-based OAuth flow with redirects
            $this->googleProvider->authenticate($request);

            // If we reach this point, something went wrong as we should have been redirected
            $error = $this->googleProvider->getError() ?: "Failed to initiate Google authentication";
            return Response::serverError($error);
        } catch (\Exception $e) {
            error_log("Google authentication error: " . $e->getMessage());
            return Response::serverError("Failed to initialize Google authentication");
        }
    }

    /**
     * Google native token authentication
     *
     * @route POST /auth/social/google
     */
    public function googleNative(Request $request): Response
    {
        try {
            $requestData = json_decode($request->getContent(), true);
            $idToken = $requestData['id_token'] ?? null;

            if (empty($idToken)) {
                return Response::validation(['id_token' => ['ID token is required']], 'Validation failed');
            }

            $userData = $this->googleProvider->verifyNativeToken($idToken);

            if (!$userData) {
                $error = $this->googleProvider->getError() ?: 'Failed to verify Google ID token';
                return Response::unauthorized($error);
            }

            $tokens = $this->googleProvider->generateTokens($userData);

            return Response::success([
                'user' => $userData,
                'tokens' => $tokens
            ], 'Successfully authenticated with Google');
        } catch (\Exception $e) {
            error_log('Google token verification error: ' . $e->getMessage());
            return Response::serverError('Failed to authenticate with Google');
        }
    }

    /**
     * Google OAuth callback
     *
     * @route GET /auth/social/google/callback
     */
    public function googleCallback(Request $request): Response
    {
        try {
            $userData = $this->googleProvider->authenticate($request);

            if (!$userData) {
                $error = $this->googleProvider->getError() ?: "Failed to authenticate with Google";
                return Response::unauthorized($error);
            }

            $tokens = $this->googleProvider->generateTokens($userData);

            return Response::success([
                'user' => $userData,
                'tokens' => $tokens
            ], "Successfully authenticated with Google");
        } catch (\Exception $e) {
            error_log("Google callback error: " . $e->getMessage());
            return Response::serverError("Failed to process Google authentication");
        }
    }

    // =========================================================================
    // Facebook Authentication
    // =========================================================================

    /**
     * Initiate Facebook OAuth flow
     *
     * @route GET /auth/social/facebook
     */
    public function facebookInit(Request $request): Response
    {
        try {
            // The authenticate method handles web-based OAuth flow with redirects
            $this->facebookProvider->authenticate($request);

            // If we reach this point, something went wrong as we should have been redirected
            $error = $this->facebookProvider->getError() ?: "Failed to initiate Facebook authentication";
            return Response::serverError($error);
        } catch (\Exception $e) {
            error_log("Facebook authentication error: " . $e->getMessage());
            return Response::serverError("Failed to initialize Facebook authentication");
        }
    }

    /**
     * Facebook native token authentication
     *
     * @route POST /auth/social/facebook
     */
    public function facebookNative(Request $request): Response
    {
        try {
            $requestData = json_decode($request->getContent(), true);
            $accessToken = $requestData['access_token'] ?? null;

            if (empty($accessToken)) {
                return Response::validation(['access_token' => ['Access token is required']], 'Validation failed');
            }

            $userData = $this->facebookProvider->verifyNativeToken($accessToken);

            if (!$userData) {
                $error = $this->facebookProvider->getError() ?: 'Failed to verify Facebook access token';
                return Response::unauthorized($error);
            }

            $tokens = $this->facebookProvider->generateTokens($userData);

            return Response::success([
                'user' => $userData,
                'tokens' => $tokens
            ], 'Successfully authenticated with Facebook');
        } catch (\Exception $e) {
            error_log('Facebook token verification error: ' . $e->getMessage());
            return Response::serverError('Failed to authenticate with Facebook');
        }
    }

    /**
     * Facebook OAuth callback
     *
     * @route GET /auth/social/facebook/callback
     */
    public function facebookCallback(Request $request): Response
    {
        try {
            $userData = $this->facebookProvider->authenticate($request);

            if (!$userData) {
                $error = $this->facebookProvider->getError() ?: "Failed to authenticate with Facebook";
                return Response::unauthorized($error);
            }

            $tokens = $this->facebookProvider->generateTokens($userData);

            return Response::success([
                'user' => $userData,
                'tokens' => $tokens
            ], "Successfully authenticated with Facebook");
        } catch (\Exception $e) {
            error_log("Facebook callback error: " . $e->getMessage());
            return Response::serverError("Failed to process Facebook authentication");
        }
    }

    // =========================================================================
    // GitHub Authentication
    // =========================================================================

    /**
     * Initiate GitHub OAuth flow
     *
     * @route GET /auth/social/github
     */
    public function githubInit(Request $request): Response
    {
        try {
            // The authenticate method handles web-based OAuth flow with redirects
            $this->githubProvider->authenticate($request);

            // If we reach this point, something went wrong as we should have been redirected
            $error = $this->githubProvider->getError() ?: "Failed to initiate GitHub authentication";
            return Response::serverError($error);
        } catch (\Exception $e) {
            error_log("GitHub authentication error: " . $e->getMessage());
            return Response::serverError("Failed to initialize GitHub authentication");
        }
    }

    /**
     * GitHub native token authentication
     *
     * @route POST /auth/social/github
     */
    public function githubNative(Request $request): Response
    {
        try {
            $requestData = json_decode($request->getContent(), true);
            $accessToken = $requestData['access_token'] ?? null;

            if (empty($accessToken)) {
                return Response::validation(['access_token' => ['Access token is required']], 'Validation failed');
            }

            $userData = $this->githubProvider->verifyNativeToken($accessToken);

            if (!$userData) {
                $error = $this->githubProvider->getError() ?: 'Failed to verify GitHub access token';
                return Response::unauthorized($error);
            }

            $tokens = $this->githubProvider->generateTokens($userData);

            return Response::success([
                'user' => $userData,
                'tokens' => $tokens
            ], 'Successfully authenticated with GitHub');
        } catch (\Exception $e) {
            error_log('GitHub token verification error: ' . $e->getMessage());
            return Response::serverError('Failed to authenticate with GitHub');
        }
    }

    /**
     * GitHub OAuth callback
     *
     * @route GET /auth/social/github/callback
     */
    public function githubCallback(Request $request): Response
    {
        try {
            $userData = $this->githubProvider->authenticate($request);

            if (!$userData) {
                $error = $this->githubProvider->getError() ?: "Failed to authenticate with GitHub";
                return Response::unauthorized($error);
            }

            $tokens = $this->githubProvider->generateTokens($userData);

            return Response::success([
                'user' => $userData,
                'tokens' => $tokens
            ], "Successfully authenticated with GitHub");
        } catch (\Exception $e) {
            error_log("GitHub callback error: " . $e->getMessage());
            return Response::serverError("Failed to process GitHub authentication");
        }
    }

    // =========================================================================
    // Apple Authentication
    // =========================================================================

    /**
     * Initiate Apple OAuth flow
     *
     * @route GET /auth/social/apple
     */
    public function appleInit(Request $request): Response
    {
        try {
            // The authenticate method handles web-based OAuth flow with redirects
            $this->appleProvider->authenticate($request);

            // If we reach this point, something went wrong as we should have been redirected
            $error = $this->appleProvider->getError() ?: "Failed to initiate Apple authentication";
            return Response::serverError($error);
        } catch (\Exception $e) {
            error_log("Apple authentication error: " . $e->getMessage());
            return Response::serverError("Failed to initialize Apple authentication");
        }
    }

    /**
     * Apple native token authentication
     *
     * @route POST /auth/social/apple
     */
    public function appleNative(Request $request): Response
    {
        try {
            $requestData = json_decode($request->getContent(), true);
            $idToken = $requestData['id_token'] ?? null;

            if (empty($idToken)) {
                return Response::validation(['id_token' => ['ID token is required']], 'Validation failed');
            }

            $userData = $this->appleProvider->verifyNativeToken($idToken);

            if (!$userData) {
                $error = $this->appleProvider->getError() ?: 'Failed to verify Apple ID token';
                return Response::unauthorized($error);
            }

            $tokens = $this->appleProvider->generateTokens($userData);

            return Response::success([
                'user' => $userData,
                'tokens' => $tokens
            ], 'Successfully authenticated with Apple');
        } catch (\Exception $e) {
            error_log('Apple token verification error: ' . $e->getMessage());
            return Response::serverError('Failed to authenticate with Apple');
        }
    }

    /**
     * Apple OAuth callback
     *
     * @route POST /auth/social/apple/callback
     */
    public function appleCallback(Request $request): Response
    {
        try {
            $userData = $this->appleProvider->authenticate($request);

            if (!$userData) {
                $error = $this->appleProvider->getError() ?: "Failed to authenticate with Apple";
                return Response::unauthorized($error);
            }

            $tokens = $this->appleProvider->generateTokens($userData);

            return Response::success([
                'user' => $userData,
                'tokens' => $tokens
            ], "Successfully authenticated with Apple");
        } catch (\Exception $e) {
            error_log("Apple callback error: " . $e->getMessage());
            return Response::serverError("Failed to process Apple authentication");
        }
    }
}
