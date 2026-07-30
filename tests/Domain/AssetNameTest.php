<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RelenzWorks\InfraRegister\Domain\Asset\AssetName;
use RelenzWorks\InfraRegister\Domain\Asset\InvalidAssetName;

final class AssetNameTest extends TestCase
{
    public function testItTrimsNames(): void
    {
        self::assertSame('Access Switch', AssetName::fromString(' Access Switch ')->value);
    }

    #[DataProvider('invalidNames')]
    public function testItRejectsInvalidNames(string $name): void
    {
        $this->expectException(InvalidAssetName::class);

        AssetName::fromString($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'blank' => [''];
        yield 'spaces' => ['   '];
        yield 'too long' => [str_repeat('a', 121)];
    }
}
