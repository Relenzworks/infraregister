<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Infrastructure\Security;

use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Exception\ConnectionException;
use Symfony\Component\Ldap\Exception\LdapException;
use Symfony\Component\Ldap\Ldap;
use Symfony\Component\Ldap\LdapInterface;

final class NativeLdapClient implements LdapClient
{
    public function __construct(private ?LdapInterface $ldap = null) {}

    /**
     * @return array<string, mixed>|null
     */
    public function findUser(LdapConfiguration $configuration, string $username): ?array
    {
        $ldap = $this->ldap($configuration->uri);

        try {
            if ($configuration->bindDn !== null) {
                $ldap->bind($configuration->bindDn, $configuration->bindPassword ?? '');
            }

            $query = $ldap->query(
                $configuration->baseDn,
                str_replace('{username}', $ldap->escape($username, '', LdapInterface::ESCAPE_FILTER), $configuration->userFilter),
                ['filter' => ['dn', 'memberOf']],
            );
            $entries = $query->execute()->toArray();
        } catch (ConnectionException|LdapException) {
            return null;
        }

        if (count($entries) !== 1) {
            return null;
        }

        return $this->normalizeEntry($entries[0]);
    }

    public function authenticate(string $uri, string $userDn, string $password): bool
    {
        try {
            $this->ldap($uri)->bind($userDn, $password);

            return true;
        } catch (ConnectionException|LdapException) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeEntry(Entry $entry): array
    {
        return [
            'dn' => $entry->getDn(),
            'memberOf' => $entry->getAttribute('memberOf') ?? [],
        ];
    }

    private function ldap(string $uri): LdapInterface
    {
        return $this->ldap ?? Ldap::create('ext_ldap', ['connection_string' => $uri]);
    }

}
