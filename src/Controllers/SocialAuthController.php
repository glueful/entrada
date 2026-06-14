<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Controllers;

use Glueful\Http\Response;
use Glueful\Auth\TokenManager;
use Glueful\Extensions\Entrada\Providers\GoogleAuthProvider;
use Glueful\Extensions\Entrada\Providers\FacebookAuthProvider;
use Glueful\Extensions\Entrada\Providers\GithubAuthProvider;
use Glueful\Extensions\Entrada\Providers\AppleAuthProvider;
use Glueful\Extensions\Entrada\Providers\AbstractSocialProvider;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Routing\Attributes\QueryParam;
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
    private TokenManager $tokenManager;

    public function __construct(
        GoogleAuthProvider $googleProvider,
        FacebookAuthProvider $facebookProvider,
        GithubAuthProvider $githubProvider,
        AppleAuthProvider $appleProvider,
        TokenManager $tokenManager
    ) {
        $this->googleProvider = $googleProvider;
        $this->facebookProvider = $facebookProvider;
        $this->githubProvider = $githubProvider;
        $this->appleProvider = $appleProvider;
        $this->tokenManager = $tokenManager;
    }

    // =========================================================================
    // Google Authentication
    // =========================================================================

    /**
     * Initiate Google OAuth flow.
     */
    #[ApiOperation(
        summary: 'Google OAuth Authentication',
        description: 'Initiates the OAuth flow with Google for user authentication.',
        tags: ['Social Authentication'],
    )]
    #[ApiResponse(302, description: "Redirects to Google's OAuth authorization page")]
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
     * Google native token authentication.
     */
    #[ApiOperation(
        summary: 'Google Native Authentication',
        description: 'Authenticates a user with a Google ID token from a native mobile app. '
            . 'Body: `id_token` (required) — ID token obtained from Google Sign-In SDK.',
        tags: ['Social Authentication'],
    )]
    #[ApiResponse(200, description: 'Successfully authenticated with Google')]
    #[ApiResponse(400, description: 'Missing ID token')]
    #[ApiResponse(401, description: 'Failed to verify Google ID token')]
    #[ApiResponse(500, description: 'Server error during authentication')]
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
                return $this->providerFailureResponse($this->googleProvider, 'Failed to verify Google ID token');
            }

            return Response::success(
                $this->buildSessionResponse($userData, GoogleAuthProvider::PROVIDER),
                'Successfully authenticated with Google'
            );
        } catch (\Exception $e) {
            error_log('Google token verification error: ' . $e->getMessage());
            return Response::serverError('Failed to authenticate with Google');
        }
    }

    /**
     * Google OAuth callback.
     */
    #[ApiOperation(
        summary: 'Google OAuth Callback',
        description: 'Callback endpoint that processes the OAuth response from Google.',
        tags: ['Social Authentication'],
    )]
    #[QueryParam('code', description: 'Authorization code from Google', required: true)]
    #[QueryParam('state', description: 'State token for CSRF protection', required: true)]
    #[ApiResponse(200, description: 'Successfully authenticated with Google')]
    #[ApiResponse(400, description: 'Bad request or invalid parameters')]
    #[ApiResponse(401, description: 'Authentication failed')]
    public function googleCallback(Request $request): Response
    {
        try {
            $userData = $this->googleProvider->authenticate($request);

            if (!$userData) {
                return $this->providerFailureResponse($this->googleProvider, 'Failed to authenticate with Google');
            }

            return Response::success(
                $this->buildSessionResponse($userData, GoogleAuthProvider::PROVIDER),
                "Successfully authenticated with Google"
            );
        } catch (\Exception $e) {
            error_log("Google callback error: " . $e->getMessage());
            return Response::serverError("Failed to process Google authentication");
        }
    }

    // =========================================================================
    // Facebook Authentication
    // =========================================================================

    /**
     * Initiate Facebook OAuth flow.
     */
    #[ApiOperation(
        summary: 'Facebook OAuth Authentication',
        description: 'Initiates the OAuth flow with Facebook for user authentication.',
        tags: ['Social Authentication'],
    )]
    #[ApiResponse(302, description: "Redirects to Facebook's OAuth authorization page")]
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
     * Facebook native token authentication.
     */
    #[ApiOperation(
        summary: 'Facebook Native Authentication',
        description: 'Authenticates a user with a Facebook access token from a native mobile app. '
            . 'Body: `access_token` (required) — access token obtained from Facebook Login SDK.',
        tags: ['Social Authentication'],
    )]
    #[ApiResponse(200, description: 'Successfully authenticated with Facebook')]
    #[ApiResponse(400, description: 'Missing access token')]
    #[ApiResponse(401, description: 'Failed to verify Facebook access token')]
    #[ApiResponse(500, description: 'Server error during authentication')]
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
                return $this->providerFailureResponse(
                    $this->facebookProvider,
                    'Failed to verify Facebook access token'
                );
            }

            return Response::success(
                $this->buildSessionResponse($userData, FacebookAuthProvider::PROVIDER),
                'Successfully authenticated with Facebook'
            );
        } catch (\Exception $e) {
            error_log('Facebook token verification error: ' . $e->getMessage());
            return Response::serverError('Failed to authenticate with Facebook');
        }
    }

    /**
     * Facebook OAuth callback.
     */
    #[ApiOperation(
        summary: 'Facebook OAuth Callback',
        description: 'Callback endpoint that processes the OAuth response from Facebook.',
        tags: ['Social Authentication'],
    )]
    #[QueryParam('code', description: 'Authorization code from Facebook', required: true)]
    #[QueryParam('state', description: 'State token for CSRF protection', required: true)]
    #[ApiResponse(200, description: 'Successfully authenticated with Facebook')]
    #[ApiResponse(400, description: 'Bad request or invalid parameters')]
    #[ApiResponse(401, description: 'Authentication failed')]
    public function facebookCallback(Request $request): Response
    {
        try {
            $userData = $this->facebookProvider->authenticate($request);

            if (!$userData) {
                return $this->providerFailureResponse($this->facebookProvider, 'Failed to authenticate with Facebook');
            }

            return Response::success(
                $this->buildSessionResponse($userData, FacebookAuthProvider::PROVIDER),
                "Successfully authenticated with Facebook"
            );
        } catch (\Exception $e) {
            error_log("Facebook callback error: " . $e->getMessage());
            return Response::serverError("Failed to process Facebook authentication");
        }
    }

    // =========================================================================
    // GitHub Authentication
    // =========================================================================

    /**
     * Initiate GitHub OAuth flow.
     */
    #[ApiOperation(
        summary: 'GitHub OAuth Authentication',
        description: 'Initiates the OAuth flow with GitHub for user authentication.',
        tags: ['Social Authentication'],
    )]
    #[ApiResponse(302, description: "Redirects to GitHub's OAuth authorization page")]
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
     * GitHub native token authentication.
     */
    #[ApiOperation(
        summary: 'GitHub Native Authentication',
        description: 'Authenticates a user with a GitHub access token from a native mobile app. '
            . 'Body: `access_token` (required) — access token obtained from GitHub OAuth.',
        tags: ['Social Authentication'],
    )]
    #[ApiResponse(200, description: 'Successfully authenticated with GitHub')]
    #[ApiResponse(400, description: 'Missing access token')]
    #[ApiResponse(401, description: 'Failed to verify GitHub access token')]
    #[ApiResponse(500, description: 'Server error during authentication')]
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
                return $this->providerFailureResponse($this->githubProvider, 'Failed to verify GitHub access token');
            }

            return Response::success(
                $this->buildSessionResponse($userData, GithubAuthProvider::PROVIDER),
                'Successfully authenticated with GitHub'
            );
        } catch (\Exception $e) {
            error_log('GitHub token verification error: ' . $e->getMessage());
            return Response::serverError('Failed to authenticate with GitHub');
        }
    }

    /**
     * GitHub OAuth callback.
     */
    #[ApiOperation(
        summary: 'GitHub OAuth Callback',
        description: 'Callback endpoint that processes the OAuth response from GitHub.',
        tags: ['Social Authentication'],
    )]
    #[QueryParam('code', description: 'Authorization code from GitHub', required: true)]
    #[QueryParam('state', description: 'State token for CSRF protection', required: true)]
    #[ApiResponse(200, description: 'Successfully authenticated with GitHub')]
    #[ApiResponse(400, description: 'Bad request or invalid parameters')]
    #[ApiResponse(401, description: 'Authentication failed')]
    public function githubCallback(Request $request): Response
    {
        try {
            $userData = $this->githubProvider->authenticate($request);

            if (!$userData) {
                return $this->providerFailureResponse($this->githubProvider, 'Failed to authenticate with GitHub');
            }

            return Response::success(
                $this->buildSessionResponse($userData, GithubAuthProvider::PROVIDER),
                "Successfully authenticated with GitHub"
            );
        } catch (\Exception $e) {
            error_log("GitHub callback error: " . $e->getMessage());
            return Response::serverError("Failed to process GitHub authentication");
        }
    }

    // =========================================================================
    // Apple Authentication
    // =========================================================================

    /**
     * Initiate Apple OAuth flow.
     */
    #[ApiOperation(
        summary: 'Apple OAuth Authentication',
        description: 'Initiates the OAuth flow with Apple for user authentication.',
        tags: ['Social Authentication'],
    )]
    #[ApiResponse(302, description: "Redirects to Apple's OAuth authorization page")]
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
     * Apple native token authentication.
     */
    #[ApiOperation(
        summary: 'Apple Native Authentication',
        description: 'Authenticates a user with an Apple ID token from a native mobile app. '
            . 'Body: `id_token` (required) — ID token obtained from Sign in with Apple SDK; '
            . '`nonce` — optional raw nonce the client bound to its Sign in with Apple request, '
            . "when supplied the token's nonce claim must match sha256(nonce) (replay protection).",
        tags: ['Social Authentication'],
    )]
    #[ApiResponse(200, description: 'Successfully authenticated with Apple')]
    #[ApiResponse(400, description: 'Missing ID token')]
    #[ApiResponse(401, description: 'Failed to verify Apple ID token')]
    #[ApiResponse(500, description: 'Server error during authentication')]
    public function appleNative(Request $request): Response
    {
        try {
            $requestData = json_decode($request->getContent(), true);
            $idToken = $requestData['id_token'] ?? null;

            if (empty($idToken)) {
                return Response::validation(['id_token' => ['ID token is required']], 'Validation failed');
            }

            // Optional replay protection: when the native client supplies the raw nonce it bound to
            // its Sign in with Apple request, the provider asserts the token's nonce claim matches.
            $rawNonce = $requestData['nonce'] ?? null;
            $rawNonce = is_string($rawNonce) && $rawNonce !== '' ? $rawNonce : null;

            $userData = $this->appleProvider->verifyNativeToken($idToken, $rawNonce);

            if (!$userData) {
                return $this->providerFailureResponse($this->appleProvider, 'Failed to verify Apple ID token');
            }

            return Response::success(
                $this->buildSessionResponse($userData, AppleAuthProvider::PROVIDER),
                'Successfully authenticated with Apple'
            );
        } catch (\Exception $e) {
            error_log('Apple token verification error: ' . $e->getMessage());
            return Response::serverError('Failed to authenticate with Apple');
        }
    }

    /**
     * Apple OAuth callback.
     */
    #[ApiOperation(
        summary: 'Apple OAuth Callback',
        description: 'Callback endpoint that processes the OAuth response from Apple. '
            . 'Body: `code` (required) — authorization code from Apple; `state` (required) — '
            . 'state token for CSRF protection; `user` — JSON string containing user information '
            . '(only provided on first login).',
        tags: ['Social Authentication'],
    )]
    #[ApiResponse(200, description: 'Successfully authenticated with Apple')]
    #[ApiResponse(400, description: 'Bad request or invalid parameters')]
    #[ApiResponse(401, description: 'Authentication failed')]
    public function appleCallback(Request $request): Response
    {
        try {
            $userData = $this->appleProvider->authenticate($request);

            if (!$userData) {
                return $this->providerFailureResponse($this->appleProvider, 'Failed to authenticate with Apple');
            }

            return Response::success(
                $this->buildSessionResponse($userData, AppleAuthProvider::PROVIDER),
                "Successfully authenticated with Apple"
            );
        } catch (\Exception $e) {
            error_log("Apple callback error: " . $e->getMessage());
            return Response::serverError("Failed to process Apple authentication");
        }
    }

    /**
     * @param array<string, mixed> $userData
     * @return array<string, mixed>
     */
    private function buildSessionResponse(array $userData, string $provider): array
    {
        if (!isset($userData['uuid']) || !is_string($userData['uuid']) || trim($userData['uuid']) === '') {
            throw new \RuntimeException('Authenticated user is missing uuid');
        }

        $sessionUser = $userData;
        $username = $sessionUser['username'] ?? null;
        if (!is_string($username) || trim($username) === '') {
            if (isset($sessionUser['name']) && is_string($sessionUser['name']) && trim($sessionUser['name']) !== '') {
                $sessionUser['username'] = trim($sessionUser['name']);
            } elseif (
                isset($sessionUser['email'])
                && is_string($sessionUser['email'])
                && trim($sessionUser['email']) !== ''
            ) {
                $sessionUser['username'] = strstr($sessionUser['email'], '@', true) ?: $sessionUser['uuid'];
            } else {
                $sessionUser['username'] = $sessionUser['uuid'];
            }
        }

        $session = $this->tokenManager->createUserSession($sessionUser, $provider);
        $accessToken = $session['access_token'] ?? null;
        $refreshToken = $session['refresh_token'] ?? null;
        $oidcUser = $session['user'] ?? null;

        if (!is_string($accessToken) || $accessToken === '' || !is_string($refreshToken) || $refreshToken === '') {
            throw new \RuntimeException('Failed to create authenticated session');
        }
        if (!is_array($oidcUser) || $oidcUser === []) {
            throw new \RuntimeException('Failed to build authenticated user profile');
        }

        return [
            'access_token' => $accessToken,
            'token_type' => $session['token_type'] ?? 'Bearer',
            'expires_in' => (int)($session['expires_in'] ?? 0),
            'refresh_token' => $refreshToken,
            'user' => $this->formatUserResponse($oidcUser),
        ];
    }

    private function providerFailureResponse(AbstractSocialProvider $provider, string $fallbackMessage): Response
    {
        // The provider's detailed error can embed raw upstream OAuth response bodies, so it is
        // logged server-side only; the client receives the generic fallback message. The status
        // code (set by the provider) is preserved.
        $detail = $provider->getError();
        if ($detail !== null && $detail !== '' && $detail !== $fallbackMessage) {
            error_log('[entrada] ' . $fallbackMessage . ': ' . $detail);
        }

        $statusCode = $provider->getErrorStatusCode();

        if ($statusCode === Response::HTTP_UNAUTHORIZED) {
            return Response::unauthorized($fallbackMessage);
        }

        return Response::error($fallbackMessage, $statusCode);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function formatUserResponse(array $user): array
    {
        return [
            'id' => isset($user['id']) ? (string)$user['id'] : '',
            'email' => $user['email'] ?? null,
            'email_verified' => (bool)($user['email_verified'] ?? false),
            'username' => isset($user['username']) ? (string)$user['username'] : '',
            'name' => isset($user['name']) ? (string)$user['name'] : null,
            'given_name' => isset($user['given_name']) ? (string)$user['given_name'] : null,
            'family_name' => isset($user['family_name']) ? (string)$user['family_name'] : null,
            'picture' => isset($user['picture']) ? (string)$user['picture'] : null,
            'locale' => isset($user['locale']) ? (string)$user['locale'] : 'en-US',
            'updated_at' => (int)($user['updated_at'] ?? time()),
        ];
    }
}
