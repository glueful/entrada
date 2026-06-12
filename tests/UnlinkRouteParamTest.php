<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Tests;

use Glueful\Extensions\Entrada\Controllers\SocialAccountController;
use PHPUnit\Framework\TestCase;

/**
 * The framework router injects route params (`{uuid}`) into controller methods BY ARGUMENT NAME,
 * not as individual request attributes. `destroy()` must therefore declare a `string $uuid`
 * parameter — otherwise the unlink lookup runs with an empty uuid and always 404s.
 */
final class UnlinkRouteParamTest extends TestCase
{
    public function test_destroy_declares_the_uuid_route_param_as_an_argument(): void
    {
        $method = new \ReflectionMethod(SocialAccountController::class, 'destroy');
        $params = $method->getParameters();

        self::assertCount(2, $params, 'destroy() must accept the {uuid} route param as an argument');

        $uuidParam = $params[1];
        self::assertSame('uuid', $uuidParam->getName(), 'the second argument must be named to match the {uuid} route param');

        $type = $uuidParam->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame('string', $type->getName());
    }
}
