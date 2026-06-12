<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Tests;

use Glueful\Extensions\Entrada\Tests\Support\StateTestProvider;
use PHPUnit\Framework\TestCase;

/**
 * `isVerifiedFlag()` is the gate that decides whether a provider's email is verified enough to
 * auto-link to an existing account. It must be strict: only an explicit truthy value counts, and
 * the string "false" must NOT be treated as verified (PHP's (bool)"false" === true would).
 */
final class VerifiedEmailFlagTest extends TestCase
{
    private StateTestProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new StateTestProvider();
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function flagProvider(): iterable
    {
        yield 'bool true'        => [true, true];
        yield 'bool false'       => [false, false];
        yield 'string true'      => ['true', true];
        yield 'string TRUE'      => ['TRUE', true];
        yield 'string 1'         => ['1', true];
        yield 'int 1'            => [1, true];
        yield 'string false'     => ['false', false];   // the dangerous (bool)"false" === true case
        yield 'string 0'         => ['0', false];
        yield 'int 0'            => [0, false];
        yield 'null'             => [null, false];
        yield 'empty string'     => ['', false];
        yield 'random string'    => ['yes', false];
    }

    /**
     * @dataProvider flagProvider
     */
    public function test_normalizes_verification_flag(mixed $value, bool $expected): void
    {
        self::assertSame($expected, $this->provider->exposedIsVerifiedFlag($value));
    }
}
