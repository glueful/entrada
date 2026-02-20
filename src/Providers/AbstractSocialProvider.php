<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Providers;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\Request;
use Glueful\Auth\Interfaces\AuthenticationProviderInterface;
use Glueful\Repository\UserRepository;
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
    protected UserRepository $userRepository;
    protected Connection $db;
    protected ApplicationContext $context;

    public function __construct(ApplicationContext $context)
    {
        $this->context = $context;
        $this->userRepository = new UserRepository(null, null, $context);
        $this->db = $context->getContainer()->get('database');
    }

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
            $this->lastError = "Authentication error: " . $e->getMessage();
            error_log("[{$this->providerName}] " . $this->lastError);
            return null;
        }

        return null;
    }

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

    public function generateTokens(
        array $userData,
        ?int $accessTokenLifetime = null,
        ?int $refreshTokenLifetime = null
    ): array {
        
        $userData['provider'] = $this->providerName;
        return $this->getTokenManager()->generateTokenPair($userData, $accessTokenLifetime, $refreshTokenLifetime);
    }

    public function refreshTokens(string $refreshToken, array $sessionData): ?array
    {
        $sessionData['provider'] = $this->providerName;
        try {
            return $this->getTokenManager()->refreshTokens($refreshToken, $this->providerName);
        } catch (\Exception $e) {
            $this->lastError = "Token refresh error: " . $e->getMessage();
            return null;
        }
    }

    protected function findOrCreateUser(array $socialData): ?array
    {
        $socialId = (string)($this->extractSocialValue($socialData, 'uuid') ?? '');
        if ($socialId === '') {
            $this->lastError = 'Social profile is missing provider user id';
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
                $emailUserUuid = $this->userValue($emailUser, 'uuid');
                if (!is_string($emailUserUuid) || $emailUserUuid === '') {
                    $this->lastError = 'Matched user is missing UUID';
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
            return null;
        }

        return $this->createUserFromSocial($socialData);
    }

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

    protected function linkSocialAccount(
        string $userUuid,
        string $provider,
        string $socialId,
        array $userData
    ): bool {
        $existing = $this->db->table('social_accounts')
            ->select(['*'])
            ->where('user_uuid', $userUuid)
            ->where('provider', $provider)
            ->where('social_id', $socialId)
            ->limit(1)
            ->get();

        if (!empty($existing)) {
            return $this->db->table('social_accounts')
                ->where('uuid', $existing[0]['uuid'])
                ->update([
                    'profile_data' => json_encode($userData),
                    'updated_at' => date('Y-m-d H:i:s')
                ]) > 0;
        }

        $result = $this->db->table('social_accounts')->insert([
            'uuid' => Utils::generateNanoID(),
            'user_uuid' => $userUuid,
            'provider' => $provider,
            'social_id' => $socialId,
            'profile_data' => json_encode($userData),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return (bool)$result;
    }

    protected function formatUserData(array $user): array
    {
        $uuid = $this->userValue($user, 'uuid');
        $email = $this->userValue($user, 'email');
        $name = $this->userValue($user, 'username');

        return [
            'uuid' => $uuid,
            'email' => $email,
            'name' => $name,
            'roles' => $user['roles'] ?? [],
        ];
    }

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

        if ((bool)$verified && (array_key_exists('email_verified_at', $columns) || array_key_exists('email_verified_at', $defaults))) {
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
            return null;
        }

        // Run app-specific provisioning after commit so handlers that resolve
        // their own DB connections can see the newly created user row.
        try {
            $this->runPostRegistrationHandler($userUuid, $socialData);
        } catch (\Throwable $e) {
            error_log("[{$this->providerName}] User provisioning failed: " . $e->getMessage());
            $this->lastError = 'Failed to create user account';
            return null;
        }

        $createdUser = $this->findUserByUuid($userUuid);
        if ($createdUser !== null) {
            $this->syncUserProfileFromSocial($createdUser, $socialData);
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

    private function getSauthConfig(): array
    {
        $config = config($this->context, 'sauth', []);
        return is_array($config) ? $config : [];
    }

    private function getStorageConfig(string $entity): array
    {
        $config = $this->getSauthConfig();
        $storage = $config['storage'] ?? [];

        if (!is_array($storage) || !is_array($storage[$entity] ?? null)) {
            return [];
        }

        return $storage[$entity];
    }

    private function getFieldMappingConfig(): array
    {
        $config = $this->getSauthConfig();
        $mapping = $config['field_mapping'] ?? [];
        return is_array($mapping) ? $mapping : [];
    }

    /**
     * Resolve canonical field value from social payload via configurable aliases.
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
    abstract protected function handleCallback(Request $request): ?array;
    abstract protected function initiateOAuthFlow(Request $request): void;

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