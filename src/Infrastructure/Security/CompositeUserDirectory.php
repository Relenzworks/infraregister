<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Infrastructure\Security;

use RelenzWorks\InfraRegister\Domain\Security\AuthenticatedUser;
use RelenzWorks\InfraRegister\Port\UserDirectory;

final readonly class CompositeUserDirectory implements UserDirectory
{
    /**
     * @param list<UserDirectory> $directories
     */
    public function __construct(private array $directories) {}

    public function authenticate(string $username, string $password): ?AuthenticatedUser
    {
        foreach ($this->directories as $directory) {
            $user = $directory->authenticate($username, $password);

            if ($user !== null) {
                return $user;
            }
        }

        return null;
    }
}
