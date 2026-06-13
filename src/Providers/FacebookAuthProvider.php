<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Providers;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Glueful\Extensions\Entrada\Providers\AbstractSocialProvider;
use Glueful\Http\Client;
use Glueful\Http\Exceptions\HttpException;

/**
 * Facebook Authentication Provider
 *
 * Handles Facebook OAuth authentication flow and user management.
 *
 * @package Glueful\Extensions\Entrada\Providers
 */
class FacebookAuthProvider extends AbstractSocialProvider
{
    public const PROVIDER = 'facebook';

    /** @var string App ID from Facebook Developers */
    private string $appId;

    /** @var string App secret from Facebook Developers */
    private string $appSecret;

    /** @var string Redirect URI for Facebook OAuth callback */
    private string $redirectUri;

    /** @var string Graph API version used in dialog, token and /me URLs */
    private string $apiVersion;

    /** @var array<int, string> OAuth scopes requested */
    private array $scopes = ['email', 'public_profile'];

    /** @var Client HTTP client instance */
    private Client $httpClient;

    /**
     * Constructor
     */
    public function __construct(ApplicationContext $context)
    {
        parent::__construct($context);

        // Set provider name
        $this->providerName = self::PROVIDER;

        // Initialize HTTP client
        $this->httpClient = $this->context->getContainer()->get(Client::class);

        // Load configuration
        $this->loadConfig();
    }

    /**
     * Load configuration from environment
     *
     * @return void
     */
    private function loadConfig(): void
    {
        // Get config from extension settings or environment
        $config = config($this->context, 'sauth', []);

        // Make sure the config has the expected structure
        if (!is_array($config) || !isset($config['facebook']) || !is_array($config['facebook'])) {
            $config = [
                'facebook' => []
            ];
        }

        $this->appId = !empty($config['facebook']['app_id']) ?
                       $config['facebook']['app_id'] :
                       (getenv('FACEBOOK_APP_ID') ?: '');

        $this->appSecret = !empty($config['facebook']['app_secret']) ?
                           $config['facebook']['app_secret'] :
                           (getenv('FACEBOOK_APP_SECRET') ?: '');

        $this->redirectUri = !empty($config['facebook']['redirect_uri']) ?
                             $config['facebook']['redirect_uri'] :
                             (getenv('FACEBOOK_REDIRECT_URI') ?: $this->defaultCallbackUri());

        $this->apiVersion = !empty($config['facebook']['api_version']) ?
                            $config['facebook']['api_version'] :
                            (getenv('FACEBOOK_API_VERSION') ?: 'v21.0');
    }

    /**
     * Compute Facebook's appsecret_proof (HMAC-SHA256 of the access token, keyed by the app secret).
     *
     * Sent alongside Graph API requests so calls succeed when the app has "Require App Secret"
     * enabled and so a stolen token cannot be replayed without the app secret.
     *
     * @param string $accessToken Access token to bind the proof to
     * @return string Hex-encoded HMAC-SHA256 digest
     */
    private function appSecretProof(string $accessToken): string
    {
        return hash_hmac('sha256', $accessToken, $this->appSecret);
    }

    /**
     * Check if request is a Facebook OAuth callback
     *
     * @param Request $request The HTTP request
     * @return bool True if this is a callback request
     */
    protected function isOAuthCallback(Request $request): bool
    {
        $path = $request->getPathInfo();
        return strpos($path, '/auth/social/facebook/callback') !== false &&
               $request->query->has('code');
    }

    /**
     * Check if request is to initialize Facebook OAuth flow
     *
     * @param Request $request The HTTP request
     * @return bool True if this is an initialization request
     */
    protected function isOAuthInitRequest(Request $request): bool
    {
        $path = $request->getPathInfo();
        return strpos($path, '/auth/social/facebook') !== false &&
               !strpos($path, '/callback');
    }

    /**
     * Handle Facebook OAuth callback
     *
     * Process callback from Facebook, validate token/code,
     * and retrieve user information.
     *
     * @param Request $request The HTTP request
     * @return array<string, mixed>|null User data if authenticated, null otherwise
     */
    protected function handleCallback(Request $request): ?array
    {
        // Validate configuration
        if (empty($this->appId) || empty($this->appSecret)) {
            $this->lastError = "Facebook OAuth configuration is missing";
            return null;
        }

        // Validate the CSRF state parameter (fail closed) before doing any work.
        if (!$this->validateOAuthState($request)) {
            $this->lastError = "Invalid or missing OAuth state parameter";
            $this->lastErrorStatusCode = 401;
            return null;
        }

        // Get authorization code from request
        $code = $request->query->get('code');

        if (empty($code)) {
            $this->lastError = "Authorization code missing from request";
            return null;
        }

        try {
            // Exchange code for access token
            $tokenData = $this->exchangeCodeForToken($code);

            if (!isset($tokenData['access_token'])) {
                $this->lastError = "Failed to get access token: " .
                                  ($tokenData['error']['message'] ?? 'Unknown error');
                return null;
            }

            // Get user profile with the access token
            $userProfile = $this->getUserProfile($tokenData['access_token']);

            if (!isset($userProfile['id'])) {
                $this->lastError = "Failed to get user profile";
                return null;
            }

            // Find or create user from Facebook data
            return $this->findOrCreateUser($userProfile);
        } catch (\Exception $e) {
            $this->lastError = "Facebook OAuth error: " . $e->getMessage();
            return null;
        }
    }

    /**
     * Initiate Facebook OAuth flow
     *
     * Generate authorization URL and redirect user to Facebook.
     *
     * @param Request $request The HTTP request
     * @return void
     */
    protected function initiateOAuthFlow(Request $request): void
    {
        // Validate configuration
        if (empty($this->appId) || empty($this->appSecret)) {
            throw new \RuntimeException("Facebook OAuth configuration is missing");
        }

        // Generate and persist a CSRF state token (validated on callback) and a PKCE challenge
        // (verifier sent on the token exchange). Facebook's flow returns an access token (no
        // OIDC id_token), so no nonce is used here.
        $state = $this->storeOAuthState();
        $codeChallenge = $this->startPkce();

        // Build authorization URL
        $authUrl = "https://www.facebook.com/{$this->apiVersion}/dialog/oauth";
        $params = [
            'client_id' => $this->appId,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'scope' => implode(',', $this->scopes),
            'response_type' => 'code',
            'auth_type' => 'rerequest',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        $authUrl .= '?' . http_build_query($params);

        // Redirect to Facebook
        $response = new RedirectResponse($authUrl);
        $response->send();
        exit;
    }

    /**
     * Exchange authorization code for access token
     *
     * @param string $code Authorization code from Facebook
     * @return array<string, mixed> Token data
     */
    private function exchangeCodeForToken(string $code): array
    {
        // Token endpoint
        $tokenUrl = "https://graph.facebook.com/{$this->apiVersion}/oauth/access_token";

        // Request parameters
        $params = [
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUri
        ];

        // Include the PKCE verifier (single-use) when one was stored during initiation.
        $verifier = $this->consumePkceVerifier();
        if ($verifier !== null) {
            $params['code_verifier'] = $verifier;
        }

        // Make request to token endpoint. POST with a form body keeps the app secret and
        // authorization code out of proxy/access/slow-request query-string logs.
        try {
            $response = $this->httpClient->post($tokenUrl, [
                'timeout' => 30,
                'headers' => [
                    'Accept' => 'application/json'
                ],
                'form_params' => $params
            ]);

            if (!$response->isSuccessful()) {
                throw new \Exception(
                    "Token exchange failed with HTTP code: " . $response->getStatusCode() .
                    ", Response: " . $response->getBody()
                );
            }

            // Parse JSON response
            $tokenData = $response->json();

            if (!is_array($tokenData)) {
                throw new \Exception("Invalid token response: " . $response->getBody());
            }
        } catch (HttpException $e) {
            throw new \Exception("Failed to exchange code for token: " . $e->getMessage());
        } catch (\JsonException $e) {
            throw new \Exception("Invalid JSON response from token endpoint: " . $e->getMessage());
        }

        return $tokenData;
    }

    /**
     * Get user profile from Facebook
     *
     * @param string $accessToken Access token from Facebook
     * @return array<string, mixed> User profile data
     */
    private function getUserProfile(string $accessToken): array
    {
        // Facebook Graph API endpoint
        $fields = 'id,name,email,first_name,last_name,picture.type(large),gender,birthday,location';

        // appsecret_proof binds the call to our app secret: required when the app enables
        // "Require App Secret" and prevents replay of a stolen token from another origin.
        $params = [
            'fields' => $fields,
            'access_token' => $accessToken,
            'appsecret_proof' => $this->appSecretProof($accessToken),
        ];
        $userInfoUrl = "https://graph.facebook.com/{$this->apiVersion}/me";

        // Make GET request
        try {
            $response = $this->httpClient->get($userInfoUrl, [
                'timeout' => 30,
                'query' => $params
            ]);

            if (!$response->isSuccessful()) {
                throw new \Exception(
                    "Failed to get user profile, HTTP code: " . $response->getStatusCode() .
                    ", Response: " . $response->getBody()
                );
            }

            // Parse JSON response
            $userProfile = $response->json();

            if (!is_array($userProfile)) {
                throw new \Exception("Invalid profile response: " . $response->getBody());
            }
        } catch (HttpException $e) {
            throw new \Exception("Failed to get user profile: " . $e->getMessage());
        } catch (\JsonException $e) {
            throw new \Exception("Invalid JSON response from Facebook Graph API: " . $e->getMessage());
        }

        // Extract picture URL from nested structure
        $pictureUrl = null;
        if (isset($userProfile['picture']['data']['url'])) {
            $pictureUrl = $userProfile['picture']['data']['url'];
        }

        // Format profile data to our standard format
        return [
            'id' => $userProfile['id'],
            'email' => $userProfile['email'] ?? null,
            'name' => $userProfile['name'] ?? null,
            'first_name' => $userProfile['first_name'] ?? null,
            'last_name' => $userProfile['last_name'] ?? null,
            'picture' => $pictureUrl,
            'gender' => $userProfile['gender'] ?? null,
            'birthday' => $userProfile['birthday'] ?? null,
            'location' => isset($userProfile['location']['name']) ?
                          $userProfile['location']['name'] : null,
            // Facebook's Graph API does not expose a per-email verified flag, so the email is
            // treated as unverified. This prevents email-based auto-linking to an existing
            // account (pre-account-takeover); users link Facebook explicitly while signed in.
            'verified_email' => false,
            'raw' => $userProfile
        ];
    }

    /**
     * Refresh authentication tokens
     *
     * Generates new token pair using refresh token.
     * For OAuth providers like Facebook, we typically can't refresh tokens directly,
     * but instead need to generate new ones through the normal flow.
     *
     * @param string $refreshToken Current refresh token
     * @param array<string, mixed> $sessionData Session data associated with the refresh token
     * @return array<string, mixed>|null New token pair or null if invalid
     */
    public function refreshTokens(string $refreshToken, array $sessionData): ?array
    {
        return parent::refreshTokens($refreshToken, $sessionData);
    }

    /**
     * Verify a token from a native mobile SDK
     *
     * @param string $accessToken Access token from Facebook Login SDK
     * @return array<string, mixed>|null User data if verified, null otherwise
     */
    public function verifyNativeToken(string $accessToken): ?array
    {
        // Validate configuration
        if (empty($this->appId) || empty($this->appSecret)) {
            $this->lastError = "Facebook OAuth configuration is missing";
            return null;
        }

        try {
            // Verify the access token with Facebook's API
            $tokenData = $this->verifyFacebookAccessToken($accessToken);

            if (!$tokenData || !isset($tokenData['is_valid']) || !$tokenData['is_valid']) {
                $this->lastError = "Invalid Facebook access token";
                return null;
            }

            // Check if the token was issued for our app
            if (isset($tokenData['app_id']) && $tokenData['app_id'] !== $this->appId) {
                $this->lastError = "Token was not issued for this application";
                return null;
            }

            // Get user profile with the access token
            $userProfile = $this->getUserProfile($accessToken);

            if (!isset($userProfile['id'])) {
                $this->lastError = "Failed to get user profile";
                return null;
            }

            // Find or create user from Facebook data
            return $this->findOrCreateUser($userProfile);
        } catch (\Exception $e) {
            $this->lastError = "Facebook token verification error: " . $e->getMessage();
            return null;
        }
    }

    /**
     * Verify Facebook access token with Facebook's API
     *
     * @param string $accessToken Access token from Facebook
     * @return array<string, mixed> Token data if verified (throws on any failure)
     */
    private function verifyFacebookAccessToken(string $accessToken): array
    {
        // Facebook's debug token endpoint
        $debugTokenUrl = "https://graph.facebook.com/debug_token";
        $params = [
            'input_token' => $accessToken,
            'access_token' => $this->appId . '|' . $this->appSecret // App access token
        ];

        // Make the request to Facebook
        try {
            $response = $this->httpClient->get($debugTokenUrl, [
                'timeout' => 10,
                'query' => $params
            ]);

            if (!$response->isSuccessful()) {
                throw new \Exception(
                    "Failed to verify access token, HTTP code: " . $response->getStatusCode() .
                    ", Response: " . $response->getBody()
                );
            }

            // Parse JSON response
            $data = $response->json();

            if (!is_array($data) || !isset($data['data'])) {
                throw new \Exception("Invalid debug token response: " . $response->getBody());
            }
        } catch (HttpException $e) {
            throw new \Exception("Failed to verify access token: " . $e->getMessage());
        } catch (\JsonException $e) {
            throw new \Exception("Invalid JSON response from Facebook debug token endpoint: " . $e->getMessage());
        }

        return $data['data'];
    }
}
