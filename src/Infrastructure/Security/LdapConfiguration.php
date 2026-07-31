<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Infrastructure\Security;

use RelenzWorks\InfraRegister\Domain\Security\InvalidSecurityConfiguration;
use RelenzWorks\InfraRegister\Domain\Security\Role;

final readonly class LdapConfiguration
{
    /**
     * @param array<string, Role> $groupRoleMap
     */
    public function __construct(
        public string $uri,
        public string $baseDn,
        public string $userFilter,
        public ?string $bindDn,
        public ?string $bindPassword,
        public array $groupRoleMap,
    ) {
        if ($uri === '' || $baseDn === '' || $userFilter === '') {
            throw new InvalidSecurityConfiguration('LDAP URI, base DN, and user filter are required.');
        }

        if (!str_contains($userFilter, '{username}')) {
            throw new InvalidSecurityConfiguration('LDAP user filter must contain {username}.');
        }
    }

    /**
     * @param array<string, string|false> $environment
     */
    public static function fromEnvironment(array $environment): ?self
    {
        $uri = self::optional($environment, 'INFRAREGISTER_LDAP_URI');

        if ($uri === null) {
            return null;
        }

        return new self(
            $uri,
            self::required($environment, 'INFRAREGISTER_LDAP_BASE_DN'),
            self::optional($environment, 'INFRAREGISTER_LDAP_USER_FILTER') ?? '(uid={username})',
            self::optional($environment, 'INFRAREGISTER_LDAP_BIND_DN'),
            self::optional($environment, 'INFRAREGISTER_LDAP_BIND_PASSWORD'),
            self::parseGroupRoleMap(self::optional($environment, 'INFRAREGISTER_LDAP_GROUP_ROLE_MAP')),
        );
    }

    /**
     * @return array<string, Role>
     */
    public static function parseGroupRoleMap(?string $configuration): array
    {
        if ($configuration === null || trim($configuration) === '') {
            return [];
        }

        $map = [];

        foreach (explode(';', $configuration) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            if (!str_contains($entry, '=')) {
                throw new InvalidSecurityConfiguration('LDAP group role entries must use group=role format.');
            }

            [$group, $role] = explode('=', $entry, 2);
            $group = strtolower(trim($group));
            $role = Role::tryFrom(trim($role));

            if ($group === '' || $role === null) {
                throw new InvalidSecurityConfiguration('LDAP group role configuration contains an invalid group or role.');
            }

            $map[$group] = $role;
        }

        return $map;
    }

    /**
     * @param array<string, string|false> $environment
     */
    private static function required(array $environment, string $key): string
    {
        $value = self::optional($environment, $key);

        if ($value === null) {
            throw new InvalidSecurityConfiguration(sprintf('%s is required when LDAP is enabled.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, string|false> $environment
     */
    private static function optional(array $environment, string $key): ?string
    {
        $value = $environment[$key] ?? false;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
