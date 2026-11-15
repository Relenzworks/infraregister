<?php

// SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Domain\Asset;

enum AssetStatus: string
{
    case InService = 'in_service';
    case InStorage = 'in_storage';
    case Retired = 'retired';
}
