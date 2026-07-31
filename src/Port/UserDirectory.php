<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Port;

use RelenzWorks\InfraRegister\Domain\Security\AuthenticatedUser;

interface UserDirectory
{
    public function authenticate(string $username, string $password): ?AuthenticatedUser;
}
