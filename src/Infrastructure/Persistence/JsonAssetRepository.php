<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Infrastructure\Persistence;

use InvalidArgumentException;
use JsonException;
use RelenzWorks\InfraRegister\Domain\Asset\Asset;
use RelenzWorks\InfraRegister\Domain\Asset\AssetId;
use RelenzWorks\InfraRegister\Domain\Asset\AssetName;
use RelenzWorks\InfraRegister\Domain\Asset\AssetStatus;
use RelenzWorks\InfraRegister\Port\AssetRepository;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class JsonAssetRepository implements AssetRepository
{
    private readonly Filesystem $filesystem;

    public function __construct(
        private readonly string $path,
        private readonly ?string $basePath = null,
    ) {
        $this->filesystem = new Filesystem();
    }

    public function save(Asset $asset): void
    {
        $directory = dirname($this->path);

        $this->enforcePathBoundary($this->path);

        $this->createDirectory($directory);

        $this->enforcePathBoundary($this->path);

        $this->withLock(true, function () use ($asset): void {
            $assets = $this->readAssetsFromDisk(true);
            $assets[$asset->id->value] = [
                'id' => $asset->id->value,
                'name' => $asset->name->value,
                'status' => $asset->status->value,
            ];

            $payload = json_encode(array_values($assets), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";

            $this->replaceStoreAtomically($payload);
        });
    }

    public function get(AssetId $id): ?Asset
    {
        $assets = $this->readAssets();
        $record = $assets[$id->value] ?? null;

        if ($record === null) {
            return null;
        }

        return Asset::reconstitute(
            AssetId::fromString($record['id']),
            AssetName::fromString($record['name']),
            AssetStatus::from($record['status']),
        );
    }

    private function replaceStoreAtomically(string $payload): void
    {
        $directory = dirname($this->path);
        $this->enforcePathBoundary($this->path);
        $temporaryPath = tempnam($directory, '.infraregister.');

        // @codeCoverageIgnoreStart
        if ($temporaryPath === false) {
            throw new AssetStoreUnavailable(sprintf('Unable to create temporary asset store in: %s', $directory));
        }
        // @codeCoverageIgnoreEnd

        try {
            $bytesWritten = file_put_contents($temporaryPath, $payload, LOCK_EX);

            // @codeCoverageIgnoreStart
            if ($bytesWritten !== strlen($payload)) {
                throw new AssetStoreUnavailable(sprintf('Unable to write complete asset store: %s', $temporaryPath));
            }
            // @codeCoverageIgnoreEnd

            $this->enforcePathBoundary($this->path);

            // Atomicity relies on POSIX rename semantics within one local filesystem.
            // @codeCoverageIgnoreStart
            if (!rename($temporaryPath, $this->path)) {
                throw new AssetStoreUnavailable(sprintf('Unable to replace asset store: %s', $this->path));
            }
            // @codeCoverageIgnoreEnd
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function createDirectory(string $directory): void
    {
        if ($this->filesystem->exists($directory)) {
            return;
        }

        try {
            $this->filesystem->mkdir($directory, 0o755);
        } catch (IOException $exception) {
            throw new AssetStoreUnavailable(sprintf('Unable to create asset store directory: %s', $directory), 0, $exception);
        }
    }

    /**
     * @return array<string, array{id: string, name: string, status: string}>
     */
    private function readAssets(): array
    {
        $this->enforcePathBoundary($this->path);

        if (!is_file($this->path)) {
            return [];
        }

        return $this->withLock(false, fn(): array => $this->readAssetsFromDisk(false));
    }

    /**
     * @return array<string, array{id: string, name: string, status: string}>
     */
    private function readAssetsFromDisk(bool $failOnInvalid): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $this->enforcePathBoundary($this->path);

        $contents = file_get_contents($this->path);

        if ($contents === false || trim($contents) === '') {
            return [];
        }

        return $this->readAssetsFromJson($contents, $failOnInvalid);
    }

    /**
     * @return array<string, array{id: string, name: string, status: string}>
     */
    private function readAssetsFromJson(string $contents, bool $failOnInvalid): array
    {
        try {
            $records = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            if ($failOnInvalid) {
                throw new AssetStoreUnavailable('Asset store contains invalid JSON.', 0, $exception);
            }

            return [];
        }

        if (!is_array($records)) {
            if ($failOnInvalid) {
                throw new AssetStoreUnavailable('Asset store root must be a JSON array.');
            }

            return [];
        }

        $assets = [];

        foreach ($records as $record) {
            if (!$this->isAssetRecord($record)) {
                if ($failOnInvalid) {
                    throw new AssetStoreUnavailable('Asset store contains malformed asset records.');
                }

                continue;
            }

            $assets[$record['id']] = $record;
        }

        return $assets;
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withLock(bool $exclusive, callable $callback): mixed
    {
        $lockPath = $this->path . '.lock';
        $this->enforcePathBoundary($lockPath);
        $handle = $this->openLockFile($lockPath);
        $locked = false;

        try {
            // @codeCoverageIgnoreStart
            if (!flock($handle, $exclusive ? LOCK_EX : LOCK_SH)) {
                throw new AssetStoreUnavailable(sprintf('Unable to lock asset store: %s', $lockPath));
            }
            // @codeCoverageIgnoreEnd

            $locked = true;

            return $callback();
        } finally {
            if ($locked) {
                flock($handle, LOCK_UN);
            }

            fclose($handle);
        }
    }

    /**
     * @return resource
     */
    private function openLockFile(string $lockPath): mixed
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $handle = fopen($lockPath, 'c+b');
        } finally {
            restore_error_handler();
        }

        if ($handle === false) {
            $reason = $warning === null ? 'unknown reason' : $warning;

            throw new AssetStoreUnavailable(sprintf('Unable to open asset store lock: %s (%s)', $lockPath, $reason));
        }

        return $handle;
    }

    /**
     * @phpstan-assert-if-true array{id: string, name: string, status: string} $record
     */
    private function isAssetRecord(mixed $record): bool
    {
        if (
            !is_array($record)
            || !isset($record['id'], $record['name'], $record['status'])
            || !is_string($record['id'])
            || !is_string($record['name'])
            || !is_string($record['status'])
        ) {
            return false;
        }

        try {
            AssetId::fromString($record['id']);
            AssetName::fromString($record['name']);
        } catch (InvalidArgumentException) {
            return false;
        }

        return AssetStatus::tryFrom($record['status']) !== null;
    }

    private function enforcePathBoundary(string $path): void
    {
        if ($this->basePath === null) {
            return;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $path) === 1) {
            throw new AssetStoreUnavailable('Asset store path cannot use a stream wrapper.');
        }

        $candidate = Path::canonicalize($path);
        $ancestor = $this->existingAncestor($candidate);

        // @codeCoverageIgnoreStart
        if ($ancestor === null) {
            throw new AssetStoreUnavailable('Asset store path does not have a resolvable ancestor.');
        }
        // @codeCoverageIgnoreEnd

        $realAncestor = realpath($ancestor);

        if ($realAncestor === false || !Path::isBasePath($this->basePath, $realAncestor)) {
            throw new AssetStoreUnavailable('Asset store path must stay inside the configured base path.');
        }

        // @codeCoverageIgnoreStart
        if (!Path::isBasePath($this->basePath, $candidate)) {
            throw new AssetStoreUnavailable('Asset store path must stay inside the configured base path.');
        }
        // @codeCoverageIgnoreEnd
    }

    private function existingAncestor(string $path): ?string
    {
        $candidate = $path;

        while (!file_exists($candidate)) {
            $parent = dirname($candidate);

            // @codeCoverageIgnoreStart
            if ($parent === $candidate) {
                return null;
            }
            // @codeCoverageIgnoreEnd

            $candidate = $parent;
        }

        return $candidate;
    }
}
