<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Tests\Domain;

use PHPUnit\Framework\TestCase;
use RelenzWorks\InfraRegister\Domain\Asset\AssetId;
use RelenzWorks\InfraRegister\Domain\Asset\InvalidAssetId;
use Symfony\Component\Uid\Uuid;

final class AssetIdTest extends TestCase
{
    public function testItGeneratesUuidIds(): void
    {
        self::assertTrue(Uuid::isValid(AssetId::generate()->value));
    }

    public function testItRejectsInvalidIds(): void
    {
        $this->expectException(InvalidAssetId::class);

        AssetId::fromString('not-a-uuid');
    }
}
