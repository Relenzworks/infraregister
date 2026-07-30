<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RelenzWorks\InfraRegister\Infrastructure\Cli\RegisterAssetCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class RegisterAssetCommandTest extends TestCase
{
    public function testItRegistersAnAsset(): void
    {
        $workspace = $this->workspace('register-command');
        $store = $workspace . '/var/assets.json';
        @unlink($store);

        $tester = $this->runInWorkspace($workspace, static function (): CommandTester {
            $tester = new CommandTester(new RegisterAssetCommand());
            $tester->execute([
                'name' => 'Core Router 01',
                '--store' => 'var/assets.json',
            ]);

            return $tester;
        });

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Registered asset Core Router 01', $tester->getDisplay());
        self::assertFileExists($store);

        $contents = file_get_contents($store);

        self::assertIsString($contents);

        $records = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($records);
        $record = $records[0] ?? null;

        self::assertIsArray($record);
        self::assertSame('Core Router 01', $record['name'] ?? null);
    }

    public function testItCreatesNestedStoreDirectories(): void
    {
        $workspace = $this->workspace('nested-command');
        $store = $workspace . '/a/b/c/assets.json';
        @unlink($store);

        $tester = $this->runInWorkspace($workspace, static function (): CommandTester {
            $tester = new CommandTester(new RegisterAssetCommand());
            $tester->execute([
                'name' => 'Access Switch 01',
                '--store' => 'a/b/c/assets.json',
            ]);

            return $tester;
        });

        self::assertSame(0, $tester->getStatusCode());
        self::assertFileExists($store);

        $contents = file_get_contents($store);

        self::assertIsString($contents);
        self::assertStringContainsString('Access Switch 01', $contents);
    }

    public function testItEscapesSuccessfulAssetOutput(): void
    {
        $tester = $this->runInWorkspace($this->workspace('escaped-success-command'), static function (): CommandTester {
            $tester = new CommandTester(new RegisterAssetCommand());
            $tester->execute([
                'name' => '<info>Core</info>',
                '--store' => 'var/assets.json',
            ]);

            return $tester;
        });

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('&lt;info&gt;Core&lt;/info&gt;', $tester->getDisplay());
        self::assertStringNotContainsString('<info>Core</info>', $tester->getDisplay());
    }

    public function testItAppendsToAnExistingStore(): void
    {
        $workspace = $this->workspace('append-command');
        $store = $workspace . '/var/assets.json';

        $first = $this->runInWorkspace($workspace, static function (): CommandTester {
            $tester = new CommandTester(new RegisterAssetCommand());
            $tester->execute([
                'name' => 'Core Router 01',
                '--store' => 'var/assets.json',
            ]);

            return $tester;
        });

        $second = $this->runInWorkspace($workspace, static function (): CommandTester {
            $tester = new CommandTester(new RegisterAssetCommand());
            $tester->execute([
                'name' => 'Core Router 02',
                '--store' => 'var/assets.json',
            ]);

            return $tester;
        });

        self::assertSame(0, $first->getStatusCode());
        self::assertSame(0, $second->getStatusCode());

        $contents = file_get_contents($store);

        self::assertIsString($contents);

        $records = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($records);
        self::assertCount(2, $records);
        self::assertSame(['Core Router 01', 'Core Router 02'], array_column($records, 'name'));
    }

    public function testItReturnsInvalidForBlankNames(): void
    {
        $tester = $this->runInWorkspace($this->workspace('blank-command'), static function (): CommandTester {
            $tester = new CommandTester(new RegisterAssetCommand());
            $tester->execute(['name' => '   ']);

            return $tester;
        });

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('Asset name cannot be blank.', $tester->getDisplay());
    }

    public function testItReturnsInvalidWhenSymfonyProvidesInvalidInputTypes(): void
    {
        $command = new RegisterAssetCommand();
        $execute = new ReflectionMethod($command, 'execute');
        $input = new class ([]) extends ArrayInput {
            public function getArgument(string $name): mixed
            {
                return ['not a string'];
            }

            public function getOption(string $name): mixed
            {
                return 'var/assets.json';
            }
        };
        $output = new BufferedOutput();

        self::assertSame(2, $execute->invoke($command, $input, $output));
        self::assertStringContainsString('Invalid command input.', $output->fetch());
    }

    public function testItReturnsFailureForRejectedStorePaths(): void
    {
        $tester = $this->runInWorkspace($this->workspace('store-command'), static function (): CommandTester {
            $tester = new CommandTester(new RegisterAssetCommand());
            $tester->execute([
                'name' => 'Core Router 01',
                '--store' => 'phar://archive.phar/assets.json',
            ]);

            return $tester;
        });

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Store path must stay inside the current working directory.', $tester->getDisplay());
    }

    public function testItReturnsFailureForTraversingStorePaths(): void
    {
        $tester = $this->runInWorkspace($this->workspace('traversal-command'), static function (): CommandTester {
            $tester = new CommandTester(new RegisterAssetCommand());
            $tester->execute([
                'name' => 'Core Router 01',
                '--store' => '../outside.json',
            ]);

            return $tester;
        });

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Store path must stay inside the current working directory.', $tester->getDisplay());
    }

    public function testItReturnsFailureForAbsoluteStorePathsOutsideTheWorkspace(): void
    {
        $outsidePath = dirname($this->workspace('absolute-command')) . '/outside-assets.json';
        @unlink($outsidePath);

        $tester = $this->runInWorkspace($this->workspace('absolute-command'), static function () use ($outsidePath): CommandTester {
            $tester = new CommandTester(new RegisterAssetCommand());
            $tester->execute([
                'name' => 'Core Router 01',
                '--store' => $outsidePath,
            ]);

            return $tester;
        });

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Store path must stay inside the current working directory.', $tester->getDisplay());
        self::assertFileDoesNotExist($outsidePath);
    }

    public function testItReturnsFailureForEmptyStorePaths(): void
    {
        $tester = $this->runInWorkspace($this->workspace('empty-store-command'), static function (): CommandTester {
            $tester = new CommandTester(new RegisterAssetCommand());
            $tester->execute([
                'name' => 'Core Router 01',
                '--store' => '',
            ]);

            return $tester;
        });

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Store path must stay inside the current working directory.', $tester->getDisplay());
    }

    public function testItAcceptsRelativeStorePathsWithExistingAncestors(): void
    {
        $command = new RegisterAssetCommand();
        $storePath = new ReflectionMethod($command, 'storePath');
        $basePath = $this->workspace('store-path-command');

        $resolved = $storePath->invoke($command, 'var/assets.json', $basePath);

        self::assertSame($basePath . '/var/assets.json', $resolved);
    }

    public function testItEscapesStoreFailureMessages(): void
    {
        $workspace = $this->workspace('escaped-store-command');
        $blocker = $workspace . '/blocked<info>store';
        file_put_contents($blocker, 'not a directory');

        $tester = $this->runInWorkspace($workspace, static function (): CommandTester {
            $tester = new CommandTester(new RegisterAssetCommand());
            $tester->execute([
                'name' => 'Core Router 01',
                '--store' => 'blocked<info>store/assets.json',
            ]);

            return $tester;
        });

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Unable to open asset store lock:', $tester->getDisplay());
        self::assertStringContainsString('blocked&lt;info&gt;store', $tester->getDisplay());
        self::assertStringNotContainsString('blocked<info>store', $tester->getDisplay());
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function runInWorkspace(string $workspace, callable $callback): mixed
    {
        $previous = getcwd();

        self::assertIsString($previous);
        self::assertTrue(chdir($workspace));

        try {
            return $callback();
        } finally {
            self::assertTrue(chdir($previous));
        }
    }

    private function workspace(string $name): string
    {
        $workspace = dirname(__DIR__, 2) . '/build/command/' . $name;

        if (is_dir($workspace)) {
            (new Filesystem())->remove($workspace);
        }

        if (!is_dir($workspace)) {
            self::assertTrue(mkdir($workspace, 0o755, true));
        }

        self::assertDirectoryExists($workspace);

        return $workspace;
    }
}
