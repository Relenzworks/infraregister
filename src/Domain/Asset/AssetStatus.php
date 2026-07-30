<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Domain\Asset;

enum AssetStatus: string
{
    case InService = 'in_service';
    case InStorage = 'in_storage';
    case Retired = 'retired';
}
