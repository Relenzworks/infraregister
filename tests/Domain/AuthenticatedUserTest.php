<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Tests\Domain;

use PHPUnit\Framework\TestCase;
use RelenzWorks\InfraRegister\Domain\Security\AuthenticatedUser;
use RelenzWorks\InfraRegister\Domain\Security\InvalidSecurityConfiguration;
use RelenzWorks\InfraRegister\Domain\Security\Role;

final class AuthenticatedUserTest extends TestCase
{
    public function testItExposesSymfonyUserIdentity(): void
    {
        $user = new AuthenticatedUser('alice', [Role::Viewer, Role::Viewer, Role::Admin]);

        self::assertSame('alice', $user->getUserIdentifier());
        self::assertSame(['ROLE_VIEWER', 'ROLE_ADMIN'], $user->getRoles());

        $user->eraseCredentials();
        self::assertSame('alice', $user->username);
    }

    public function testItRejectsBlankUsernames(): void
    {
        $this->expectException(InvalidSecurityConfiguration::class);

        new AuthenticatedUser('', [Role::Viewer]);
    }
}
