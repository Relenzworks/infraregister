<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Infrastructure\Security;

use RelenzWorks\InfraRegister\Domain\Security\AuthenticatedUser;
use RelenzWorks\InfraRegister\Domain\Security\InvalidSecurityConfiguration;
use RelenzWorks\InfraRegister\Domain\Security\Role;
use RelenzWorks\InfraRegister\Port\UserDirectory;

final readonly class LocalUserDirectory implements UserDirectory
{
    /**
     * @param array<string, array{password: string, roles: list<Role>}> $users
     */
    public function __construct(private array $users) {}

    public static function fromLegacyWriteAuth(?string $writeAuth): ?self
    {
        if ($writeAuth === null || !str_contains($writeAuth, ':')) {
            return null;
        }

        [$username, $password] = explode(':', $writeAuth, 2);

        if ($username === '' || $password === '') {
            return null;
        }

        return new self([
            $username => [
                'password' => $password,
                'roles' => [Role::AssetManager],
            ],
        ]);
    }

    public static function fromConfiguration(?string $configuration): ?self
    {
        if ($configuration === null || trim($configuration) === '') {
            return null;
        }

        $users = [];

        foreach (explode(';', $configuration) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            if (!str_contains($entry, '=') || !str_contains($entry, ':')) {
                throw new InvalidSecurityConfiguration('Local user entries must use username:password=role[,role] format.');
            }

            [$credentials, $roles] = explode('=', $entry, 2);
            [$username, $password] = explode(':', $credentials, 2);
            $username = trim($username);

            if ($username === '' || $password === '') {
                throw new InvalidSecurityConfiguration('Local user credentials cannot be blank.');
            }

            $users[$username] = [
                'password' => $password,
                'roles' => self::parseRoles($roles),
            ];
        }

        return $users === [] ? null : new self($users);
    }

    public function authenticate(string $username, string $password): ?AuthenticatedUser
    {
        $user = $this->users[$username] ?? null;

        if ($user === null || !hash_equals($user['password'], $password)) {
            return null;
        }

        return new AuthenticatedUser($username, $user['roles']);
    }

    /**
     * @return list<Role>
     */
    private static function parseRoles(string $roles): array
    {
        $parsed = [];

        foreach (explode(',', $roles) as $role) {
            $role = Role::tryFrom(trim($role));

            if ($role === null) {
                throw new InvalidSecurityConfiguration('Local user configuration contains an unknown role.');
            }

            $parsed[] = $role;
        }

        return $parsed;
    }
}
