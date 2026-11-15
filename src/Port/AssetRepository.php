<?php

// SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Port;

use RelenzWorks\InfraRegister\Domain\Asset\Asset;
use RelenzWorks\InfraRegister\Domain\Asset\AssetId;

interface AssetRepository
{
    public function save(Asset $asset): void;

    public function get(AssetId $id): ?Asset;

    /**
     * @return list<Asset>
     */
    public function all(): array;
}
