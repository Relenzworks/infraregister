<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use RelenzWorks\InfraRegister\Infrastructure\Http\StorePathResolver;

final class StorePathResolverTest extends TestCase
{
    public function testEnvironmentValueWins(): void
    {
        $resolved = StorePathResolver::resolve('/tmp/env-assets.json', [
            'INFRAREGISTER_STORE' => '/tmp/server-assets.json',
        ], '/app');

        self::assertSame('/tmp/env-assets.json', $resolved);
    }

    public function testServerValueIsUsedWhenEnvironmentValueIsEmpty(): void
    {
        $resolved = StorePathResolver::resolve('', [
            'INFRAREGISTER_STORE' => '/tmp/server-assets.json',
        ], '/app');

        self::assertSame('/tmp/server-assets.json', $resolved);
    }

    public function testServerValueIsUsedWhenEnvironmentLookupFails(): void
    {
        $resolved = StorePathResolver::resolve(false, [
            'INFRAREGISTER_STORE' => '/tmp/server-assets.json',
        ], '/app');

        self::assertSame('/tmp/server-assets.json', $resolved);
    }

    public function testEmptyServerValueFallsBackToDefaultProjectStore(): void
    {
        $resolved = StorePathResolver::resolve('', [
            'INFRAREGISTER_STORE' => '',
        ], '/app');

        self::assertSame('/app/var/assets.json', $resolved);
    }

    public function testMissingServerValueFallsBackToDefaultProjectStore(): void
    {
        $resolved = StorePathResolver::resolve(false, [], '/app');

        self::assertSame('/app/var/assets.json', $resolved);
    }
}
