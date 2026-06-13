<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Providers;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\Request;
use Glueful\Auth\Interfaces\AuthenticationProviderInterface;
use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Auth\TokenManager;
use Glueful\Auth\JWTService;
use Glueful\Helpers\Utils;
use Glueful\Database\Connection;

/**
 * Abstract Social Authentication Provider
 *
 * Base class for all social authentication providers.
 * Implements common functionality and defines the contract
 * for provider-specific implementations.
 */
abstract class AbstractSocialProvider implements AuthenticationProviderInterface
{
    protected string $providerName;
    protected ?string $lastError = null;
    protected int $lastErrorStatusCode = 401;
    protected UserProviderInterface $users;
    protected Connection $db;
    protected ApplicationContext $context;

    public function __construct(ApplicationContext $context)
    {
        $this->context = $context;
        // Resolve the framework identity seam (bound by glueful/users; core NullUserProvider
        // fallback). Used for read lookups; user CREATION uses the raw config-driven writes below,
        // since the seam is read-only. The concrete Glueful\Repository\UserRepository was removed
        // from the framework when the user store was extracted into glueful/users.
        $this->users = $context->getContainer()->get(UserProviderInterface::class);
        $this->db = $context->getContainer()->get('database');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function authenticate(Request $request): ?array
    {
        try {
            if ($this->isOAuthCallback($request)) {
                return $this->handleCallback($request);
            }

            if ($this->isOAuthInitRequest($request)) {
                $this->initiateOAuthFlow($request);
                return null;
            }
        } catch (\Exception $e) {
            // Record the failure on the provider; the controller's providerFailureResponse() is the
            // single place that logs it (it has request context). Logging here too would double-log.
            $this->lastError = "Authentication error: " . $e->getMessage();
            $this->lastErrorStatusCode = 500;
            return null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $userData
     */
    public function isAdmin(array $userData): bool
    {
        if (!isset($userData['uuid'])) {
            return false;
        }
        $roles = $userData['roles'] ?? [];
        return in_array('superuser', $roles, true);
    }

    public function getError(): ?string
    {
        return $this->lastError;
    }

    public function getErrorStatusCode(): int
    {
        return $this->lastErrorStatusCode;
    }

    public function validateToken(string $token): bool
    {
        return JWTService::verify($token);
    }

    public function canHandleToken(string $token): bool
    {
        // Access JWTs are intentionally provider-agnostic in the new session model.
        // Provider routing for refresh comes from persisted session state.
        return false;
    }

    /**
     * @param array<string, mixed> $userData
     * @return array<string, mixed>
     */
    public function generateTokens(
        array $userData,
        ?int $accessTokenLifetime = null,
        ?int $refreshTokenLifetime = null
    ): array {

        $userData['provider'] = $this->providerName;
        return $this->getTokenManager()->generateTokenPair($userData, $accessTokenLifetime, $refreshTokenLifetime);
    }

    /**
     * @param array<string, mixed> $sessionData
     * @return array<string, mixed>|null
     */
    public function refreshTokens(string $refreshToken, array $sessionData): ?array
    {
        try {
            $payload = $sessionData;
            $payload['provider'] = $this->providerName;
            $payload['refresh_token'] = $refreshToken;

            if (!isset($payload['uuid']) || !is_string($payload['uuid']) || $payload['uuid'] === '') {
                $this->lastError = 'Missing user uuid in session data';
                return null;
            }

            // Avoid recursive provider refresh loops by issuing a fresh pair directly.
            return $this->getTokenManager()->generateTokenPair($payload);
        } catch (\Throwable $e) {
            $this->lastError = "Token refresh error: " . $e->getMessage();
            return null;
        }
    }

    /**
     * @param array<string, mixed> $socialData
     * @return array<string, mixed>|null
     */
    protected function findOrCreateUser(array $socialData): ?array
    {
        $this->lastError = null;
        $this->lastErrorStatusCode = 401;

        $socialId = (string)($this->extractSocialValue($socialData, 'uuid') ?? '');
        if ($socialId === '') {
            $this->lastError = 'Social profile is missing provider user id';
            $this->lastErrorStatusCode = 400;
            return null;
        }

        $existingUser = $this->findUserBySocialId(
            $this->providerName,
            $socialId
        );

        if ($existingUser) {
            $this->syncUserProfileFromSocial($existingUser, $socialData);
            return $this->formatUserData($existingUser);
        }

        $email = $this->extractSocialValue($socialData, 'email');
        if (!empty($email) && is_string($email)) {
            $emailUser = $this->findUserByEmail($email);
            if ($emailUser) {
                // Only auto-link a social identity to an existing account when the provider
                // asserts the email is verified. Linking on an unverified email would let an
                // attacker who can set an arbitrary (unverified) email at the provider take over
                // the matching local account (pre-account-takeover). When the email is not
                // verified, refuse rather than link or silently create a duplicate — the user
                // must sign in and link the provider explicitly from their account settings.
                if (!$this->isVerifiedFlag($this->extractSocialValue($socialData, 'email_verified'))) {
                    $this->lastError = 'An account with this email already exists. Sign in and '
                        . 'link your ' . $this->providerName . ' account from your account settings.';
                    $this->lastErrorStatusCode = 409;
                    return null;
                }

                $emailUserUuid = $this->userValue($emailUser, 'uuid');
                if (!is_string($emailUserUuid) || $emailUserUuid === '') {
                    $this->lastError = 'Matched user is missing UUID';
                    $this->lastErrorStatusCode = 500;
                    return null;
                }

                $this->linkSocialAccount(
                    $emailUserUuid,
                    $this->providerName,
                    $socialId,
                    $socialData
                );
                $this->syncUserProfileFromSocial($emailUser, $socialData);
                return $this->formatUserData($emailUser);
            }
        }

        $config = config($this->context, 'sauth', []);
        if (!($config['auto_register'] ?? true)) {
            $this->lastError = "Auto-registration is disabled and no matching user found";
            $this->lastErrorStatusCode = 403;
            return null;
        }

        return $this->createUserFromSocial($socialData);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findUserBySocialId(string $provider, string $socialId): ?array
    {
        $result = $this->db->table('social_accounts')
            ->select(['user_uuid'])
            ->where('provider', $provider)
            ->where('social_id', $socialId)
            ->limit(1)
            ->get();

        if (empty($result)) {
            return null;
        }

        return $this->findUserByUuid((string)$result[0]['user_uuid']);
    }

    /**
     * @param array<string, mixed> $userData
     */
    protected function linkSocialAccount(
        string $userUuid,
        string $provider,
        string $socialId,
        array $userData
    ): bool {
        $existing = $this->db->table('social_accounts')
            ->select(['uuid'])
            ->where('user_uuid', $userUuid)
            ->where('provider', $provider)
            ->where('social_id', $socialId)
            ->limit(1)
            ->get();

        $profileData = json_encode($this->filterProfileData($userData));

        if (!empty($existing)) {
            return $this->db->table('social_accounts')
                ->where('uuid', $existing[0]['uuid'])
                ->update([
                    'profile_data' => $profileData,
                    'updated_at' => date('Y-m-d H:i:s')
                ]) > 0;
        }

        $result = $this->db->table('social_accounts')->insert([
            'uuid' => Utils::generateNanoID(),
            'user_uuid' => $userUuid,
            'provider' => $provider,
            'social_id' => $socialId,
            'profile_data' => $profileData,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return (bool)$result;
    }

    /**
     * Reduce a provider profile payload to a fixed allowlist before it is persisted in
     * `social_accounts.profile_data`.
     *
     * The raw provider response (under `raw`) and any unanticipated upstream fields are dropped:
     * nothing in this extension reads `profile_data` back, so persisting the full payload only
     * stores extra PII (locale, full picture URLs, future fields) with no benefit. Only a small,
     * stable set of identity fields is kept for operator inspection/debugging.
     *
     * @param array<string, mixed> $userData
     * @return array<string, mixed>
     */
    private function filterProfileData(array $userData): array
    {
        $allowed = [
            'id',
            'email',
            'name',
            'first_name',
            'last_name',
            'username',
            'picture',
            'email_verified',
        ];

        $filtered = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $userData)) {
                $filtered[$key] = $userData[$key];
            }
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    protected function formatUserData(array $user): array
    {
        $uuid = $this->userValue($user, 'uuid');
        $email = $this->userValue($user, 'email');
        $name = $this->userValue($user, 'username');

        // Carry the verified-email status forward from the persisted row. The column is resolved
        // through the same storage-config mapping the rest of the class uses (storage.users.columns
        // -> email_verified_at), so a non-null timestamp means the email is verified. A missing key
        // fails closed to unverified. The raw timestamp is preserved under its mapped column name
        // because the framework's TokenManager::createUserSession() derives the OIDC user's
        // email_verified flag from email_verified_at (not from this bool), and that OIDC user is
        // what SocialAuthController reports back to the client.
        $emailVerifiedAt = $this->userValue($user, 'email_verified_at');
        $emailVerified = $emailVerifiedAt !== null && $emailVerifiedAt !== '';

        $userConfig = $this->getStorageConfig('users');
        $columns = is_array($userConfig['columns'] ?? null) ? $userConfig['columns'] : [];
        $emailVerifiedAtColumn = (string)($columns['email_verified_at'] ?? 'email_verified_at');

        return [
            'uuid' => $uuid,
            'email' => $email,
            'name' => $name,
            'email_verified' => $emailVerified,
            $emailVerifiedAtColumn => $emailVerifiedAt,
            'roles' => $user['roles'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $socialData
     * @return array<string, mixed>|null
     */
    protected function createUserFromSocial(array $socialData): ?array
    {
        $userConfig = $this->getStorageConfig('users');
        $table = (string)($userConfig['table'] ?? 'users');
        $columns = is_array($userConfig['columns'] ?? null) ? $userConfig['columns'] : [];
        $defaults = is_array($userConfig['defaults'] ?? null) ? $userConfig['defaults'] : [];

        $uuidColumn = (string)($columns['uuid'] ?? 'uuid');
        $usernameColumn = (string)($columns['username'] ?? 'username');
        $emailColumn = (string)($columns['email'] ?? 'email');
        $createdAtColumn = (string)($columns['created_at'] ?? 'created_at');
        $passwordColumn = (string)($columns['password'] ?? 'password');
        $statusColumn = (string)($columns['status'] ?? 'status');
        $emailVerifiedAtColumn = (string)($columns['email_verified_at'] ?? 'email_verified_at');

        $username = $this->resolveUsername($socialData);
        $email = $this->extractSocialValue($socialData, 'email');
        $verified = $this->extractSocialValue($socialData, 'email_verified');

        $email = is_string($email) ? $email : null;

        $userUuid = Utils::generateNanoID();

        $userData = [
            $uuidColumn => $userUuid,
            $usernameColumn => $username,
            $emailColumn => $email,
            $createdAtColumn => date('Y-m-d H:i:s'),
        ];

        if (array_key_exists('password', $columns) || array_key_exists('password', $defaults)) {
            $userData[$passwordColumn] = $defaults['password'] ?? null;
        }

        if (array_key_exists('status', $columns) || array_key_exists('status', $defaults)) {
            $userData[$statusColumn] = $defaults['status'] ?? 'active';
        }

        if (
            $this->isVerifiedFlag($verified)
            && (array_key_exists('email_verified_at', $columns) || array_key_exists('email_verified_at', $defaults))
        ) {
            $userData[$emailVerifiedAtColumn] = date('Y-m-d H:i:s');
        }

        $socialId = (string)($this->extractSocialValue($socialData, 'uuid') ?? '');
        try {
            $this->db->transaction(function () use ($table, $userData, $userUuid, $socialId, $socialData): void {
                $saved = $this->db->table($table)->insert($userData);
                if (!$saved) {
                    throw new \RuntimeException('Failed to create user account');
                }

                if ($socialId !== '') {
                    $linked = $this->linkSocialAccount($userUuid, $this->providerName, $socialId, $socialData);
                    if (!$linked) {
                        throw new \RuntimeException('Failed to link social account');
                    }
                }
            });
        } catch (\Throwable $e) {
            error_log("[{$this->providerName}] User creation failed: " . $e->getMessage());
            $this->lastError = 'Failed to create user account';
            $this->lastErrorStatusCode = 500;
            return null;
        }

        $createdUser = $this->findUserByUuid($userUuid);
        if ($createdUser !== null) {
            $this->syncUserProfileFromSocial($createdUser, $socialData);
        }

        // Run app-specific provisioning after commit so handlers that resolve
        // their own DB connections can see the newly created user row.
        try {
            $this->runPostRegistrationHandler($userUuid, $socialData);
        } catch (\Throwable $e) {
            error_log("[{$this->providerName}] User provisioning failed: " . $e->getMessage());
            $this->lastError = 'Failed to complete account setup';
            $this->lastErrorStatusCode = 500;
            return null;
        }

        if ($createdUser !== null) {
            return $this->formatUserData($createdUser);
        }

        return $this->formatUserData($userData);
    }

    /**
     * Resolve a unique username for social-auth user creation.
     *
     * Priority:
     * 1) extracted username from provider payload
     * 2) generated from first/given name + family initial
     * 3) generated from email local-part
     * 4) fallback random user_<id>
     *
     * @param array<string, mixed> $socialData
     */
    protected function resolveUsername(array $socialData): string
    {
        $preferred = '';

        if (!empty($socialData['username']) && is_string($socialData['username'])) {
            $preferred = $socialData['username'];
        } else {
            $preferred = $this->generateUsername($socialData);
        }

        $base = $this->sanitizeUsername($preferred);
        if ($base === '') {
            $base = 'user';
        }

        if (strlen($base) < 3) {
            $base = str_pad($base, 3, 'x');
        }

        // Keep room for numeric suffix.
        $base = substr($base, 0, 24);

        if (!$this->usernameExists($base)) {
            return $base;
        }

        for ($i = 1; $i <= 9999; $i++) {
            $suffix = (string) $i;
            $candidate = substr($base, 0, 24 - strlen($suffix)) . $suffix;
            if (!$this->usernameExists($candidate)) {
                return $candidate;
            }
        }

        return 'user' . substr(strtolower(Utils::generateNanoID()), 0, 8);
    }

    /**
     * Default username generation strategy.
     *
     * For providers that don't supply username directly:
     * - first/given name + first letter of family/last name
     * - else email local-part
     *
     * @param array<string, mixed> $socialData
     */
    protected function generateUsername(array $socialData): string
    {
        $firstName = '';
        $lastInitial = '';

        if (!empty($socialData['given_name']) && is_string($socialData['given_name'])) {
            $firstName = $socialData['given_name'];
        } elseif (!empty($socialData['first_name']) && is_string($socialData['first_name'])) {
            $firstName = $socialData['first_name'];
        }

        if (!empty($socialData['family_name']) && is_string($socialData['family_name'])) {
            $lastInitial = substr(trim($socialData['family_name']), 0, 1);
        } elseif (!empty($socialData['last_name']) && is_string($socialData['last_name'])) {
            $lastInitial = substr(trim($socialData['last_name']), 0, 1);
        }

        if ($firstName !== '') {
            return $firstName . $lastInitial;
        }

        if (!empty($socialData['email']) && is_string($socialData['email'])) {
            $emailParts = explode('@', $socialData['email']);
            if (!empty($emailParts[0])) {
                return $emailParts[0];
            }
        }

        return 'user_' . substr(strtolower(Utils::generateNanoID()), 0, 8);
    }

    private function sanitizeUsername(string $username): string
    {
        $username = strtolower(trim($username));
        $username = preg_replace('/[^a-z0-9_]/', '', $username) ?? '';
        return $username;
    }

    private function usernameExists(string $username): bool
    {
        $userConfig = $this->getStorageConfig('users');
        $table = (string)($userConfig['table'] ?? 'users');
        $columns = is_array($userConfig['columns'] ?? null) ? $userConfig['columns'] : [];

        $usernameColumn = (string)($columns['username'] ?? 'username');
        $uuidColumn = (string)($columns['uuid'] ?? 'uuid');

        $existing = $this->db->table($table)
            ->select([$uuidColumn])
            ->where($usernameColumn, $username)
            ->limit(1)
            ->get();

        return !empty($existing);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getSauthConfig(): array
    {
        $config = config($this->context, 'sauth', []);
        return is_array($config) ? $config : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function getStorageConfig(string $entity): array
    {
        $config = $this->getSauthConfig();
        $storage = $config['storage'] ?? [];

        if (!is_array($storage) || !is_array($storage[$entity] ?? null)) {
            return [];
        }

        return $storage[$entity];
    }

    /**
     * @return array<string, mixed>
     */
    private function getFieldMappingConfig(): array
    {
        $config = $this->getSauthConfig();
        $mapping = $config['field_mapping'] ?? [];
        return is_array($mapping) ? $mapping : [];
    }

    /**
     * Resolve canonical field value from social payload via configurable aliases.
     *
     * @param array<string, mixed> $socialData
     */
    private function extractSocialValue(array $socialData, string $canonicalKey): mixed
    {
        $mapping = $this->getFieldMappingConfig();
        $socialMap = is_array($mapping['social'] ?? null) ? $mapping['social'] : [];
        $aliases = $socialMap[$canonicalKey] ?? [$canonicalKey];

        if (!is_array($aliases)) {
            $aliases = [$aliases];
        }

        foreach ($aliases as $alias) {
            if (!is_string($alias) || $alias === '') {
                continue;
            }

            if (array_key_exists($alias, $socialData) && $socialData[$alias] !== null) {
                return $socialData[$alias];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function userValue(array $user, string $canonicalKey): mixed
    {
        $userConfig = $this->getStorageConfig('users');
        $columns = is_array($userConfig['columns'] ?? null) ? $userConfig['columns'] : [];
        $mappedColumn = (string)($columns[$canonicalKey] ?? $canonicalKey);

        if (array_key_exists($mappedColumn, $user)) {
            return $user[$mappedColumn];
        }

        if (array_key_exists($canonicalKey, $user)) {
            return $user[$canonicalKey];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findUserByEmail(string $email): ?array
    {
        $userConfig = $this->getStorageConfig('users');
        $table = (string)($userConfig['table'] ?? 'users');
        $columns = is_array($userConfig['columns'] ?? null) ? $userConfig['columns'] : [];
        $emailColumn = (string)($columns['email'] ?? 'email');

        $result = $this->db->table($table)
            ->select(['*'])
            ->where($emailColumn, $email)
            ->limit(1)
            ->get();

        return !empty($result) ? $result[0] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findUserByUuid(string $uuid): ?array
    {
        $userConfig = $this->getStorageConfig('users');
        $table = (string)($userConfig['table'] ?? 'users');
        $columns = is_array($userConfig['columns'] ?? null) ? $userConfig['columns'] : [];
        $uuidColumn = (string)($columns['uuid'] ?? 'uuid');

        $result = $this->db->table($table)
            ->select(['*'])
            ->where($uuidColumn, $uuid)
            ->limit(1)
            ->get();

        return !empty($result) ? $result[0] : null;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $socialData
     */
    private function syncUserProfileFromSocial(array $user, array $socialData): void
    {
        $config = $this->getSauthConfig();
        if (!($config['sync_profile'] ?? true)) {
            return;
        }

        $profileConfig = $this->getStorageConfig('profiles');
        $table = (string)($profileConfig['table'] ?? 'profiles');
        $columns = is_array($profileConfig['columns'] ?? null) ? $profileConfig['columns'] : [];
        if ($table === '' || $columns === []) {
            return;
        }

        $userUuid = $this->userValue($user, 'uuid');
        if (!is_string($userUuid) || $userUuid === '') {
            return;
        }

        $firstName = $this->extractSocialValue($socialData, 'first_name');
        $lastName = $this->extractSocialValue($socialData, 'last_name');
        $photoUrl = $this->extractSocialValue($socialData, 'photo_url');

        $update = [];
        if (array_key_exists('first_name', $columns) && $firstName !== null) {
            $update[(string)$columns['first_name']] = $firstName;
        }
        if (array_key_exists('last_name', $columns) && $lastName !== null) {
            $update[(string)$columns['last_name']] = $lastName;
        }
        if (array_key_exists('photo_url', $columns) && $photoUrl !== null) {
            $update[(string)$columns['photo_url']] = $photoUrl;
        }

        if ($update === []) {
            return;
        }

        $userUuidColumn = (string)($columns['user_uuid'] ?? 'user_uuid');
        $existing = $this->db->table($table)
            ->select(['*'])
            ->where($userUuidColumn, $userUuid)
            ->limit(1)
            ->get();

        $now = date('Y-m-d H:i:s');

        try {
            if (!empty($existing)) {
                // Profile already exists — user owns their data, don't overwrite
                return;
            }

            $insert = $update;
            if (array_key_exists('uuid', $columns)) {
                $insert[(string)$columns['uuid']] = Utils::generateNanoID();
            }
            $insert[$userUuidColumn] = $userUuid;
            if (array_key_exists('created_at', $columns)) {
                $insert[(string)$columns['created_at']] = $now;
            }
            if (array_key_exists('updated_at', $columns)) {
                $insert[(string)$columns['updated_at']] = $now;
            }

            $defaults = is_array($profileConfig['defaults'] ?? null) ? $profileConfig['defaults'] : [];
            if (array_key_exists('status', $columns) && array_key_exists('status', $defaults)) {
                $insert[(string)$columns['status']] = $defaults['status'];
            }

            $this->db->table($table)->insert($insert);
        } catch (\Throwable $e) {
            error_log("[{$this->providerName}] Profile sync failed: " . $e->getMessage());
        }
    }

    abstract protected function isOAuthCallback(Request $request): bool;
    abstract protected function isOAuthInitRequest(Request $request): bool;
    /**
     * @return array<string, mixed>|null
     */
    abstract protected function handleCallback(Request $request): ?array;
    abstract protected function initiateOAuthFlow(Request $request): void;

    /**
     * Normalize a provider's "email verified" flag to a strict boolean.
     *
     * Only an explicit truthy verification counts. In particular the string `"false"` must NOT
     * be treated as verified — PHP's `(bool) "false"` is `true` — and a missing/null flag is
     * unverified. Verification is never inferred from the mere presence of an email address.
     */
    protected function isVerifiedFlag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['true', '1'], true);
        }

        return false;
    }

    /**
     * Default OAuth callback URI for this provider, derived from the configured `app.url`.
     *
     * The redirect URI is never derived from the request `Host` header (host-header injection):
     * when `app.url` is unset a relative path is returned, which fails the provider's registered
     * redirect_uri match rather than trusting an attacker-controlled host. Providers fall back to
     * this only when no explicit redirect URI is configured.
     */
    protected function defaultCallbackUri(): string
    {
        $baseUrl = config($this->context, 'app.url', '');
        $baseUrl = is_string($baseUrl) ? rtrim($baseUrl, '/') : '';

        return $baseUrl . '/auth/social/' . $this->providerName . '/callback';
    }

    /**
     * Session key under which this provider's CSRF state token is stored.
     */
    protected function oauthStateSessionKey(): string
    {
        return $this->providerName . '_oauth_state';
    }

    /**
     * Generate a CSRF `state` token, persist it in the session, and return it for use in
     * the authorization URL. Call this from initiateOAuthFlow() instead of writing the
     * session directly.
     */
    protected function storeOAuthState(): string
    {
        $state = bin2hex(random_bytes(16));
        $this->ensureSession();
        $_SESSION[$this->oauthStateSessionKey()] = $state;

        return $state;
    }

    /**
     * Validate the `state` returned on the OAuth callback against the value stored in the
     * session (fail-closed CSRF protection). The stored token is single-use: it is cleared
     * whether or not validation succeeds, so a captured callback URL cannot be replayed.
     *
     * Reads `state` from the POST body first (Apple's form_post response mode) and then the
     * query string (Google/Facebook/GitHub), mirroring how the providers read `code`.
     */
    protected function validateOAuthState(Request $request): bool
    {
        $key = $this->oauthStateSessionKey();
        $this->ensureSession();

        $expected = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        $received = $request->request->get('state') ?? $request->query->get('state');

        if (!is_string($expected) || $expected === '' || !is_string($received) || $received === '') {
            return false;
        }

        return hash_equals($expected, $received);
    }

    /**
     * Ensure a PHP session is active so the CSRF state token survives the redirect→callback
     * round trip. Overridable in tests so the state helpers can run against the $_SESSION
     * array without starting a real session.
     */
    protected function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /**
     * Begin a PKCE (RFC 7636) exchange: generate a high-entropy code_verifier, store it in the
     * session, and return the S256 code_challenge to place in the authorization URL.
     */
    protected function startPkce(): string
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->ensureSession();
        $_SESSION[$this->providerName . '_pkce_verifier'] = $verifier;

        return $this->pkceChallenge($verifier);
    }

    /**
     * Retrieve and clear the stored PKCE code_verifier for the token exchange (single-use).
     */
    protected function consumePkceVerifier(): ?string
    {
        $key = $this->providerName . '_pkce_verifier';
        $this->ensureSession();
        $verifier = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        return is_string($verifier) && $verifier !== '' ? $verifier : null;
    }

    /**
     * Derive the S256 PKCE code_challenge from a code_verifier:
     * base64url(sha256(verifier)) without padding.
     */
    protected function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Generate an OIDC nonce, store it in the session, and return it for the authorization URL.
     */
    protected function startNonce(): string
    {
        $nonce = bin2hex(random_bytes(16));
        $this->ensureSession();
        $_SESSION[$this->providerName . '_oauth_nonce'] = $nonce;

        return $nonce;
    }

    /**
     * Validate an ID token's `nonce` claim against the stored value (fail-closed, single-use).
     * The stored nonce is cleared whether or not validation succeeds.
     */
    protected function validateNonce(mixed $tokenNonce): bool
    {
        $key = $this->providerName . '_oauth_nonce';
        $this->ensureSession();
        $expected = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        if (!is_string($expected) || $expected === '' || !is_string($tokenNonce) || $tokenNonce === '') {
            return false;
        }

        return hash_equals($expected, $tokenNonce);
    }

    /**
     * @param array<string, mixed> $socialData
     */
    private function runPostRegistrationHandler(string $userUuid, array $socialData): void
    {
        $config = $this->getSauthConfig();
        $postRegistration = is_array($config['post_registration'] ?? null) ? $config['post_registration'] : [];
        $enabled = (bool)($postRegistration['enabled'] ?? false);

        if (!$enabled) {
            return;
        }

        $handler = $postRegistration['handler'] ?? null;
        if ($handler === null || $handler === '') {
            throw new \RuntimeException('Post-registration handler is enabled but not configured');
        }

        $callable = null;

        if (is_string($handler) && class_exists($handler)) {
            $instance = app($this->context, $handler);
            if (is_callable($instance)) {
                $callable = $instance;
            }
        } elseif (is_callable($handler)) {
            $callable = $handler;
        }

        if (!is_callable($callable)) {
            throw new \RuntimeException('Configured post-registration handler is not callable');
        }

        $callable($userUuid, $socialData, $this->context);
    }

    private function getTokenManager(): TokenManager
    {
        if ($this->context->hasContainer()) {
            return $this->context->getContainer()->get(TokenManager::class);
        }

        return new TokenManager($this->context);
    }
}
