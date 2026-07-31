<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Domain\Security;

enum Permission: string
{
    case AssetRegister = 'asset.register';
    case AssetRead = 'asset.read';
    case AdminAccess = 'admin.access';

    /**
     * @return list<string>
     */
    public function requiredSecurityRoles(): array
    {
        return match ($this) {
            self::AssetRead => ['ROLE_VIEWER'],
            self::AssetRegister => ['ROLE_OPERATOR', 'ROLE_ASSET_MANAGER', 'ROLE_ADMIN'],
            self::AdminAccess => ['ROLE_ADMIN'],
        };
    }
}
