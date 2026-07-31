<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use RelenzWorks\InfraRegister\Domain\Security\Role;
use RelenzWorks\InfraRegister\Infrastructure\Security\LdapClient;
use RelenzWorks\InfraRegister\Infrastructure\Security\LdapConfiguration;
use RelenzWorks\InfraRegister\Infrastructure\Security\LdapUserDirectory;

final class LdapUserDirectoryTest extends TestCase
{
    public function testItAuthenticatesLdapUsersAndMapsGroupsToRoles(): void
    {
        $directory = new LdapUserDirectory(
            new LdapConfiguration(
                'ldap://directory.example',
                'ou=people,dc=example,dc=com',
                '(uid={username})',
                'cn=service,dc=example,dc=com',
                'service-secret',
                ['infraregister admins' => Role::Admin],
            ),
            new FakeLdapClient(true, [
                'dn' => 'uid=alice,ou=people,dc=example,dc=com',
                'memberOf' => ['cn=InfraRegister Admins,ou=groups,dc=example,dc=com'],
            ]),
        );

        $user = $directory->authenticate('alice', 'secret');

        self::assertNotNull($user);
        self::assertSame('alice', $user->username);
        self::assertSame([Role::Admin], $user->roles);
    }

    public function testItDefaultsAuthenticatedLdapUsersToViewer(): void
    {
        $directory = new LdapUserDirectory(
            new LdapConfiguration('ldap://directory.example', 'dc=example,dc=com', '(uid={username})', null, null, []),
            new FakeLdapClient(true, ['dn' => 'uid=alice,dc=example,dc=com']),
        );

        self::assertSame([Role::Viewer], $directory->authenticate('alice', 'secret')?->roles);
    }

    public function testItRejectsUsersWhenBindFails(): void
    {
        $directory = new LdapUserDirectory(
            new LdapConfiguration('ldap://directory.example', 'dc=example,dc=com', '(uid={username})', null, null, []),
            new FakeLdapClient(false, ['dn' => 'uid=alice,dc=example,dc=com']),
        );

        self::assertNull($directory->authenticate('alice', 'secret'));
    }

    public function testItRejectsBlankCredentials(): void
    {
        $directory = new LdapUserDirectory(
            new LdapConfiguration('ldap://directory.example', 'dc=example,dc=com', '(uid={username})', null, null, []),
            new FakeLdapClient(true, ['dn' => 'uid=alice,dc=example,dc=com']),
        );

        self::assertNull($directory->authenticate('', 'secret'));
        self::assertNull($directory->authenticate('alice', ''));
    }

    public function testItRejectsEntriesWithoutDistinguishedNames(): void
    {
        $directory = new LdapUserDirectory(
            new LdapConfiguration('ldap://directory.example', 'dc=example,dc=com', '(uid={username})', null, null, []),
            new FakeLdapClient(true, ['memberOf' => ['operators']]),
        );

        self::assertNull($directory->authenticate('alice', 'secret'));
    }

    public function testItMapsStringMemberOfValues(): void
    {
        $directory = new LdapUserDirectory(
            new LdapConfiguration(
                'ldap://directory.example',
                'dc=example,dc=com',
                '(uid={username})',
                null,
                null,
                ['operators' => Role::Operator],
            ),
            new FakeLdapClient(true, [
                'dn' => 'uid=alice,dc=example,dc=com',
                'memberof' => 'operators',
            ]),
        );

        self::assertSame([Role::Operator], $directory->authenticate('alice', 'secret')?->roles);
    }

    public function testItIgnoresMalformedMemberOfValues(): void
    {
        $directory = new LdapUserDirectory(
            new LdapConfiguration('ldap://directory.example', 'dc=example,dc=com', '(uid={username})', null, null, []),
            new FakeLdapClient(true, [
                'dn' => 'uid=alice,dc=example,dc=com',
                'memberOf' => 'no-common-name',
            ]),
        );

        self::assertSame([Role::Viewer], $directory->authenticate('alice', 'secret')?->roles);
    }

    public function testItIgnoresNonListMemberOfValues(): void
    {
        $directory = new LdapUserDirectory(
            new LdapConfiguration('ldap://directory.example', 'dc=example,dc=com', '(uid={username})', null, null, []),
            new FakeLdapClient(true, [
                'dn' => 'uid=alice,dc=example,dc=com',
                'memberOf' => 42,
            ]),
        );

        self::assertSame([Role::Viewer], $directory->authenticate('alice', 'secret')?->roles);
    }
}

final readonly class FakeLdapClient implements LdapClient
{
    /**
     * @param array<string, mixed>|null $entry
     */
    public function __construct(
        private bool $binds,
        private ?array $entry,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function findUser(LdapConfiguration $configuration, string $username): ?array
    {
        return $this->entry;
    }

    public function authenticate(string $uri, string $userDn, string $password): bool
    {
        return $this->binds;
    }
}
