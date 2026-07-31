<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Infrastructure\Security;

interface LdapClient
{
    /**
     * @return array<string, mixed>|null
     */
    public function findUser(LdapConfiguration $configuration, string $username): ?array;

    public function authenticate(string $uri, string $userDn, string $password): bool;
}
