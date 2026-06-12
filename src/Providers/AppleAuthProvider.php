<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Providers;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Glueful\Extensions\Entrada\Providers\AbstractSocialProvider;
use Glueful\Auth\JWTService;
use Glueful\Extensions\Entrada\Providers\ASN1Parser;
use Glueful\Http\Client;
use Glueful\Http\Exceptions\HttpException;

/**
 * Apple Authentication Provider
 *
 * Handles Apple OAuth authentication flow and user management.
 *
 * @package Glueful\Extensions\Entrada\Providers
 */
class AppleAuthProvider extends AbstractSocialProvider
{
    public const PROVIDER = 'apple';

    /** @var string Client ID (Service ID) from Apple Developer Account */
    private string $clientId;

    /** @var string Client secret (Generated using the private key) */
    private string $clientSecret;

    /** @var string Team ID from Apple Developer Account */
    private string $teamId;

    /** @var string Key ID from Apple Developer Account */
    private string $keyId;

    /** @var string Redirect URI for Apple OAuth callback */
    private string $redirectUri;

    /** @var array<int, string> OAuth scopes requested */
    private array $scopes = [
        'name',
        'email'
    ];

    /** @var Client HTTP client instance */
    private Client $httpClient;

    /**
     * Constructor
     */
    public function __construct(ApplicationContext $context)
    {
        parent::__construct($context);

        // Initialize HTTP client
        $this->httpClient = $this->context->getContainer()->get(Client::class);

        // Set provider name
        $this->providerName = self::PROVIDER;

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
        if (!is_array($config) || !isset($config['apple']) || !is_array($config['apple'])) {
            $config = [
                'apple' => []
            ];
        }

        $this->clientId = !empty($config['apple']['client_id']) ?
                          $config['apple']['client_id'] :
                          (getenv('APPLE_CLIENT_ID') ?: '');

        $this->clientSecret = !empty($config['apple']['client_secret']) ?
                              $config['apple']['client_secret'] :
                              (getenv('APPLE_CLIENT_SECRET') ?: '');

        $this->teamId = !empty($config['apple']['team_id']) ?
                        $config['apple']['team_id'] :
                        (getenv('APPLE_TEAM_ID') ?: '');

        $this->keyId = !empty($config['apple']['key_id']) ?
                       $config['apple']['key_id'] :
                       (getenv('APPLE_KEY_ID') ?: '');

        $this->redirectUri = !empty($config['apple']['redirect_uri']) ?
                             $config['apple']['redirect_uri'] :
                             (getenv('APPLE_REDIRECT_URI') ?: $this->defaultCallbackUri());
    }

    /**
     * Check if request is an Apple OAuth callback
     *
     * @param Request $request The HTTP request
     * @return bool True if this is a callback request
     */
    protected function isOAuthCallback(Request $request): bool
    {
        $path = $request->getPathInfo();
        return strpos($path, '/auth/social/apple/callback') !== false &&
               ($request->request->has('code') || $request->query->has('code'));
    }

    /**
     * Check if request is to initialize Apple OAuth flow
     *
     * @param Request $request The HTTP request
     * @return bool True if this is an initialization request
     */
    protected function isOAuthInitRequest(Request $request): bool
    {
        $path = $request->getPathInfo();
        return strpos($path, '/auth/social/apple') !== false &&
               !strpos($path, '/callback');
    }

    /**
     * Handle Apple OAuth callback
     *
     * Process callback from Apple, validate token/code,
     * and retrieve user information.
     *
     * @param Request $request The HTTP request
     * @return array<string, mixed>|null User data if authenticated, null otherwise
     */
    protected function handleCallback(Request $request): ?array
    {
        // Validate configuration
        if (empty($this->clientId) || empty($this->teamId) || empty($this->keyId)) {
            $this->lastError = "Apple OAuth configuration is missing";
            return null;
        }

        // Validate the CSRF state parameter (fail closed) before doing any work.
        // Apple uses response_mode=form_post, so state arrives in the POST body.
        if (!$this->validateOAuthState($request)) {
            $this->lastError = "Invalid or missing OAuth state parameter";
            $this->lastErrorStatusCode = 401;
            return null;
        }

        // Get authorization code from request
        $code = (string) ($request->request->get('code') ?? $request->query->get('code') ?? '');

        if ($code === '') {
            $this->lastError = "Authorization code missing from request";
            return null;
        }

        // Apple returns user info only on the first login, so we need to check for it
        $userData = null;
        if ($request->request->has('user')) {
            $userDataJson = (string) $request->request->get('user');
            $decodedUser = json_decode($userDataJson, true);
            $userData = is_array($decodedUser) ? $decodedUser : null;
        }

        try {
            // Exchange code for access token
            $tokenData = $this->exchangeCodeForToken($code);

            if (!isset($tokenData['access_token'])) {
                $this->lastError = "Failed to get access token: " .
                                  ($tokenData['error'] ?? 'Unknown error');
                return null;
            }

            // Extract user information from the verified ID token (signature + claims checked).
            $userProfile = $this->extractUserProfile($tokenData['id_token'], $userData);

            if (!isset($userProfile['id'])) {
                $this->lastError = "Failed to get user profile";
                return null;
            }

            // Bind the id_token to this authorization request via the OIDC nonce (the verified
            // claims are available under 'raw'). Only enforced on the web flow, where we issued
            // the nonce — the native SDK flow has no server-stored nonce.
            $claims = is_array($userProfile['raw'] ?? null) ? $userProfile['raw'] : [];
            if (!$this->validateNonce($claims['nonce'] ?? null)) {
                $this->lastError = "Invalid or missing OAuth nonce";
                $this->lastErrorStatusCode = 401;
                return null;
            }

            // Find or create user from Apple data
            return $this->findOrCreateUser($userProfile);
        } catch (\Exception $e) {
            $this->lastError = "Apple OAuth error: " . $e->getMessage();
            return null;
        }
    }

    /**
     * Initiate Apple OAuth flow
     *
     * Generate authorization URL and redirect user to Apple.
     *
     * @param Request $request The HTTP request
     * @return void
     */
    protected function initiateOAuthFlow(Request $request): void
    {
        // Validate configuration
        if (empty($this->clientId) || empty($this->teamId) || empty($this->keyId)) {
            throw new \RuntimeException("Apple OAuth configuration is missing");
        }

        // Generate and persist a CSRF state token (validated on callback), a PKCE challenge
        // (verifier sent on the token exchange), and an OIDC nonce (echoed in the id_token and
        // validated after its signature is verified).
        $state = $this->storeOAuthState();
        $codeChallenge = $this->startPkce();
        $nonce = $this->startNonce();

        // Build authorization URL
        $authUrl = 'https://appleid.apple.com/auth/authorize';
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'state' => $state,
            'scope' => implode(' ', $this->scopes),
            'response_mode' => 'form_post',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'nonce' => $nonce,
        ];

        $authUrl .= '?' . http_build_query($params);

        // Redirect to Apple
        $response = new RedirectResponse($authUrl);
        $response->send();
        exit;
    }

    /**
     * Exchange authorization code for access token
     *
     * @param string $code Authorization code from Apple
     * @return array<string, mixed> Token data
     */
    private function exchangeCodeForToken(string $code): array
    {
        // Generate client secret (JWT) for Apple
        $clientSecret = $this->generateClientSecret();

        // Token endpoint
        $tokenUrl = 'https://appleid.apple.com/auth/token';

        // Request parameters
        $params = [
            'client_id' => $this->clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code'
        ];

        // Include the PKCE verifier (single-use) when one was stored during initiation.
        $verifier = $this->consumePkceVerifier();
        if ($verifier !== null) {
            $params['code_verifier'] = $verifier;
        }

        // Make POST request to token endpoint
        try {
            $response = $this->httpClient->post($tokenUrl, [
                'timeout' => 30,
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
     * Generate client secret JWT for Apple
     *
     * @return string JWT token to use as client secret
     */
    private function generateClientSecret(): string
    {
        // If client secret is already set and it's a valid JWT, use it
        if (!empty($this->clientSecret) && strpos($this->clientSecret, '.') !== false) {
            return $this->clientSecret;
        }

        // Private key from environment or file
        $privateKey = $this->clientSecret;

        // If private key starts with a path, read the file
        if (strpos($privateKey, '/') === 0 && file_exists($privateKey)) {
            $contents = file_get_contents($privateKey);
            if ($contents === false) {
                throw new \Exception("Unable to read Apple private key file");
            }
            $privateKey = $contents;
        }

        // Since Apple requires ES256 algorithm which our JWTService doesn't support yet,
        // we need to implement ES256 signing directly for this specific use case

        // Prepare the JWT header and payload
        $header = [
            'kid' => $this->keyId,
            'alg' => 'ES256'
        ];

        $payload = [
            'iss' => $this->teamId,
            'iat' => time(),
            'exp' => time() + 3600, // 1 hour expiration
            'aud' => 'https://appleid.apple.com',
            'sub' => $this->clientId
        ];

        // Encode header and payload
        $headerEncoded = $this->base64UrlEncode((string) json_encode($header));
        $payloadEncoded = $this->base64UrlEncode((string) json_encode($payload));

        // Create signature input
        $signatureInput = $headerEncoded . '.' . $payloadEncoded;

        // Generate signature using OpenSSL's ES256 support
        $signature = '';
        openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        // Convert signature to proper DER format for ES256
        $signature = $this->convertDERtoJOSE($signature);

        // Base64Url encode the signature
        $signatureEncoded = $this->base64UrlEncode($signature);

        // Return complete JWT
        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }

    /**
     * Convert DER format signature to JOSE format
     *
     * @param string $der DER encoded signature from OpenSSL
     * @return string JOSE format signature
     */
    private function convertDERtoJOSE(string $der): string
    {
        // Extract R and S values from DER format
        $asn1 = new ASN1Parser($der);
        $seq = $asn1->readObject();

        if ($seq['type'] !== 0x30) {
            throw new \Exception('Invalid DER signature format');
        }

        $r = $asn1->readObject();
        $s = $asn1->readObject();

        if ($r['type'] !== 0x02 || $s['type'] !== 0x02) {
            throw new \Exception('Invalid DER signature values');
        }

        // Convert to fixed-length 32-byte values
        $rBin = $this->convertToBinary($r['value'], 32);
        $sBin = $this->convertToBinary($s['value'], 32);

        // Concatenate R and S values
        return $rBin . $sBin;
    }

    /**
     * Convert integer to fixed-length binary
     *
     * @param string $value Integer value as binary string
     * @param int $length Desired length in bytes
     * @return string Fixed-length binary string
     */
    private function convertToBinary(string $value, int $length): string
    {
        // Remove leading zeros
        $value = ltrim($value, "\x00");

        // A zero component decodes to an empty string; return the zero-padded length.
        if ($value === '') {
            return str_repeat("\x00", $length);
        }

        // Handle negative numbers (remove leading 0xFF)
        if (ord($value[0]) >= 0x80) {
            $value = "\x00" . $value;
        }

        // Pad to desired length
        $value = str_pad($value, $length, "\x00", STR_PAD_LEFT);

        // Truncate if too long
        if (strlen($value) > $length) {
            $value = substr($value, -$length);
        }

        return $value;
    }

    /**
     * Base64URL encode (using framework's JWTService if available)
     *
     * @param string $data Data to encode
     * @return string Base64URL encoded string
     */
    private function base64UrlEncode(string $data): string
    {
        // Use reflection to access the private method in JWTService
        try {
            $reflection = new \ReflectionClass(JWTService::class);
            $method = $reflection->getMethod('base64UrlEncode');

            return $method->invoke(null, $data);
        } catch (\ReflectionException $e) {
            // Fallback implementation if reflection fails
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        }
    }

    /**
     * Extract user profile from a verified ID token.
     *
     * The token's RS256 signature is verified against Apple's JWKS and its iss/aud/exp claims
     * are asserted (see verifyAndDecodeIdToken) BEFORE any claim is trusted. This is the single
     * verified-claims path shared by both the web callback and native-SDK flows.
     *
     * @param string $idToken ID token from Apple
     * @param array<string, mixed>|null $userData User data from request (only on first login)
     * @return array<string, mixed> User profile data
     * @throws \Exception If the token signature or claims are invalid
     */
    protected function extractUserProfile(string $idToken, ?array $userData = null): array
    {
        $payload = $this->verifyAndDecodeIdToken($idToken);

        // Get user info from token and request data
        $profile = [
            'id' => $payload['sub'] ?? null,
            'email' => $payload['email'] ?? null,
            // Promote the verified-email claim to a top-level key so the canonical alias
            // resolution in AbstractSocialProvider::extractSocialValue() (which only inspects
            // top-level keys) can see it. Apple sends this as bool true OR the string "true";
            // pass the raw claim through unchanged — isVerifiedFlag() normalizes both, and
            // coercing here would lose the distinction it relies on.
            'email_verified' => $payload['email_verified'] ?? false,
            'name' => null,
            'first_name' => null,
            'last_name' => null,
            'raw' => $payload
        ];

        // Apple only sends name information on the first login
        if (is_array($userData) && isset($userData['name'])) {
            $profile['first_name'] = $userData['name']['firstName'] ?? null;
            $profile['last_name'] = $userData['name']['lastName'] ?? null;
            $profile['name'] = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
        }

        return $profile;
    }

    /**
     * Verify a token from a native mobile SDK
     *
     * @param string $idToken ID token from Sign in with Apple SDK
     * @return array<string, mixed>|null User data if verified, null otherwise
     */
    public function verifyNativeToken(string $idToken): ?array
    {
        // Validate configuration
        if (empty($this->clientId) || empty($this->teamId) || empty($this->keyId)) {
            $this->lastError = "Apple OAuth configuration is missing";
            return null;
        }

        try {
            // Verify the ID token signature + claims against Apple's JWKS, then build the
            // profile from the verified payload (extractUserProfile performs the verification).
            $userProfile = $this->extractUserProfile($idToken);

            if (!isset($userProfile['id'])) {
                $this->lastError = "Failed to extract user data from ID token";
                return null;
            }

            // Find or create user from Apple data
            return $this->findOrCreateUser($userProfile);
        } catch (\Exception $e) {
            $this->lastError = "Apple token verification error: " . $e->getMessage();
            return null;
        }
    }

    /**
     * Fetch Apple's JWKS, verify the ID token's RS256 signature against it, and assert the core
     * claims (iss/aud/exp). Returns the verified payload. This is the only path that decodes an
     * Apple ID token, so no caller ever trusts an unverified claim.
     *
     * @param string $idToken ID token from Sign in with Apple
     * @return array<string, mixed> Verified token claims
     * @throws \Exception If the JWKS cannot be fetched or verification fails
     */
    protected function verifyAndDecodeIdToken(string $idToken): array
    {
        $jwks = $this->fetchAppleJwks();

        return $this->verifyIdTokenWithJwks(
            $idToken,
            $jwks,
            $this->clientId,
            'https://appleid.apple.com'
        );
    }

    /**
     * Fetch Apple's JSON Web Key Set.
     *
     * @return array<string, mixed>
     * @throws \Exception If the JWKS cannot be fetched or is malformed
     */
    private function fetchAppleJwks(): array
    {
        $jwksUrl = 'https://appleid.apple.com/auth/keys';

        try {
            $response = $this->httpClient->get($jwksUrl, [
                'timeout' => 10
            ]);

            if (!$response->isSuccessful()) {
                throw new \Exception("Failed to fetch Apple JWKS, HTTP code: " . $response->getStatusCode());
            }

            $jwks = $response->json();
        } catch (HttpException $e) {
            throw new \Exception("Failed to fetch Apple JWKS: " . $e->getMessage());
        } catch (\JsonException $e) {
            throw new \Exception("Invalid JSON response from Apple JWKS endpoint: " . $e->getMessage());
        }

        if (!is_array($jwks) || !isset($jwks['keys']) || !is_array($jwks['keys'])) {
            throw new \Exception("Invalid JWKS response from Apple");
        }

        return $jwks;
    }

    /**
     * Cryptographically verify an Apple ID token against a JWKS and assert its core claims,
     * returning the verified payload. Network-free (the JWKS is supplied), so the signature
     * path is unit-testable.
     *
     * Verification order (signature is checked BEFORE any claim is trusted):
     *  1. token has three parts and a decodable header;
     *  2. header `alg` is exactly `RS256` (rejects `none` and HS/RS algorithm confusion);
     *  3. the header `kid` matches an RSA signing key in the JWKS;
     *  4. the RS256 signature over `header.payload` verifies against that key's public modulus;
     *  5. `iss` equals the expected issuer, `aud` contains the expected client id, `exp` is future.
     *
     * @param array<string, mixed> $jwks Apple's JWKS (decoded /auth/keys response)
     * @param int|null $now Unix time used for the expiry check (defaults to time())
     * @return array<string, mixed> Verified token claims
     * @throws \Exception If any step fails
     */
    protected function verifyIdTokenWithJwks(
        string $idToken,
        array $jwks,
        string $expectedAud,
        string $expectedIss,
        ?int $now = null
    ): array {
        $now ??= time();

        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new \Exception("Invalid ID token format");
        }
        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = json_decode($this->base64UrlDecode($encodedHeader), true);
        if (!is_array($header)) {
            throw new \Exception("Invalid ID token header");
        }

        // Pin the algorithm. Apple signs ID tokens with RS256; rejecting anything else closes
        // the `alg: none` bypass and RS/HS key-confusion attacks.
        if (($header['alg'] ?? null) !== 'RS256') {
            throw new \Exception("Unexpected ID token algorithm");
        }

        $kid = $header['kid'] ?? null;
        if (!is_string($kid) || $kid === '') {
            throw new \Exception("ID token header is missing kid");
        }

        $jwk = $this->findJwksKey($jwks, $kid);
        if ($jwk === null) {
            throw new \Exception("No matching key found for token verification");
        }

        $publicKey = $this->jwkToPublicKeyPem($jwk);
        $signature = $this->base64UrlDecode($encodedSignature);
        $signingInput = $encodedHeader . '.' . $encodedPayload;

        $result = openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($result !== 1) {
            throw new \Exception("ID token signature verification failed");
        }

        $payload = json_decode($this->base64UrlDecode($encodedPayload), true);
        if (!is_array($payload)) {
            throw new \Exception("Invalid ID token payload");
        }

        // Claims are only trusted now that the signature is proven.
        if (($payload['iss'] ?? null) !== $expectedIss) {
            throw new \Exception("Token was not issued by Apple");
        }

        $aud = $payload['aud'] ?? null;
        $audMatches = is_array($aud) ? in_array($expectedAud, $aud, true) : ($aud === $expectedAud);
        if (!$audMatches) {
            throw new \Exception("Token was not issued for this application");
        }

        $exp = $payload['exp'] ?? null;
        if (!is_numeric($exp)) {
            throw new \Exception("Token is missing expiration");
        }
        if ((int) $exp <= $now) {
            throw new \Exception("Token has expired");
        }

        return $payload;
    }

    /**
     * Locate the RSA signing key in a JWKS by key id.
     *
     * @param array<string, mixed> $jwks
     * @return array<string, mixed>|null
     */
    private function findJwksKey(array $jwks, string $kid): ?array
    {
        $keys = is_array($jwks['keys'] ?? null) ? $jwks['keys'] : [];

        foreach ($keys as $key) {
            if (!is_array($key)) {
                continue;
            }
            if (($key['kid'] ?? null) === $kid && ($key['kty'] ?? null) === 'RSA') {
                return $key;
            }
        }

        return null;
    }

    /**
     * Reconstruct an RSA public key (PEM) from a JWK's base64url modulus (`n`) and exponent (`e`)
     * by DER-encoding a SubjectPublicKeyInfo structure.
     *
     * @param array<string, mixed> $jwk
     * @return string PEM-encoded public key
     * @throws \Exception If the JWK is missing the RSA parameters
     */
    private function jwkToPublicKeyPem(array $jwk): string
    {
        $n = isset($jwk['n']) && is_string($jwk['n']) ? $this->base64UrlDecode($jwk['n']) : '';
        $e = isset($jwk['e']) && is_string($jwk['e']) ? $this->base64UrlDecode($jwk['e']) : '';

        if ($n === '' || $e === '') {
            throw new \Exception("JWK is missing RSA public key parameters");
        }

        // RSAPublicKey ::= SEQUENCE { modulus INTEGER, publicExponent INTEGER }
        $rsaPublicKey = $this->derSequence(
            $this->derInteger($n) . $this->derInteger($e)
        );

        // SubjectPublicKeyInfo ::= SEQUENCE { AlgorithmIdentifier, BIT STRING }
        // AlgorithmIdentifier for rsaEncryption (OID 1.2.840.113549.1.1.1) with NULL parameters.
        $algorithmIdentifier = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $bitString = "\x03" . $this->derLength(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;

        $spki = $this->derSequence($algorithmIdentifier . $bitString);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /**
     * DER-encode an unsigned big-endian integer (prefixing 0x00 when the high bit is set so the
     * value stays positive).
     */
    private function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . $this->derLength(strlen($bytes)) . $bytes;
    }

    /**
     * DER-encode a SEQUENCE wrapping the given contents.
     */
    private function derSequence(string $contents): string
    {
        return "\x30" . $this->derLength(strlen($contents)) . $contents;
    }

    /**
     * DER length encoding (short form below 128, long form otherwise).
     */
    private function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /**
     * Base64URL-decode a JWT segment.
     *
     * @throws \Exception On malformed input
     */
    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \Exception("Invalid base64url encoding in ID token");
        }

        return $decoded;
    }
}
