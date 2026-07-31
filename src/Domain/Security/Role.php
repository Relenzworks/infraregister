<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Domain\Security;

enum Role: string
{
    case Viewer = 'viewer';
    case Operator = 'operator';
    case AssetManager = 'asset-manager';
    case Admin = 'admin';

    public function securityRole(): string
    {
        return match ($this) {
            self::Viewer => 'ROLE_VIEWER',
            self::Operator => 'ROLE_OPERATOR',
            self::AssetManager => 'ROLE_ASSET_MANAGER',
            self::Admin => 'ROLE_ADMIN',
        };
    }
}
