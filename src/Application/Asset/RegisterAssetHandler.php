<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Application\Asset;

use RelenzWorks\InfraRegister\Domain\Asset\Asset;
use RelenzWorks\InfraRegister\Domain\Asset\AssetId;
use RelenzWorks\InfraRegister\Domain\Asset\AssetName;
use RelenzWorks\InfraRegister\Port\AssetRepository;

final class RegisterAssetHandler
{
    public function __construct(private readonly AssetRepository $assets) {}

    public function __invoke(RegisterAsset $command): Asset
    {
        $asset = Asset::register(
            AssetId::generate(),
            AssetName::fromString($command->name),
        );

        $this->assets->save($asset);

        return $asset;
    }
}
