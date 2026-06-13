<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Tests;

use Glueful\Extensions\Entrada\Tests\Support\AppleClientSecretTestProvider;
use PHPUnit\Framework\TestCase;

/**
 * Apple's client_secret is an ES256 JWT signed with the developer's `.p8` private key. The
 * generator must distinguish three inputs: a pre-built JWT (used verbatim), a key-file path
 * (read then signed), and inline PEM (signed). The earlier "contains a dot" detection broke
 * the path case because a `.p8` path also contains dots, so these tests pin the structural
 * detection and assert a real, verifiable ES256 JWT comes out of the key paths.
 */
final class AppleClientSecretTest extends TestCase
{
    private string $teamId = 'TEAM123456';
    private string $keyId = 'KEY7890AB';
    private string $clientId = 'com.example.service';

    private \OpenSSLAsymmetricKey $privateKey;
    private string $privatePem;
    /** @var array<string, mixed> */
    private array $publicDetails;

    protected function setUp(): void
    {
        // Generate a real EC P-256 key so the produced JWT can be verified with openssl.
        $this->privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]) ?: throw new \RuntimeException('Unable to generate test EC key');

        $pem = '';
        openssl_pkey_export($this->privateKey, $pem);
        $this->privatePem = $pem;

        $details = openssl_pkey_get_details($this->privateKey);
        $this->publicDetails = $details ?: throw new \RuntimeException('Unable to read EC key details');
    }

    private function b64uDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }

    /**
     * Verify a JOSE (r||s, 64-byte) ES256 signature over the signing input against the public key
     * by re-encoding it as the DER SEQUENCE openssl expects. Returns true if the signature checks.
     */
    private function verifyEs256(string $signingInput, string $joseSignature): bool
    {
        if (strlen($joseSignature) !== 64) {
            return false;
        }
        $r = ltrim(substr($joseSignature, 0, 32), "\x00");
        $s = ltrim(substr($joseSignature, 32, 32), "\x00");
        if ($r === '') {
            $r = "\x00";
        }
        if ($s === '') {
            $s = "\x00";
        }
        if ((ord($r[0]) & 0x80) !== 0) {
            $r = "\x00" . $r;
        }
        if ((ord($s[0]) & 0x80) !== 0) {
            $s = "\x00" . $s;
        }
        $intR = "\x02" . chr(strlen($r)) . $r;
        $intS = "\x02" . chr(strlen($s)) . $s;
        $seqBody = $intR . $intS;
        $der = "\x30" . chr(strlen($seqBody)) . $seqBody;

        $publicPem = $this->publicDetails['key'];

        return openssl_verify($signingInput, $der, $publicPem, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * Assert that $jwt is a well-formed ES256 client-secret JWT for this app, and (when asked)
     * that its signature verifies against the generated public key.
     */
    private function assertValidClientSecretJwt(string $jwt, bool $verifySignature): void
    {
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts, 'client secret must have three segments');
        [$encHeader, $encPayload, $encSignature] = $parts;

        $header = json_decode($this->b64uDecode($encHeader), true);
        self::assertIsArray($header);
        self::assertSame('ES256', $header['alg']);
        self::assertSame($this->keyId, $header['kid']);

        $payload = json_decode($this->b64uDecode($encPayload), true);
        self::assertIsArray($payload);
        self::assertSame($this->teamId, $payload['iss']);
        self::assertSame($this->clientId, $payload['sub']);
        self::assertSame('https://appleid.apple.com', $payload['aud']);

        if ($verifySignature) {
            $signature = $this->b64uDecode($encSignature);
            self::assertTrue(
                $this->verifyEs256($encHeader . '.' . $encPayload, $signature),
                'ES256 signature must verify against the generated public key'
            );
        }
    }

    public function test_returns_a_prebuilt_jwt_verbatim(): void
    {
        // A value shaped like a real JWT: three base64url segments, header decodes to alg ES256.
        $header = rtrim(strtr(base64_encode((string) json_encode(['alg' => 'ES256', 'kid' => 'x'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode((string) json_encode(['iss' => 'someone'])), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode('not-a-real-signature'), '+/', '-_'), '=');
        $prebuilt = $header . '.' . $payload . '.' . $signature;

        $provider = new AppleClientSecretTestProvider([
            'team_id' => $this->teamId,
            'key_id' => $this->keyId,
            'client_id' => $this->clientId,
            'client_secret' => $prebuilt,
        ]);

        self::assertSame($prebuilt, $provider->exposedGenerateClientSecret());
    }

    public function test_p8_file_path_produces_a_real_signed_jwt(): void
    {
        // A .p8-style file path always contains a dot; the old detection misread it as a JWT.
        $path = tempnam(sys_get_temp_dir(), 'AuthKey_') . '.p8';
        file_put_contents($path, $this->privatePem);

        try {
            $provider = new AppleClientSecretTestProvider([
                'team_id' => $this->teamId,
                'key_id' => $this->keyId,
                'client_id' => $this->clientId,
                'client_secret' => $path,
            ]);

            $jwt = $provider->exposedGenerateClientSecret();

            self::assertNotSame($path, $jwt, 'the file path must not be returned as the client secret');
            $this->assertValidClientSecretJwt($jwt, verifySignature: true);
        } finally {
            @unlink($path);
        }
    }

    public function test_inline_pem_produces_a_real_signed_jwt(): void
    {
        // Inline PEM content (no leading slash) must also be signed, not returned verbatim.
        $provider = new AppleClientSecretTestProvider([
            'team_id' => $this->teamId,
            'key_id' => $this->keyId,
            'client_id' => $this->clientId,
            'client_secret' => $this->privatePem,
        ]);

        $jwt = $provider->exposedGenerateClientSecret();

        self::assertNotSame($this->privatePem, $jwt);
        $this->assertValidClientSecretJwt($jwt, verifySignature: true);
    }

    public function test_convert_to_binary_throws_on_oversized_component(): void
    {
        $provider = new AppleClientSecretTestProvider([
            'team_id' => $this->teamId,
            'key_id' => $this->keyId,
            'client_id' => $this->clientId,
            'client_secret' => $this->privatePem,
        ]);

        // 33 bytes for a 32-byte ES256 component is malformed and must fail loudly.
        $oversized = str_repeat("\x11", 33);

        $this->expectException(\RuntimeException::class);
        $provider->exposedConvertToBinary($oversized, 32);
    }

    public function test_convert_to_binary_left_pads_short_component(): void
    {
        $provider = new AppleClientSecretTestProvider([
            'team_id' => $this->teamId,
            'key_id' => $this->keyId,
            'client_id' => $this->clientId,
            'client_secret' => $this->privatePem,
        ]);

        $result = $provider->exposedConvertToBinary("\x2a", 32);

        self::assertSame(32, strlen($result));
        self::assertSame("\x2a", substr($result, -1));
        self::assertSame(str_repeat("\x00", 31), substr($result, 0, 31));
    }
}
