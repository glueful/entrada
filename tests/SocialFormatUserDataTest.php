<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Tests;

use Glueful\Extensions\Entrada\Tests\Support\SocialFormatTestProvider;
use PHPUnit\Framework\TestCase;

/**
 * formatUserData() must surface the persisted verified-email status so it can flow into the
 * session/OIDC user reported to the client. A non-null email_verified_at column means verified;
 * a null or absent value fails closed to unverified.
 */
final class SocialFormatUserDataTest extends TestCase
{
    public function test_marks_user_verified_when_email_verified_at_is_set(): void
    {
        $provider = new SocialFormatTestProvider();

        $formatted = $provider->exposedFormatUserData([
            'uuid' => 'u-1',
            'username' => 'alice',
            'email' => 'alice@example.com',
            'email_verified_at' => '2026-06-12 10:00:00',
        ]);

        self::assertTrue($formatted['email_verified']);
        self::assertSame('2026-06-12 10:00:00', $formatted['email_verified_at']);
    }

    public function test_marks_user_unverified_when_email_verified_at_is_null(): void
    {
        $provider = new SocialFormatTestProvider();

        $formatted = $provider->exposedFormatUserData([
            'uuid' => 'u-2',
            'username' => 'bob',
            'email' => 'bob@example.com',
            'email_verified_at' => null,
        ]);

        self::assertFalse($formatted['email_verified']);
    }

    public function test_marks_user_unverified_when_email_verified_at_key_is_missing(): void
    {
        $provider = new SocialFormatTestProvider();

        $formatted = $provider->exposedFormatUserData([
            'uuid' => 'u-3',
            'username' => 'carol',
            'email' => 'carol@example.com',
        ]);

        self::assertFalse($formatted['email_verified']);
    }

    public function test_resolves_email_verified_at_through_storage_column_mapping(): void
    {
        // App maps the canonical email_verified_at to a custom column name; formatUserData must
        // read the row via the configured column and still expose the raw value under that name.
        $provider = new SocialFormatTestProvider(
            columnOverrides: ['email_verified_at' => 'verified_on']
        );

        $formatted = $provider->exposedFormatUserData([
            'uuid' => 'u-4',
            'username' => 'dave',
            'email' => 'dave@example.com',
            'verified_on' => '2026-06-12 11:00:00',
        ]);

        self::assertTrue($formatted['email_verified']);
        self::assertArrayHasKey('verified_on', $formatted);
        self::assertSame('2026-06-12 11:00:00', $formatted['verified_on']);
    }
}
