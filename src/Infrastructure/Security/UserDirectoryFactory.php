<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Infrastructure\Security;

use RelenzWorks\InfraRegister\Port\UserDirectory;

final class UserDirectoryFactory
{
    /**
     * @param array<string, string|false> $environment
     */
    public static function fromEnvironment(array $environment): ?UserDirectory
    {
        $directories = [];
        $local = LocalUserDirectory::fromConfiguration(self::optional($environment, 'INFRAREGISTER_LOCAL_USERS'))
            ?? LocalUserDirectory::fromLegacyWriteAuth(self::optional($environment, 'INFRAREGISTER_WRITE_AUTH'));

        if ($local !== null) {
            $directories[] = $local;
        }

        $ldapConfiguration = LdapConfiguration::fromEnvironment($environment);

        if ($ldapConfiguration !== null) {
            $directories[] = new LdapUserDirectory($ldapConfiguration, new NativeLdapClient());
        }

        return match (count($directories)) {
            0 => null,
            1 => $directories[0],
            default => new CompositeUserDirectory($directories),
        };
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
