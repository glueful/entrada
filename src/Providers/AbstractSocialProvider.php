<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Providers;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Auth\Interfaces\AuthenticationProviderInterface;
use Glueful\Repository\UserRepository;
use Glueful\Auth\TokenManager;
use Glueful\Auth\JWTService;
use Glueful\Helpers\Utils;

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

    public function __construct()
    {
        $this->userRepository = new UserRepository();
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
        return TokenManager::validateAccessToken($token);
    }

    public function canHandleToken(string $token): bool
    {
        $decoded = JWTService::decode($token);
        if (!$decoded) {
            return false;
        }
        return isset($decoded['provider']) && $decoded['provider'] === $this->providerName;
    }

    public function generateTokens(
        array $userData,
        ?int $accessTokenLifetime = null,
        ?int $refreshTokenLifetime = null
    ): array {
        $userData['provider'] = $this->providerName;
        return TokenManager::generateTokenPair($userData, $accessTokenLifetime, $refreshTokenLifetime);
    }

    public function refreshTokens(string $refreshToken, array $sessionData): ?array
    {
        $sessionData['provider'] = $this->providerName;
        try {
            return TokenManager::refreshTokens($refreshToken, $this->providerName);
        } catch (\Exception $e) {
            $this->lastError = "Token refresh error: " . $e->getMessage();
            return null;
        }
    }

    protected function findOrCreateUser(array $socialData): ?array
    {
        $existingUser = $this->findUserBySocialId(
            $this->providerName,
            $socialData['id']
        );

        if ($existingUser) {
            return $this->formatUserData($existingUser);
        }

        if (!empty($socialData['email'])) {
            $emailUser = $this->userRepository->findByEmail($socialData['email']);
            if ($emailUser) {
                $this->linkSocialAccount(
                    $emailUser['uuid'],
                    $this->providerName,
                    $socialData['id'],
                    $socialData
                );
                return $this->formatUserData($emailUser);
            }
        }

        $config = config('sauth', []);
        if (!($config['auto_register'] ?? true)) {
            $this->lastError = "Auto-registration is disabled and no matching user found";
            return null;
        }

        return $this->createUserFromSocial($socialData);
    }

    protected function findUserBySocialId(string $provider, string $socialId): ?array
    {
        $db = new \Glueful\Database\Connection();
        $result = $db->table('social_accounts')
            ->select(['user_uuid'])
            ->where('provider', $provider)
            ->where('social_id', $socialId)
            ->limit(1)
            ->get();

        if (empty($result)) {
            return null;
        }

        return $this->userRepository->findByUUID($result[0]['user_uuid']);
    }

    protected function linkSocialAccount(
        string $userUuid,
        string $provider,
        string $socialId,
        array $userData
    ): bool {
        $db = new \Glueful\Database\Connection();

        $existing = $db->table('social_accounts')
            ->select(['*'])
            ->where('user_uuid', $userUuid)
            ->where('provider', $provider)
            ->where('social_id', $socialId)
            ->limit(1)
            ->get();

        if (!empty($existing)) {
            return $db->table('social_accounts')
                ->where('uuid', $existing[0]['uuid'])
                ->update([
                    'profile_data' => json_encode($userData),
                    'updated_at' => date('Y-m-d H:i:s')
                ]) > 0;
        }

        $result = $db->table('social_accounts')->insert([
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
        return [
            'uuid' => $user['uuid'] ?? null,
            'email' => $user['email'] ?? null,
            'name' => $user['name'] ?? ($user['username'] ?? null),
            'roles' => $user['roles'] ?? [],
        ];
    }

    protected function createUserFromSocial(array $socialData): ?array
    {
        $userData = [
            'uuid' => Utils::generateNanoID(),
            'email' => $socialData['email'] ?? null,
            'name' => $socialData['name'] ?? ($socialData['username'] ?? null),
            'created_at' => date('Y-m-d H:i:s')
        ];

        $saved = $this->userRepository->create($userData);
        if (!$saved) {
            $this->lastError = 'Failed to create user account';
            return null;
        }

        $this->linkSocialAccount($userData['uuid'], $this->providerName, $socialData['id'], $socialData);
        return $this->formatUserData($userData);
    }

    abstract protected function isOAuthCallback(Request $request): bool;
    abstract protected function isOAuthInitRequest(Request $request): bool;
    abstract protected function handleCallback(Request $request): ?array;
    abstract protected function initiateOAuthFlow(Request $request): void;
}

