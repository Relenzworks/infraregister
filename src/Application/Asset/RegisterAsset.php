<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Application\Asset;

final class RegisterAsset
{
    public function __construct(public readonly string $name) {}
}
