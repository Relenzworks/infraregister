<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Domain\Security;

use Symfony\Component\Security\Core\User\UserInterface;

final readonly class AuthenticatedUser implements UserInterface
{
    public string $username;

    /**
     * @var list<Role>
     */
    public array $roles;

    /**
     * @param list<Role> $roles
     */
    public function __construct(
        string $username,
        array $roles,
    ) {
        $this->username = $this->nonEmptyUsername($username);
        $this->roles = $roles;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return array_values(array_unique(array_map(
            static fn(Role $role): string => $role->securityRole(),
            $this->roles,
        )));
    }

    public function eraseCredentials(): void {}

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    /**
     * @return non-empty-string
     */
    private function nonEmptyUsername(string $username): string
    {
        if ($username === '') {
            throw new InvalidSecurityConfiguration('Authenticated username cannot be blank.');
        }

        return $username;
    }
}
