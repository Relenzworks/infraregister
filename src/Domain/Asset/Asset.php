<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Domain\Asset;

final class Asset
{
    private function __construct(
        public readonly AssetId $id,
        public readonly AssetName $name,
        public readonly AssetStatus $status,
    ) {}

    public static function register(AssetId $id, AssetName $name): self
    {
        return new self($id, $name, AssetStatus::InService);
    }

    public static function reconstitute(AssetId $id, AssetName $name, AssetStatus $status): self
    {
        return new self($id, $name, $status);
    }
}
