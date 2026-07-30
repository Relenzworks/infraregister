<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Domain\Asset;

final class AssetName
{
    private const int MAX_LENGTH = 120;

    public readonly string $value;

    private function __construct(string $value)
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidAssetName('Asset name cannot be blank.');
        }

        if (mb_check_encoding($trimmed, 'UTF-8') === false) {
            throw new InvalidAssetName('Asset name must be valid UTF-8.');
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw new InvalidAssetName('Asset name cannot exceed 120 characters.');
        }

        $this->value = $trimmed;
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }
}
