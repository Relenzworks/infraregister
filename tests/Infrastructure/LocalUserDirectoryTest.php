<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use RelenzWorks\InfraRegister\Domain\Security\InvalidSecurityConfiguration;
use RelenzWorks\InfraRegister\Domain\Security\Role;
use RelenzWorks\InfraRegister\Infrastructure\Security\LocalUserDirectory;

final class LocalUserDirectoryTest extends TestCase
{
    public function testItAuthenticatesConfiguredUsersWithRoles(): void
    {
        $directory = LocalUserDirectory::fromConfiguration('alice:secret=viewer,operator');

        self::assertNotNull($directory);

        $user = $directory->authenticate('alice', 'secret');

        self::assertNotNull($user);
        self::assertSame('alice', $user->username);
        self::assertSame([Role::Viewer, Role::Operator], $user->roles);
    }

    public function testItRejectsInvalidPasswords(): void
    {
        $directory = LocalUserDirectory::fromConfiguration('alice:secret=operator');

        self::assertNotNull($directory);
        self::assertNull($directory->authenticate('alice', 'wrong'));
    }

    public function testLegacyWriteAuthCreatesAssetManager(): void
    {
        $directory = LocalUserDirectory::fromLegacyWriteAuth('writer:secret');

        self::assertNotNull($directory);
        self::assertSame([Role::AssetManager], $directory->authenticate('writer', 'secret')?->roles);
    }

    public function testItRejectsUnknownRoles(): void
    {
        $this->expectException(InvalidSecurityConfiguration::class);

        LocalUserDirectory::fromConfiguration('alice:secret=superuser');
    }

    public function testItIgnoresEmptyConfigurationEntries(): void
    {
        $directory = LocalUserDirectory::fromConfiguration(' ; alice:secret=operator; ');

        self::assertNotNull($directory);
        self::assertSame([Role::Operator], $directory->authenticate('alice', 'secret')?->roles);
    }

    public function testItReturnsNullForMalformedLegacyWriteAuth(): void
    {
        self::assertNull(LocalUserDirectory::fromLegacyWriteAuth(null));
        self::assertNull(LocalUserDirectory::fromLegacyWriteAuth('malformed'));
        self::assertNull(LocalUserDirectory::fromLegacyWriteAuth(':secret'));
    }

    public function testItRejectsMalformedLocalEntries(): void
    {
        $this->expectException(InvalidSecurityConfiguration::class);

        LocalUserDirectory::fromConfiguration('alice-secret-operator');
    }

    public function testItRejectsBlankLocalCredentials(): void
    {
        $this->expectException(InvalidSecurityConfiguration::class);

        LocalUserDirectory::fromConfiguration('alice:=operator');
    }
}
