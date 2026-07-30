<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Domain\Asset;

use Symfony\Component\Uid\Uuid;

final class AssetId
{
    private function __construct(public readonly string $value)
    {
        if (!Uuid::isValid($value)) {
            throw new InvalidAssetId('Asset ID must be a valid UUID.');
        }
    }

    public static function generate(): self
    {
        return new self((string) Uuid::v7());
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }
}
