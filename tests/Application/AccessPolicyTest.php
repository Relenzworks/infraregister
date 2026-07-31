<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Tests\Application;

use PHPUnit\Framework\TestCase;
use RelenzWorks\InfraRegister\Application\Security\AccessPolicy;
use RelenzWorks\InfraRegister\Domain\Security\AuthenticatedUser;
use RelenzWorks\InfraRegister\Domain\Security\Permission;
use RelenzWorks\InfraRegister\Domain\Security\Role;

final class AccessPolicyTest extends TestCase
{
    public function testViewerCanReadAssets(): void
    {
        self::assertTrue((new AccessPolicy())->allows(
            new AuthenticatedUser('viewer', [Role::Viewer]),
            Permission::AssetRead,
        ));
    }

    public function testViewerCannotRegisterAssets(): void
    {
        self::assertFalse((new AccessPolicy())->allows(
            new AuthenticatedUser('viewer', [Role::Viewer]),
            Permission::AssetRegister,
        ));
    }

    public function testAssetManagerCanRegisterAssets(): void
    {
        self::assertTrue((new AccessPolicy())->allows(
            new AuthenticatedUser('manager', [Role::AssetManager]),
            Permission::AssetRegister,
        ));
    }

    public function testOnlyAdminsCanAccessAdministration(): void
    {
        $policy = new AccessPolicy();

        self::assertFalse($policy->allows(new AuthenticatedUser('manager', [Role::AssetManager]), Permission::AdminAccess));
        self::assertTrue($policy->allows(new AuthenticatedUser('admin', [Role::Admin]), Permission::AdminAccess));
    }
}
