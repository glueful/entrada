# Entrada (Social Login & SSO) for Glueful

## Overview

Entrada provides enterprise-grade OAuth/OIDC social authentication for the Glueful Framework, enabling seamless integration with major providers. It supports both web-based OAuth flows and native mobile app authentication, with automatic user registration, account linking, and comprehensive security features.

## Features

- ✅ **Multi-Platform Support** - Google, Facebook, GitHub, and Apple Sign In
- ✅ **Dual Authentication Flows** - Web OAuth redirects and native mobile token verification
- ✅ **Security** - OAuth `state` CSRF protection, cryptographic Apple ID-token verification (JWKS / RS256), and verified-email-gated account linking
- ✅ **Automatic User Management** - Registration, verified-email account linking, and profile synchronization
- ✅ **Sign in with Apple** - JWKS-based ID-token signature verification and ES256 client-secret generation
- ✅ **Database Integration** - Social account associations via an indexed `user_uuid` reference (no cross-package FK)
- ✅ **Comprehensive API** - RESTful endpoints with OpenAPI documentation
- ✅ **Health Monitoring** - Built-in diagnostics and configuration validation
- ✅ **Flexible Configuration** - Environment variables and runtime configuration

## Requirements

- PHP 8.3 or higher
- Glueful Framework 1.50.2 or higher
- cURL PHP extension
- OpenSSL PHP extension (for Apple Sign In)

## Installation

### Composer (Recommended)

```bash
composer require glueful/entrada

# Enable it — installing does not auto-load an extension; this adds the provider to
# config/extensions.php's `enabled` list and recompiles the cache.
php glueful extensions:enable entrada

# Run migrations (if not auto-run)
php glueful migrate run
```

In production, manage the `enabled` list in config and run `php glueful extensions:cache` in your deploy step.

Verify status and details:

```bash
php glueful extensions:list
php glueful extensions:info entrada
```

### Local Development Installation

To develop the extension locally, register it as a Composer **path repository** in your app's `composer.json`, then require and enable it:

```jsonc
// composer.json
"repositories": [
    { "type": "path", "url": "extensions/entrada", "options": { "symlink": true } }
]
```

```bash
composer require glueful/entrada:@dev
php glueful extensions:enable entrada
```

Entries in `config/extensions.php` are plain string FQCNs (no `::class`) — prefer `extensions:enable` over editing by hand.

Run the migrations to create the necessary database tables:
```bash
php glueful migrate run
```

Generate API documentation (optional, if your tooling supports it):
```bash
php glueful generate:json doc
```

Restart your web server to apply the changes.

### Verify Installation

Check status and details:

```bash
php glueful extensions:list
php glueful extensions:info entrada
php glueful extensions:diagnose
```

Post-install checklist:

- Run migrations (if not auto-run): `php glueful migrate run`
- Hit an endpoint to verify: `GET /auth/social/google` (should redirect to Google OAuth)
- Rebuild cache after Composer operations: `php glueful extensions:cache`
- Check logs for initialization messages or errors

## Configuration

### Provider Credentials Setup

Obtain OAuth credentials from each provider you want to support:

#### Google OAuth Setup

1. Visit [Google Cloud Console](https://console.cloud.google.com/)
2. Create or select a project
3. Navigate to "APIs & Services" > "Credentials"
4. Create OAuth 2.0 Client ID
5. Add authorized redirect URI: `https://yourdomain.com/auth/social/google/callback`
6. Enable Google+ API and Google People API

#### Facebook OAuth Setup

1. Go to [Facebook Developers](https://developers.facebook.com/)
2. Create a new app or select existing
3. Add Facebook Login product
4. Configure Valid OAuth Redirect URIs: `https://yourdomain.com/auth/social/facebook/callback`
5. Set required permissions: `email`, `public_profile`

#### GitHub OAuth Setup

1. Go to [GitHub Developer Settings](https://github.com/settings/developers)
2. Create new OAuth App
3. Set Authorization callback URL: `https://yourdomain.com/auth/social/github/callback`
4. Configure required scopes: `user:email`, `read:user`

#### Apple Sign In Setup

1. Visit [Apple Developer Account](https://developer.apple.com/)
2. Navigate to "Certificates, Identifiers & Profiles"
3. Create Services ID under Identifiers
4. Enable "Sign in with Apple" capability
5. Configure domain and return URLs
6. Create or reuse a private key (`.p8` file). `APPLE_CLIENT_SECRET` accepts the `.p8` file path,
   an inline PEM, or a pre-built ES256 client-secret JWT (all auto-detected).
7. Set redirect URI: `https://yourdomain.com/auth/social/apple/callback`

### Environment Variables

Configure OAuth credentials in your `.env` file:

```env
# Google OAuth Configuration
GOOGLE_CLIENT_ID=your-google-client-id.googleusercontent.com
GOOGLE_CLIENT_SECRET=your-google-client-secret
GOOGLE_REDIRECT_URI=https://yourdomain.com/auth/social/google/callback

# Facebook OAuth Configuration
FACEBOOK_APP_ID=your-facebook-app-id
FACEBOOK_APP_SECRET=your-facebook-app-secret
FACEBOOK_REDIRECT_URI=https://yourdomain.com/auth/social/facebook/callback

# GitHub OAuth Configuration
GITHUB_CLIENT_ID=your-github-client-id
GITHUB_CLIENT_SECRET=your-github-client-secret
GITHUB_REDIRECT_URI=https://yourdomain.com/auth/social/github/callback

# Facebook Graph API version (optional; default v21.0)
FACEBOOK_API_VERSION=v21.0

# Apple Sign In Configuration
APPLE_CLIENT_ID=com.yourdomain.services.id
# APPLE_CLIENT_SECRET accepts THREE forms (auto-detected): a .p8 key file path (shown),
# an inline PEM private key, or a pre-built ES256 client-secret JWT.
APPLE_CLIENT_SECRET=/path/to/AuthKey_XXXXXXXXXX.p8
APPLE_TEAM_ID=XXXXXXXXXX
APPLE_KEY_ID=XXXXXXXXXX
APPLE_REDIRECT_URI=https://yourdomain.com/auth/social/apple/callback
```

> **`app.url` is required.** When a provider has no explicit `*_REDIRECT_URI` set, Entrada derives the
> default callback URI from the framework's `app.url` config (`APP_URL` in `.env`) — there is **no**
> `HTTP_HOST` fallback (host-header injection hardening, 1.9.0). If `app.url` is unset and you rely on
> the default, the generated `redirect_uri` is a relative path and will fail the provider's registered
> redirect-URI match. Either set `APP_URL` or provide an explicit per-provider redirect URI.

> **Note:** `auto_register` and `sync_profile` are configured in `config/sauth.php` (see below); they
> are not read from environment variables.

### Extension Configuration

Customize behavior in `config/sauth.php`:

```php
return [
    'enabled_providers' => ['google', 'facebook', 'github', 'apple'],
    'auto_register' => true, // auto-create user accounts for new social logins
    'sync_profile' => true,  // sync profile data from social providers

    // Account linking is governed by the verified-email gate (see "Account Linking" below),
    // not a config flag — a social identity is auto-linked to an existing local account only
    // when the provider asserts the email is verified.

    // Provider configurations
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
    ],
    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID'),
        'app_secret' => env('FACEBOOK_APP_SECRET'),
        'redirect_uri' => env('FACEBOOK_REDIRECT_URI'),
        'api_version' => env('FACEBOOK_API_VERSION', 'v21.0'), // Graph API version
    ],
    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect_uri' => env('GITHUB_REDIRECT_URI'),
    ],
    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'client_secret' => env('APPLE_CLIENT_SECRET'),
        'team_id' => env('APPLE_TEAM_ID'),
        'key_id' => env('APPLE_KEY_ID'),
        'redirect_uri' => env('APPLE_REDIRECT_URI'),
    ],
];
```

### Advanced User Provisioning

Entrada now supports configurable social-field mapping, storage-column mapping, and an optional post-registration hook.

#### 1) Field and storage mapping

Use this when your app schema differs from Glueful defaults (for example, custom username/photo columns).

```php
return [
    'field_mapping' => [
        'social' => [
            'uuid' => ['id'],
            'email' => ['email'],
            'username' => ['username', 'login'],
            'first_name' => ['first_name', 'given_name'],
            'last_name' => ['last_name', 'family_name'],
            'photo_url' => ['photo_url', 'picture', 'avatar_url'],
            'email_verified' => ['verified_email', 'email_verified'],
        ],
    ],
    'storage' => [
        'users' => [
            'table' => 'users',
            'columns' => [
                'uuid' => 'uuid',
                'username' => 'username',
                'email' => 'email',
                'password' => 'password',
                'status' => 'status',
                'created_at' => 'created_at',
                'email_verified_at' => 'email_verified_at',
            ],
        ],
        'profiles' => [
            'table' => 'profiles',
            'columns' => [
                'uuid' => 'uuid',
                'user_uuid' => 'user_uuid',
                'first_name' => 'first_name',
                'last_name' => 'last_name',
                'photo_url' => 'photo_url',
            ],
        ],
    ],
];
```

#### 2) Optional post-registration hook (disabled by default)

Use this to run app-specific bootstrap logic after social signup (for example: assign a default role).

```php
return [
    'post_registration' => [
        'enabled' => false, // opt-in
        // Invokable class-string (recommended) or callable
        // Signature: (string $userUuid, array $socialData, ApplicationContext $context): void
        'handler' => App\Auth\SocialRegistrationHandler::class,
    ],
];
```

Recommended handler pattern:

- Keep Entrada generic.
- Put business logic (roles, app profiles, tenant defaults) in your app handler.
- Throw on critical failures when strict behavior is desired.

#### 3) Transaction behavior for social registration

For new social users, Entrada runs the core creation sequence in a DB transaction, then executes app provisioning after commit:

1. Insert user
2. Link social account
3. Commit
4. Run post-registration handler (if enabled)

If step 1 or 2 fails, the transaction is rolled back and signup fails with a generic API error.
If step 4 fails, signup fails with a generic API error, but committed core records remain.

Note: profile synchronization remains best-effort and runs after core creation.

## Usage

### PHP Usage Examples

#### Using Social Login in Controllers

```php
<?php

namespace App\Controllers;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Http\Response;
use Glueful\Extensions\Entrada\Services\SocialAuthService;
use Glueful\Extensions\Entrada\Providers\GoogleAuthProvider;

class AuthController
{
    private SocialAuthService $socialAuth;

    public function __construct()
    {
        $this->socialAuth = container()->get(SocialAuthService::class);
    }

    /**
     * Handle social login initiation
     */
    public function socialLogin(Request $request, string $provider)
    {
        try {
            // Get the provider instance
            $authProvider = $this->socialAuth->getProvider($provider);

            if (!$authProvider) {
                return Response::error("Provider {$provider} not supported", 400);
            }

            // Initiate OAuth flow
            return $authProvider->initiateOAuthFlow($request);

        } catch (\Exception $e) {
            return Response::error("Failed to initiate login: " . $e->getMessage(), 500);
        }
    }

    /**
     * Handle OAuth callback
     */
    public function socialCallback(Request $request, string $provider)
    {
        try {
            $authProvider = $this->socialAuth->getProvider($provider);

            if (!$authProvider) {
                return Response::error("Provider {$provider} not supported", 400);
            }

            // Handle the OAuth callback
            $userData = $authProvider->handleCallback($request);

            if (!$userData) {
                return Response::error($authProvider->getError() ?? "Authentication failed", 401);
            }

            // Generate application tokens
            $tokens = $authProvider->generateTokens($userData);

            return Response::success([
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'user' => $userData
            ], 'Login successful');

        } catch (\Exception $e) {
            return Response::error("Authentication failed: " . $e->getMessage(), 401);
        }
    }
}
```

#### Direct Provider Usage

```php
<?php

use Glueful\Extensions\Entrada\Providers\GoogleAuthProvider;
use Glueful\Extensions\Entrada\Providers\FacebookAuthProvider;
use Glueful\Extensions\Entrada\Providers\GithubAuthProvider;
use Glueful\Extensions\Entrada\Providers\AppleAuthProvider;

// Get a specific provider
$googleProvider = container()->get(GoogleAuthProvider::class);
$facebookProvider = container()->get(FacebookAuthProvider::class);
$githubProvider = container()->get(GithubAuthProvider::class);
$appleProvider = container()->get(AppleAuthProvider::class);

// Verify a native mobile token. Google/Apple take the ID token; Facebook/GitHub take the
// access token. Each provider's verifyNativeToken() takes a single string argument.
$userData = $googleProvider->verifyNativeToken($idToken);

if ($userData) {
    // User authenticated successfully
    $tokens = $googleProvider->generateTokens($userData);
    // Use tokens for your application
}
```

#### Managing Social Accounts

```php
<?php

use Glueful\Extensions\Entrada\Services\SocialAccountService;

class UserSocialAccountController
{
    private SocialAccountService $socialAccountService;

    public function __construct()
    {
        $this->socialAccountService = container()->get(SocialAccountService::class);
    }

    /**
     * Link a social account to existing user
     */
    public function linkSocialAccount(string $userUuid, array $socialData): bool
    {
        return $this->socialAccountService->linkAccountToUser(
            $userUuid,
            $socialData['provider'],
            $socialData['social_id'],
            $socialData['profile_data']
        );
    }

    /**
     * Get all social accounts for a user
     */
    public function getUserSocialAccounts(string $userUuid): array
    {
        return $this->socialAccountService->getUserSocialAccounts($userUuid);
    }

    /**
     * Unlink a social account
     */
    public function unlinkSocialAccount(string $userUuid, string $provider): bool
    {
        return $this->socialAccountService->unlinkAccount($userUuid, $provider);
    }

    /**
     * Check if user has a specific social account
     */
    public function hasSocialAccount(string $userUuid, string $provider): bool
    {
        $accounts = $this->socialAccountService->getUserSocialAccounts($userUuid);
        return collect($accounts)->where('provider', $provider)->isNotEmpty();
    }
}
```

#### Custom Integration in Services

```php
<?php

namespace App\Services;

use Glueful\Extensions\Entrada\Services\SocialAuthService;
use Glueful\Auth\TokenManager;

class CustomAuthService
{
    private SocialAuthService $socialAuth;

    public function __construct()
    {
        $this->socialAuth = container()->get(SocialAuthService::class);
    }

    /**
     * Authenticate user with social provider tokens
     */
    public function authenticateWithSocial(string $provider, array $tokens): ?array
    {
        $authProvider = $this->socialAuth->getProvider($provider);

        if (!$authProvider) {
            throw new \InvalidArgumentException("Unknown provider: {$provider}");
        }

        // Verify tokens based on provider. verifyNativeToken() takes one argument: the ID token
        // for Google/Apple, the access token for Facebook/GitHub.
        switch ($provider) {
            case 'google':
            case 'apple':
                $userData = $authProvider->verifyNativeToken($tokens['id_token'] ?? '');
                break;

            case 'facebook':
            case 'github':
                $userData = $authProvider->verifyNativeToken($tokens['access_token'] ?? '');
                break;

            default:
                $userData = null;
        }

        if (!$userData) {
            return null;
        }

        // Generate application JWT tokens
        return TokenManager::generateTokenPair($userData);
    }

    /**
     * Get or create user from social data
     */
    public function findOrCreateSocialUser(array $socialData): array
    {
        return $this->socialAuth->findOrCreateUser($socialData);
    }
}
```

#### Middleware for Social Authentication

```php
<?php

namespace App\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Http\Response;
use Glueful\Extensions\Entrada\Services\SocialAuthService;

class SocialAuthMiddleware
{
    private SocialAuthService $socialAuth;

    public function __construct()
    {
        $this->socialAuth = container()->get(SocialAuthService::class);
    }

    public function handle(Request $request, \Closure $next)
    {
        // Check for social auth token in header
        $authHeader = $request->headers->get('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return $next($request);
        }

        $token = substr($authHeader, 7);

        // Validate token with social providers
        foreach ($this->socialAuth->getEnabledProviders() as $provider) {
            $authProvider = $this->socialAuth->getProvider($provider);

            if ($authProvider->canHandleToken($token)) {
                if ($authProvider->validateToken($token)) {
                    // Token is valid, continue
                    return $next($request);
                }

                // Token is invalid
                return Response::error('Invalid social auth token', 401);
            }
        }

        // Not a social auth token, continue
        return $next($request);
    }
}
```

#### Event Listeners for Social Login

```php
<?php

namespace App\Listeners;

use Glueful\Extensions\Entrada\Events\SocialLoginEvent;
use Glueful\Extensions\Entrada\Events\SocialAccountLinkedEvent;

class SocialAuthEventListener
{
    /**
     * Handle social login event
     */
    public function onSocialLogin(SocialLoginEvent $event): void
    {
        $user = $event->getUser();
        $provider = $event->getProvider();
        $socialData = $event->getSocialData();

        // Log the social login
        logger()->info("User {$user['uuid']} logged in via {$provider}", [
            'provider' => $provider,
            'social_id' => $socialData['id'] ?? null,
            'email' => $user['email'] ?? null
        ]);

        // Update last login timestamp
        db()->table('users')
            ->where('uuid', $user['uuid'])
            ->update(['last_login_at' => now()]);

        // Sync profile data if needed
        if (config('sauth.sync_profile')) {
            $this->syncUserProfile($user['uuid'], $socialData);
        }
    }

    /**
     * Handle social account linked event
     */
    public function onSocialAccountLinked(SocialAccountLinkedEvent $event): void
    {
        $userUuid = $event->getUserUuid();
        $provider = $event->getProvider();

        // Send notification to user
        notification()->send($userUuid, [
            'type' => 'social_account_linked',
            'message' => "Your {$provider} account has been linked successfully",
            'provider' => $provider
        ]);
    }

    private function syncUserProfile(string $userUuid, array $socialData): void
    {
        $updates = [];

        if (!empty($socialData['name'])) {
            $updates['name'] = $socialData['name'];
        }

        if (!empty($socialData['picture'])) {
            $updates['avatar_url'] = $socialData['picture'];
        }

        if (!empty($updates)) {
            db()->table('users')
                ->where('uuid', $userUuid)
                ->update($updates);
        }
    }
}
```

### Web-Based OAuth Flow

For traditional web applications, use redirect-based OAuth:

```html
<!-- Social login buttons -->
<div class="social-login-buttons">
    <a href="/auth/social/google" class="btn btn-google">
        <i class="fab fa-google"></i> Sign in with Google
    </a>
    <a href="/auth/social/facebook" class="btn btn-facebook">
        <i class="fab fa-facebook-f"></i> Sign in with Facebook
    </a>
    <a href="/auth/social/github" class="btn btn-github">
        <i class="fab fa-github"></i> Sign in with GitHub
    </a>
    <a href="/auth/social/apple" class="btn btn-apple">
        <i class="fab fa-apple"></i> Sign in with Apple
    </a>
</div>
```

### Native Mobile App Flow

For mobile applications, use direct token verification:

```javascript
// Example: React Native with Google Sign In
import { GoogleSignin } from '@react-native-google-signin/google-signin';

// Configure Google Sign In
GoogleSignin.configure({
  webClientId: 'your-google-client-id.googleusercontent.com',
});

// Sign in and get tokens
const signIn = async () => {
  try {
    await GoogleSignin.hasPlayServices();
    const { idToken, accessToken } = await GoogleSignin.signIn();
    
    // Send tokens to your Glueful backend
    const response = await fetch('https://yourapi.com/auth/social/google', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        id_token: idToken,
        access_token: accessToken,
      }),
    });
    
    const result = await response.json();
    // Handle authentication result
  } catch (error) {
    console.error('Sign in failed:', error);
  }
};
```

### Backend Token Verification

```php
// For native mobile apps, POST tokens directly
POST /auth/social/google
Content-Type: application/json

{
    "id_token": "google-id-token-here",
    "access_token": "google-access-token-here"
}

// Response
{
    "success": true,
    "message": "Authentication successful",
    "data": {
        "access_token": "your-app-jwt-token",
        "refresh_token": "your-app-refresh-token",
        "user": {
            "uuid": "user-uuid",
            "email": "user@example.com",
            "name": "User Name"
        },
        "social_account": {
            "uuid": "social-account-uuid",
            "provider": "google",
            "social_id": "google-user-id"
        }
    }
}
```

## API Endpoints

The extension provides comprehensive REST API endpoints:

### Authentication Endpoints

```php
// Web OAuth Flow
GET  /auth/social/{provider}              // Initiate OAuth flow
GET  /auth/social/{provider}/callback     // OAuth callback handler

// Native App Flow
POST /auth/social/{provider}              // Direct token verification

// Apple-specific (supports both GET and POST)
GET  /auth/social/apple                   // Apple OAuth initiation
POST /auth/social/apple/callback          // Apple callback (POST only)
```

All public endpoints above are rate limited per-IP (native POSTs 10/min; init + callbacks 20/min).
`POST /auth/social/apple` additionally accepts an optional `nonce` field for native replay protection
(see [Native nonce binding](#native-nonce-binding-replay-protection)).

### Account Management Endpoints

```php
// Social account management
GET    /user/social-accounts              // List connected social accounts
DELETE /user/social-accounts/{uuid}       // Unlink social account

// User profile operations
GET    /user/profile                      // Get user profile
PUT    /user/profile                      // Update user profile
```

### Example API Usage

```bash
# List connected social accounts
curl -H "Authorization: Bearer your-jwt-token" \
     https://yourapi.com/user/social-accounts

# Unlink a social account
curl -X DELETE \
     -H "Authorization: Bearer your-jwt-token" \
     https://yourapi.com/user/social-accounts/social-account-uuid
```

## Advanced Features

### Automatic User Registration

When `auto_register` is enabled, the extension automatically creates user accounts:

```php
// User creation process
1. Verify social provider token/ID token
2. Extract user profile information
3. Check if user exists by email
4. Create new user if not exists
5. Create social account association
6. Generate application JWT tokens
7. Return authentication response
```

### Account Linking

When a social login matches an existing local account by email, Entrada auto-links the social
identity **only if the provider asserts the email is verified** (the verified-email gate — there is
no `link_accounts` config flag; the gate is the control). If the email is unverified, the login is
refused with a `409` and the user must sign in and link the provider explicitly from their account
settings. This prevents account takeover via an attacker-controlled unverified email at the provider.

```php
// Account linking process
1. User authenticates with social provider
2. System finds existing user by email
3. If the provider reports the email as VERIFIED → link the social account
   (if UNVERIFIED → reject with 409; the user must link manually after signing in)
4. Updates profile if sync_profile is enabled
5. Returns authentication tokens
```

#### Provider verified-email matrix

Each provider asserts email verification differently; the matrix below is what drives the
auto-link gate (and whether new users get `email_verified_at` stamped):

| Provider | Verified email asserted? | How |
|----------|--------------------------|-----|
| **Google** | Yes | OIDC `email_verified` claim from the ID token / tokeninfo. |
| **GitHub** | Yes | Resolved authoritatively from the `/user/emails` endpoint (primary + verified preferred). The public profile email alone is **not** trusted. |
| **Apple** | Yes | Apple verifies all emails; the `email_verified` claim is propagated from the verified ID token. |
| **Facebook** | No | The Graph API exposes no per-email verified flag, so Facebook emails are treated as **unverified** — email-match linking always requires a manual link. |

### Profile Synchronization

Automatically sync user profiles from social providers:

```php
// Synced profile fields
- Name (first_name, last_name, display_name)
- Email address
- Profile photo URL
- Social provider ID
- Additional provider-specific data
```

### Apple Sign In Specifics

The extension includes advanced Apple Sign In support:

#### ID-token signature verification

Apple ID tokens are verified cryptographically before any claim is trusted: the RSA public key
is reconstructed from Apple's JWKS (`https://appleid.apple.com/auth/keys`), the RS256 signature
over the token is checked with `openssl_verify`, the algorithm is pinned to `RS256` (rejecting
`alg: none` and RS/HS confusion), and `iss`/`aud`/`exp` are asserted. This happens automatically
inside `verifyNativeToken()` and the web callback — both share one verified-claims path; callers
never decode an unverified token themselves.

The bundled `ASN1Parser` is **only** a DER reader used when **generating** the Apple client-secret
JWT — it performs the DER→JOSE conversion of the ES256 signature during client-secret signing. It is
**not** part of ID-token validation: incoming ID tokens are verified RS256 against Apple's JWKS (above).

**JWKS caching:** Apple's JWKS (`/auth/keys`) is cached automatically in the framework cache store
(1-hour TTL) so it stays out of the critical path of every login. On a `kid` miss (key rotation) it
does a single bypass-refetch; failed/malformed responses are never cached, and it degrades gracefully
to a direct fetch when no cache is available.

**Client-secret forms:** `APPLE_CLIENT_SECRET` accepts three forms, auto-detected structurally — a
`.p8` key-file path, an inline PEM private key, or a pre-built ES256 client-secret JWT (used verbatim).

#### Native nonce binding (replay protection)

`POST /auth/social/apple` accepts an optional `nonce` field. When a native client supplies the raw
nonce it bound to its Sign in with Apple SDK request, Entrada requires the verified ID token's `nonce`
claim to match (`sha256(rawNonce)` per the Apple SDK convention, or the raw value), closing the
captured-token replay window. Clients that send no nonce are unaffected. **Native clients are
recommended to supply a nonce.**

#### Apple-Specific Considerations

- **First-Time Data**: Apple only provides name and email on first authentication
- **Privacy**: Users can choose to hide their email (Apple provides a proxy email)
- **JWT Validation**: Requires complex signature verification with Apple's rotating keys

## Database Schema

The extension creates a `social_accounts` table:

```sql
CREATE TABLE social_accounts (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    uuid CHAR(12) NOT NULL UNIQUE,
    user_uuid CHAR(12) NOT NULL,
    provider VARCHAR(50) NOT NULL,
    social_id VARCHAR(255) NOT NULL,
    profile_data TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- user_uuid is an indexed logical reference to users.uuid (owned by glueful/users);
    -- no cross-package FK (Phase 5 decoupling — integrity enforced at the service layer)
    UNIQUE KEY unique_provider_social (provider, social_id),
    INDEX idx_user_uuid (user_uuid),
    INDEX idx_provider (provider)
);
```

## Security Features

### CSRF Protection

Every OAuth flow is protected by a `state` parameter. A per-provider token is generated and stored
in the session when the flow is initiated, then validated on the callback: it is compared with
`hash_equals()`, is single-use (cleared on every callback), and a missing or mismatched value
rejects the callback with a 401 before any authorization code is exchanged. This is automatic — no
caller action is required.

### Rate Limiting

All public social-auth endpoints are rate limited per-IP (sliding window):

- **Native token POSTs** (`POST /auth/social/{provider}`) — 10 requests/min (token-grinding surface).
- **OAuth callbacks and init redirects** (`GET /auth/social/{provider}`, `.../callback`) — 20 requests/min.
- **Unlink** (`DELETE /user/social-accounts/{uuid}`) — 10 requests/min.

> **Implementation note:** route rate limiting in Glueful requires the `->rateLimit(n, minutes)`
> builder **plus** `->middleware(['rate_limit'])`. The middleware-string form (`rate_limit:10,60`)
> is a silent no-op — the framework's limiter ignores string params and reads only the builder
> config. If you add your own rate-limited routes, use the builder form.

### Verified-email session claims

The provider's verified-email status flows end to end: a verified email stamps `email_verified_at`
on the user row, and that drives `email_verified: true` in the session / OIDC claims reported back
to the client (previously always `false`). See the provider verified-email matrix above for which
providers assert verification.

### `profile_data` privacy

The `social_accounts.profile_data` column stores only a minimal identity allowlist
(`id`, `email`, `name`, `first_name`, `last_name`, `username`, `picture`, `email_verified`) — never
the full raw provider payload. Nothing in the extension reads the column back, so this is pure
exposure reduction.

### JWT Token Management

Secure token generation and validation:

```php
// Uses Glueful's TokenManager for secure JWT tokens
$tokens = $this->tokenManager->generateTokenPair($userUuid, [
    'social_provider' => $provider,
    'social_id' => $socialId
]);
```

### Secure Configuration

- Environment variable configuration
- Encrypted client secrets storage
- Secure OAuth redirect validation
- Provider token verification

## Provider-Specific Implementation

### Google Provider

```php
// Supports both OAuth flow and ID token verification
- OAuth 2.0 with OpenID Connect
- ID token validation with Google's public keys
- Access token verification via Google API
- Profile data from Google People API
```

### Facebook Provider

```php
// Facebook Graph API integration
- OAuth 2.0 flow
- Access token validation via Facebook Graph API
- Profile data extraction
- Long-lived token generation
```

> **Graph API version:** configurable via `FACEBOOK_API_VERSION` / `sauth.facebook.api_version`
> (default `v21.0`). Facebook emails are treated as **unverified** (the Graph API exposes no
> per-email verified flag), so email-match account linking always requires a manual link.

### GitHub Provider

```php
// GitHub OAuth implementation
- OAuth 2.0 with required scopes
- Access token validation
- User profile via GitHub API
- Verified email resolved authoritatively from /user/emails (primary + verified)
```

> **PKCE:** GitHub OAuth Apps do not support PKCE, so the GitHub code flow relies on the OAuth
> `state` parameter for request binding (rather than a `code_verifier`).

### Apple Provider

```php
// Advanced Apple Sign In implementation
- OAuth 2.0 with Sign In with Apple
- ID-token signature verification via Apple's JWKS (RS256 / openssl)
- ES256 private-key JWT generation for client secrets (DER via ASN1Parser)
- First-time user data handling
```

## Monitoring and Troubleshooting

### Health Monitoring

Monitor extension health:

```php
// Resolve a provider and check basic availability
$google = container()->get(Glueful\Extensions\Entrada\Providers\GoogleAuthProvider::class);
// e.g., dump config/redirect URI or perform a light request
```

### Common Issues

1. **OAuth Redirect Mismatch**
   - Ensure redirect URIs match exactly in provider settings
   - Use HTTPS for production environments

2. **Invalid Client Credentials**
   - Verify client ID and secret are correct
   - Check environment variable names

3. **Apple Sign In Issues**
   - Ensure private key file is readable
   - Verify Team ID and Key ID are correct
   - Check domain registration with Apple

4. **Token Validation Failures**
   - Verify system time is synchronized
   - Check internet connectivity for provider APIs
   - Ensure required PHP extensions are installed

### Debug Mode

Enable detailed logging:

```env
APP_DEBUG=true
```

### Health Checks

```bash
# Check social login system health
curl -H "Authorization: Bearer your-token" \
     http://your-domain.com/health/social-login
```

## Migration and Integration

### Existing User Migration

Migrate existing users to social login:

```php
// Link existing users to social accounts
$existingUser = $userRepository->findByEmail($socialEmail);
if ($existingUser) {
    $socialAccountService->linkAccountToUser(
        $existingUser->getUuid(),
        $provider,
        $socialId,
        $profileData
    );
}
```

### Custom Provider Implementation

Extend the system with custom providers:

```php
use Glueful\Extensions\Entrada\Providers\AbstractSocialProvider;

class CustomProvider extends AbstractSocialProvider
{
    public function getAuthorizationUrl(array $scopes = []): string
    {
        // Implement OAuth authorization URL generation
    }
    
    public function validateToken(string $token): array
    {
        // Implement token validation logic
    }
    
    public function getUserProfile(string $accessToken): array
    {
        // Implement user profile retrieval
    }
}
```

## Performance Considerations

- **Connection Pooling**: HTTP clients use connection pooling for provider APIs
- **JWKS Caching**: Apple's JWKS is cached in the framework cache store (1-hour TTL) so it stays out of the per-login critical path; a `kid` miss triggers a single refetch for key rotation
- **Database Optimization**: Indexed social accounts table for fast lookups

## License

This extension is licensed under the MIT License.

## Support

For issues, feature requests, or questions about the SocialLogin extension:
- Create an issue in the repository
- Consult the Glueful documentation
- Check provider-specific documentation for OAuth setup
- Use the built-in health monitoring for diagnostics
