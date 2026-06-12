<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Tests;

use Glueful\Extensions\Entrada\Tests\Support\ProfileDataFilterTestProvider;
use PHPUnit\Framework\TestCase;

/**
 * Persisted social_accounts.profile_data must keep only a fixed identity allowlist and must drop
 * the raw provider response plus any unanticipated upstream fields, so the full provider payload
 * (extra PII / future fields) is never silently stored.
 */
final class ProfileDataFilterTest extends TestCase
{
    public function test_strips_raw_provider_response_and_unknown_fields(): void
    {
        $provider = new ProfileDataFilterTestProvider();

        $filtered = $provider->exposedFilterProfileData([
            'id' => 'abc123',
            'email' => 'alice@example.com',
            'name' => 'Alice Example',
            'first_name' => 'Alice',
            'last_name' => 'Example',
            'username' => 'alice',
            'picture' => 'https://cdn.example.com/alice.jpg',
            'email_verified' => true,
            // Should be dropped:
            'raw' => ['access_token' => 'secret', 'locale' => 'en_GB', 'sub' => 'xyz'],
            'locale' => 'en_GB',
            'hd' => 'example.com',
            'some_future_field' => 'leak',
        ]);

        self::assertArrayNotHasKey('raw', $filtered);
        self::assertArrayNotHasKey('locale', $filtered);
        self::assertArrayNotHasKey('hd', $filtered);
        self::assertArrayNotHasKey('some_future_field', $filtered);

        self::assertSame([
            'id' => 'abc123',
            'email' => 'alice@example.com',
            'name' => 'Alice Example',
            'first_name' => 'Alice',
            'last_name' => 'Example',
            'username' => 'alice',
            'picture' => 'https://cdn.example.com/alice.jpg',
            'email_verified' => true,
        ], $filtered);
    }

    public function test_keeps_only_present_allowlisted_keys(): void
    {
        $provider = new ProfileDataFilterTestProvider();

        $filtered = $provider->exposedFilterProfileData([
            'id' => 'gh-42',
            'username' => 'octocat',
            'raw' => ['node_id' => 'MDQ6'],
        ]);

        self::assertSame([
            'id' => 'gh-42',
            'username' => 'octocat',
        ], $filtered);
    }
}
