<?php

// SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Infrastructure\Http;

final class StorePathResolver
{
    /**
     * @param array<string, mixed> $server
     */
    public static function resolve(string|false $environmentValue, array $server, string $projectRoot): string
    {
        if (is_string($environmentValue) && $environmentValue !== '') {
            return $environmentValue;
        }

        $serverValue = $server['INFRAREGISTER_STORE'] ?? null;

        if (is_string($serverValue) && $serverValue !== '') {
            return $serverValue;
        }

        return $projectRoot . '/var/assets.json';
    }
}
