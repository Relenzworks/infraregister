<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use RelenzWorks\InfraRegister\Domain\Asset\Asset;
use RelenzWorks\InfraRegister\Domain\Asset\AssetId;
use RelenzWorks\InfraRegister\Domain\Asset\AssetName;
use RelenzWorks\InfraRegister\Domain\Asset\AssetStatus;
use RelenzWorks\InfraRegister\Infrastructure\Persistence\AssetStoreUnavailable;
use RelenzWorks\InfraRegister\Infrastructure\Persistence\JsonAssetRepository;

final class JsonAssetRepositoryTest extends TestCase
{
    public function testItPersistsAndRetrievesAssets(): void
    {
        $path = $this->storePath('persisted-assets.json');
        $repository = new JsonAssetRepository($path);
        $asset = Asset::register(AssetId::generate(), AssetName::fromString('Aggregation Switch'));

        $repository->save($asset);

        $stored = $repository->get($asset->id);

        self::assertNotNull($stored);
        self::assertSame($asset->id->value, $stored->id->value);
        self::assertSame('Aggregation Switch', $stored->name->value);
    }

    public function testItReturnsNullWhenTheAssetIsMissing(): void
    {
        $repository = new JsonAssetRepository($this->storePath('missing-assets.json'));

        self::assertNull($repository->get(AssetId::generate()));
    }

    public function testItReturnsNullBeforeTheStoreDirectoryExists(): void
    {
        $path = $this->directory('missing-store-directory-root') . '/nested/assets.json';
        $repository = new JsonAssetRepository($path);

        self::assertNull($repository->get(AssetId::generate()));
        self::assertFileDoesNotExist($path . '.lock');
    }

    public function testItRefusesToOverwriteInvalidJsonWhenSaving(): void
    {
        $path = $this->storePath('invalid-json.json');
        file_put_contents($path, '{');

        $repository = new JsonAssetRepository($path);
        $asset = Asset::register(AssetId::generate(), AssetName::fromString('Distribution Router'));

        $this->expectException(AssetStoreUnavailable::class);

        $repository->save($asset);
    }

    public function testItRefusesToOverwriteMalformedRecordsWhenSaving(): void
    {
        $path = $this->storePath('malformed-save-records.json');
        file_put_contents($path, json_encode([['id' => 123]], JSON_THROW_ON_ERROR));

        $repository = new JsonAssetRepository($path);
        $asset = Asset::register(AssetId::generate(), AssetName::fromString('Distribution Router'));

        $this->expectException(AssetStoreUnavailable::class);

        $repository->save($asset);
    }

    public function testItRefusesToOverwriteInvalidSemanticRecordsWhenSaving(): void
    {
        $path = $this->storePath('semantic-save-records.json');
        file_put_contents($path, json_encode([[
            'id' => 'not-a-uuid',
            'name' => 'Distribution Router',
            'status' => 'in_service',
        ]], JSON_THROW_ON_ERROR));

        $repository = new JsonAssetRepository($path);
        $asset = Asset::register(AssetId::generate(), AssetName::fromString('Distribution Router'));

        $this->expectException(AssetStoreUnavailable::class);

        $repository->save($asset);
    }

    public function testItRefusesToOverwriteNonArrayStoresWhenSaving(): void
    {
        $path = $this->storePath('scalar-save-records.json');
        file_put_contents($path, json_encode('not a record list', JSON_THROW_ON_ERROR));

        $repository = new JsonAssetRepository($path);
        $asset = Asset::register(AssetId::generate(), AssetName::fromString('Distribution Router'));

        $this->expectException(AssetStoreUnavailable::class);

        $repository->save($asset);
    }

    public function testItWrapsDirectoryCreationFailures(): void
    {
        $path = $this->storePath('directory-blocker');
        file_put_contents($path, 'not a directory');

        $repository = new JsonAssetRepository($path . '/nested/assets.json');
        $asset = Asset::register(AssetId::generate(), AssetName::fromString('Distribution Router'));

        $this->expectException(AssetStoreUnavailable::class);

        $repository->save($asset);
    }

    public function testItIgnoresMalformedRecordsWhenReading(): void
    {
        $path = $this->storePath('malformed-records.json');
        file_put_contents($path, json_encode([['id' => 123], 'not a record'], JSON_THROW_ON_ERROR));

        $repository = new JsonAssetRepository($path);

        self::assertNull($repository->get(AssetId::generate()));
    }

    public function testItIgnoresInvalidJsonWhenReading(): void
    {
        $path = $this->storePath('invalid-read-json.json');
        file_put_contents($path, '{');

        $repository = new JsonAssetRepository($path);

        self::assertNull($repository->get(AssetId::generate()));
    }

    public function testItIgnoresNonArrayStoresWhenReading(): void
    {
        $path = $this->storePath('scalar-read-records.json');
        file_put_contents($path, json_encode('not a record list', JSON_THROW_ON_ERROR));

        $repository = new JsonAssetRepository($path);

        self::assertNull($repository->get(AssetId::generate()));
    }

    public function testItTreatsEmptyStoresAsEmpty(): void
    {
        $path = $this->storePath('empty-read-records.json');
        file_put_contents($path, " \n ");

        $repository = new JsonAssetRepository($path);

        self::assertNull($repository->get(AssetId::generate()));
    }

    public function testItIgnoresInvalidStatusesWhenReading(): void
    {
        $path = $this->storePath('invalid-status-records.json');
        file_put_contents($path, json_encode([[
            'id' => AssetId::generate()->value,
            'name' => 'Warehouse Spare',
            'status' => 'unknown',
        ]], JSON_THROW_ON_ERROR));

        $repository = new JsonAssetRepository($path);

        self::assertNull($repository->get(AssetId::generate()));
    }

    public function testItIgnoresSemanticallyInvalidRecordsWhenReading(): void
    {
        $path = $this->storePath('invalid-content-records.json');
        file_put_contents($path, json_encode([[
            'id' => 'not-a-uuid',
            'name' => '',
            'status' => 'in_service',
        ]], JSON_THROW_ON_ERROR));

        $repository = new JsonAssetRepository($path);

        self::assertNull($repository->get(AssetId::generate()));
    }

    public function testItReadsKnownNonDefaultStatuses(): void
    {
        $path = $this->storePath('known-status-records.json');
        $id = AssetId::generate();
        file_put_contents($path, json_encode([[
            'id' => $id->value,
            'name' => 'Warehouse Spare',
            'status' => 'in_storage',
        ]], JSON_THROW_ON_ERROR));

        $repository = new JsonAssetRepository($path);
        $asset = $repository->get($id);

        self::assertNotNull($asset);
        self::assertSame(AssetStatus::InStorage, $asset->status);
    }

    public function testItEnforcesConfiguredBasePath(): void
    {
        $basePath = $this->directory('base-path');
        $outsidePath = dirname($basePath) . '/outside-base/assets.json';
        $repository = new JsonAssetRepository($outsidePath, $basePath);
        $asset = Asset::register(AssetId::generate(), AssetName::fromString('Distribution Router'));

        $this->expectException(AssetStoreUnavailable::class);

        $repository->save($asset);
    }

    public function testItRejectsStreamWrappersWhenABasePathIsConfigured(): void
    {
        $basePath = $this->directory('stream-base-path');
        $repository = new JsonAssetRepository('phar://archive.phar/assets.json', $basePath);
        $asset = Asset::register(AssetId::generate(), AssetName::fromString('Distribution Router'));

        $this->expectException(AssetStoreUnavailable::class);

        $repository->save($asset);
    }

    public function testItReadsAssetsInsideConfiguredBasePath(): void
    {
        $basePath = $this->directory('inside-base-path');
        $path = $basePath . '/var/assets.json';
        $repository = new JsonAssetRepository($path, $basePath);
        $asset = Asset::register(AssetId::generate(), AssetName::fromString('Distribution Router'));

        $repository->save($asset);

        self::assertNotNull($repository->get($asset->id));
    }

    private function storePath(string $name): string
    {
        $directory = $this->directory('integration');

        $path = $directory . '/' . $name;
        @unlink($path);

        return $path;
    }

    private function directory(string $name): string
    {
        $directory = dirname(__DIR__, 2) . '/build/' . $name;

        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0o755, true));
        }

        self::assertDirectoryExists($directory);

        return $directory;
    }
}
