<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use RelenzWorks\InfraRegister\Domain\Security\AuthenticatedUser;
use RelenzWorks\InfraRegister\Domain\Security\Role;
use RelenzWorks\InfraRegister\Infrastructure\Security\CompositeUserDirectory;
use RelenzWorks\InfraRegister\Port\UserDirectory;

final class CompositeUserDirectoryTest extends TestCase
{
    public function testItReturnsTheFirstDirectoryMatch(): void
    {
        $directory = new CompositeUserDirectory([
            new StaticUserDirectory(null),
            new StaticUserDirectory(new AuthenticatedUser('alice', [Role::Operator])),
        ]);

        self::assertSame(Role::Operator, $directory->authenticate('alice', 'secret')?->roles[0]);
    }

    public function testItReturnsNullWhenNoDirectoryMatches(): void
    {
        $directory = new CompositeUserDirectory([
            new StaticUserDirectory(null),
        ]);

        self::assertNull($directory->authenticate('alice', 'secret'));
    }
}

final readonly class StaticUserDirectory implements UserDirectory
{
    public function __construct(private ?AuthenticatedUser $user) {}

    public function authenticate(string $username, string $password): ?AuthenticatedUser
    {
        return $this->user;
    }
}
