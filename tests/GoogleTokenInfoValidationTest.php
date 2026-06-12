<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Tests;

use Glueful\Extensions\Entrada\Tests\Support\GoogleTokenInfoTestProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Google tokeninfo-claims validator must bind the token to *this* application before any
 * claim is trusted: the audience (aud) and issuer (iss) checks fail closed, so a response that
 * omits either claim is rejected rather than silently accepted. The tokeninfo endpoint already
 * validates signature/expiry server-side, so those are not re-checked here.
 */
final class GoogleTokenInfoValidationTest extends TestCase
{
    private string $clientId = '123456789.apps.googleusercontent.com';

    private function validator(): GoogleTokenInfoTestProvider
    {
        return new GoogleTokenInfoTestProvider($this->clientId);
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function tokenInfo(array $override = []): array
    {
        return array_merge([
            'aud' => $this->clientId,
            'iss' => 'https://accounts.google.com',
            'sub' => '000123456789',
            'email' => 'user@example.com',
            'email_verified' => 'true',
            'name' => 'Ada Lovelace',
            'given_name' => 'Ada',
            'family_name' => 'Lovelace',
        ], $override);
    }

    public function test_missing_audience_throws(): void
    {
        $info = $this->tokenInfo();
        unset($info['aud']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Token was not issued for this application');
        $this->validator()->exposedValidate($info);
    }

    public function test_wrong_audience_throws(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Token was not issued for this application');
        $this->validator()->exposedValidate($this->tokenInfo(['aud' => 'attacker.apps.googleusercontent.com']));
    }

    public function test_valid_claims_return_mapped_profile(): void
    {
        $profile = $this->validator()->exposedValidate($this->tokenInfo());

        self::assertSame('000123456789', $profile['id']);
        self::assertSame('user@example.com', $profile['email']);
        self::assertTrue($profile['verified_email']);
    }

    public function test_bare_host_issuer_is_accepted(): void
    {
        $profile = $this->validator()->exposedValidate($this->tokenInfo(['iss' => 'accounts.google.com']));

        self::assertSame('000123456789', $profile['id']);
    }

    public function test_email_verified_non_true_string_maps_to_false(): void
    {
        // The source maps verified_email from the literal string 'true'; anything else is false.
        $profile = $this->validator()->exposedValidate($this->tokenInfo(['email_verified' => 'false']));

        self::assertFalse($profile['verified_email']);
    }

    public function test_missing_issuer_throws(): void
    {
        $info = $this->tokenInfo();
        unset($info['iss']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Token was not issued by Google');
        $this->validator()->exposedValidate($info);
    }

    public function test_wrong_issuer_throws(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Token was not issued by Google');
        $this->validator()->exposedValidate($this->tokenInfo(['iss' => 'https://evil.example.com']));
    }
}
