<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Tests;

use Glueful\Extensions\Entrada\Tests\Support\FacebookConfigTestProvider;
use PHPUnit\Framework\TestCase;

/**
 * Facebook Graph hardening: the appsecret_proof is the HMAC-SHA256 of the access token keyed by the
 * app secret (so Graph calls survive "Require App Secret" and a stolen token cannot be replayed),
 * and the Graph API version is configurable with a current stable default.
 */
final class FacebookGraphHardeningTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        // Isolate from any FACEBOOK_* env that would otherwise feed loadConfig()'s fallbacks.
        foreach (['FACEBOOK_API_VERSION', 'FACEBOOK_APP_ID', 'FACEBOOK_APP_SECRET', 'FACEBOOK_REDIRECT_URI'] as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("{$key}={$value}");
            }
        }
    }

    public function test_appsecret_proof_matches_hmac_sha256_known_vector(): void
    {
        $appSecret = 'app-secret-value';
        $accessToken = 'user-access-token';

        // Known vector: hex HMAC-SHA256(message=accessToken, key=appSecret) per Facebook's spec.
        $expected = hash_hmac('sha256', $accessToken, $appSecret);
        // Sanity-check the literal so the assertion below isn't merely re-deriving the impl.
        self::assertSame(
            '82cd96817dfdc3f933035f53ae4c77e70c1d2a81632ca58bd0565a813c677a21',
            $this->referenceVector(),
            'reference vector helper sanity'
        );

        $provider = new FacebookConfigTestProvider([
            'app_id' => 'app-id',
            'app_secret' => $appSecret,
        ]);

        self::assertSame($expected, $provider->exposedAppSecretProof($accessToken));
        // 64-char lowercase hex (sha256), bound to the token (changing the token changes the proof).
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $provider->exposedAppSecretProof($accessToken));
        self::assertNotSame(
            $provider->exposedAppSecretProof($accessToken),
            $provider->exposedAppSecretProof($accessToken . 'x')
        );
    }

    public function test_api_version_defaults_to_v21_when_unconfigured(): void
    {
        $provider = new FacebookConfigTestProvider([
            'app_id' => 'app-id',
            'app_secret' => 'app-secret',
        ]);

        self::assertSame('v21.0', $provider->resolvedApiVersion());
    }

    public function test_api_version_config_overrides_default(): void
    {
        $provider = new FacebookConfigTestProvider([
            'app_id' => 'app-id',
            'app_secret' => 'app-secret',
            'api_version' => 'v19.0',
        ]);

        self::assertSame('v19.0', $provider->resolvedApiVersion());
    }

    public function test_api_version_env_overrides_default_when_config_absent(): void
    {
        putenv('FACEBOOK_API_VERSION=v18.0');

        $provider = new FacebookConfigTestProvider([
            'app_id' => 'app-id',
            'app_secret' => 'app-secret',
        ]);

        self::assertSame('v18.0', $provider->resolvedApiVersion());
    }

    /**
     * Independently computed HMAC-SHA256(key='app-secret-value', msg='user-access-token') so the
     * primary assertion compares against a fixed literal rather than the implementation alone.
     */
    private function referenceVector(): string
    {
        return hash_hmac('sha256', 'user-access-token', 'app-secret-value');
    }
}
