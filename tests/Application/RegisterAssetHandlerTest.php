<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Tests\Application;

use PHPUnit\Framework\TestCase;
use RelenzWorks\InfraRegister\Application\Asset\RegisterAsset;
use RelenzWorks\InfraRegister\Application\Asset\RegisterAssetHandler;
use RelenzWorks\InfraRegister\Domain\Asset\Asset;
use RelenzWorks\InfraRegister\Domain\Asset\AssetId;
use RelenzWorks\InfraRegister\Domain\Asset\AssetStatus;
use RelenzWorks\InfraRegister\Port\AssetRepository;

final class RegisterAssetHandlerTest extends TestCase
{
    public function testItRegistersAnAssetInService(): void
    {
        $repository = new RecordingAssetRepository();
        $handler = new RegisterAssetHandler($repository);

        $asset = $handler(new RegisterAsset('Core Router 01'));

        self::assertSame('Core Router 01', $asset->name->value);
        self::assertSame(AssetStatus::InService, $asset->status);
        self::assertSame($asset, $repository->get($asset->id));
    }
}

final class RecordingAssetRepository implements AssetRepository
{
    /** @var array<string, Asset> */
    private array $assets = [];

    public function save(Asset $asset): void
    {
        $this->assets[$asset->id->value] = $asset;
    }

    public function get(AssetId $id): ?Asset
    {
        return $this->assets[$id->value] ?? null;
    }
}
