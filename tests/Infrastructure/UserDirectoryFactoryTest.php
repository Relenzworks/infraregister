<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use RelenzWorks\InfraRegister\Domain\Security\Role;
use RelenzWorks\InfraRegister\Infrastructure\Security\CompositeUserDirectory;
use RelenzWorks\InfraRegister\Infrastructure\Security\LocalUserDirectory;
use RelenzWorks\InfraRegister\Infrastructure\Security\UserDirectoryFactory;

final class UserDirectoryFactoryTest extends TestCase
{
    public function testItReturnsNullWhenNoAuthenticationIsConfigured(): void
    {
        self::assertNull(UserDirectoryFactory::fromEnvironment([]));
    }

    public function testItBuildsLocalDirectoryFromExplicitUsers(): void
    {
        $directory = UserDirectoryFactory::fromEnvironment([
            'INFRAREGISTER_LOCAL_USERS' => 'alice:secret=operator',
        ]);

        self::assertInstanceOf(LocalUserDirectory::class, $directory);
        self::assertSame([Role::Operator], $directory->authenticate('alice', 'secret')?->roles);
    }

    public function testItFallsBackToLegacyWriteAuth(): void
    {
        $directory = UserDirectoryFactory::fromEnvironment([
            'INFRAREGISTER_WRITE_AUTH' => 'writer:secret',
        ]);

        self::assertInstanceOf(LocalUserDirectory::class, $directory);
        self::assertSame([Role::AssetManager], $directory->authenticate('writer', 'secret')?->roles);
    }

    public function testItBuildsCompositeDirectoryWhenLocalAndLdapAreConfigured(): void
    {
        $directory = UserDirectoryFactory::fromEnvironment([
            'INFRAREGISTER_LOCAL_USERS' => 'alice:secret=operator',
            'INFRAREGISTER_LDAP_URI' => 'ldap://directory.example',
            'INFRAREGISTER_LDAP_BASE_DN' => 'dc=example,dc=com',
        ]);

        self::assertInstanceOf(CompositeUserDirectory::class, $directory);
    }
}
