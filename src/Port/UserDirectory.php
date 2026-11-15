<?php

// SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Port;

use RelenzWorks\InfraRegister\Domain\Security\AuthenticatedUser;

interface UserDirectory
{
    public function authenticate(string $username, string $password): ?AuthenticatedUser;
}
