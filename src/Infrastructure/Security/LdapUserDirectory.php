<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Infrastructure\Security;

use RelenzWorks\InfraRegister\Domain\Security\AuthenticatedUser;
use RelenzWorks\InfraRegister\Domain\Security\Role;
use RelenzWorks\InfraRegister\Port\UserDirectory;

final readonly class LdapUserDirectory implements UserDirectory
{
    public function __construct(
        private LdapConfiguration $configuration,
        private LdapClient $client,
    ) {}

    public function authenticate(string $username, string $password): ?AuthenticatedUser
    {
        if ($username === '' || $password === '') {
            return null;
        }

        $entry = $this->client->findUser($this->configuration, $username);
        $dn = is_array($entry) && isset($entry['dn']) && is_string($entry['dn']) ? $entry['dn'] : null;

        if ($dn === null || !$this->client->authenticate($this->configuration->uri, $dn, $password)) {
            return null;
        }

        return new AuthenticatedUser($username, $this->rolesFromEntry($entry));
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<Role>
     */
    private function rolesFromEntry(array $entry): array
    {
        $roles = [];

        foreach ($this->groupsFromEntry($entry) as $group) {
            $role = $this->configuration->groupRoleMap[strtolower($group)] ?? null;

            if ($role !== null && !in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }

        return $roles === [] ? [Role::Viewer] : $roles;
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private function groupsFromEntry(array $entry): array
    {
        $groups = [];
        $memberOf = $entry['memberof'] ?? $entry['memberOf'] ?? [];

        if (is_string($memberOf)) {
            $memberOf = [$memberOf];
        }

        if (!is_array($memberOf)) {
            return [];
        }

        foreach ($memberOf as $group) {
            if (is_string($group) && $group !== '') {
                $groups[] = $group;
                $cn = $this->commonName($group);

                if ($cn !== null) {
                    $groups[] = $cn;
                }
            }
        }

        return $groups;
    }

    private function commonName(string $distinguishedName): ?string
    {
        if (preg_match('/(?:^|,)cn=([^,]+)/i', $distinguishedName, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
