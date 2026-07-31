<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Application\Security;

use RelenzWorks\InfraRegister\Domain\Security\AuthenticatedUser;
use RelenzWorks\InfraRegister\Domain\Security\Permission;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\UserAuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\RoleVoter;

final class AccessPolicy
{
    private readonly UserAuthorizationCheckerInterface $authorization;

    public function __construct(?UserAuthorizationCheckerInterface $authorization = null)
    {
        $this->authorization = $authorization ?? new AuthorizationChecker(
            new TokenStorage(),
            new AccessDecisionManager([new RoleVoter()]),
        );
    }

    public function allows(AuthenticatedUser $user, Permission $permission): bool
    {
        foreach ($permission->requiredSecurityRoles() as $role) {
            if ($this->authorization->isGrantedForUser($user, $role)) {
                return true;
            }
        }

        return false;
    }
}
