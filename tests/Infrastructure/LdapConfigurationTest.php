<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use RelenzWorks\InfraRegister\Domain\Security\InvalidSecurityConfiguration;
use RelenzWorks\InfraRegister\Domain\Security\Role;
use RelenzWorks\InfraRegister\Infrastructure\Security\LdapConfiguration;

final class LdapConfigurationTest extends TestCase
{
    public function testItBuildsConfigurationFromEnvironment(): void
    {
        $configuration = LdapConfiguration::fromEnvironment([
            'INFRAREGISTER_LDAP_URI' => 'ldap://directory.example',
            'INFRAREGISTER_LDAP_BASE_DN' => 'ou=people,dc=example,dc=com',
            'INFRAREGISTER_LDAP_USER_FILTER' => '(uid={username})',
            'INFRAREGISTER_LDAP_BIND_DN' => 'cn=service,dc=example,dc=com',
            'INFRAREGISTER_LDAP_BIND_PASSWORD' => 'secret',
            'INFRAREGISTER_LDAP_GROUP_ROLE_MAP' => 'InfraRegister Admins=admin;InfraRegister Operators=operator',
        ]);

        self::assertNotNull($configuration);
        self::assertSame('ldap://directory.example', $configuration->uri);
        self::assertSame(Role::Admin, $configuration->groupRoleMap['infraregister admins']);
        self::assertSame(Role::Operator, $configuration->groupRoleMap['infraregister operators']);
    }

    public function testItRequiresBaseDnWhenLdapIsEnabled(): void
    {
        $this->expectException(InvalidSecurityConfiguration::class);

        LdapConfiguration::fromEnvironment([
            'INFRAREGISTER_LDAP_URI' => 'ldap://directory.example',
        ]);
    }

    public function testItRequiresUsernamePlaceholderInFilter(): void
    {
        $this->expectException(InvalidSecurityConfiguration::class);

        new LdapConfiguration('ldap://directory.example', 'dc=example,dc=com', '(uid=alice)', null, null, []);
    }

    public function testItReturnsNullWhenLdapIsNotConfigured(): void
    {
        self::assertNull(LdapConfiguration::fromEnvironment([]));
    }

    public function testItAcceptsEmptyGroupRoleMapEntries(): void
    {
        self::assertSame(['ops' => Role::Operator], LdapConfiguration::parseGroupRoleMap(' ; ops=operator; '));
    }

    public function testItRejectsBlankRequiredConstructorValues(): void
    {
        $this->expectException(InvalidSecurityConfiguration::class);

        new LdapConfiguration('', 'dc=example,dc=com', '(uid={username})', null, null, []);
    }

    public function testItRejectsMalformedGroupRoleEntries(): void
    {
        $this->expectException(InvalidSecurityConfiguration::class);

        LdapConfiguration::parseGroupRoleMap('operators');
    }

    public function testItRejectsInvalidGroupRoles(): void
    {
        $this->expectException(InvalidSecurityConfiguration::class);

        LdapConfiguration::parseGroupRoleMap('operators=superuser');
    }
}
