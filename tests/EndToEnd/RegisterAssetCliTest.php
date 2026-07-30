<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RegisterAssetCliTest extends TestCase
{
    public function testItRegistersAnAssetThroughTheCli(): void
    {
        $store = dirname(__DIR__, 2) . '/build/e2e/assets.json';
        @unlink($store);

        $process = new Process([
            $this->phpBinary(),
            dirname(__DIR__, 2) . '/bin/infraregister',
            'asset:register',
            'Core Router 01',
            '--store',
            $store,
        ]);

        $process->mustRun();

        self::assertStringContainsString('Registered asset Core Router 01', $process->getOutput());
        self::assertFileExists($store);

        $contents = file_get_contents($store);

        self::assertIsString($contents);

        $records = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($records);
        self::assertCount(1, $records);

        $record = $records[0] ?? null;

        self::assertIsArray($record);
        self::assertSame('Core Router 01', $record['name'] ?? null);
        self::assertSame('in_service', $record['status'] ?? null);
        self::assertIsString($record['id'] ?? null);
    }

    public function testItRejectsStorePathsOutsideTheWorkingTree(): void
    {
        $process = new Process([
            $this->phpBinary(),
            dirname(__DIR__, 2) . '/bin/infraregister',
            'asset:register',
            'Core Router 01',
            '--store',
            '../outside.json',
        ], dirname(__DIR__, 2));

        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Store path must stay inside the current working directory.', $process->getOutput());
    }

    public function testItRejectsStreamWrapperStorePathsBeforeFilesystemUse(): void
    {
        $process = new Process([
            $this->phpBinary(),
            dirname(__DIR__, 2) . '/bin/infraregister',
            'asset:register',
            'Core Router 01',
            '--store',
            'phar://archive.phar/assets.json',
        ], dirname(__DIR__, 2));

        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Store path must stay inside the current working directory.', $process->getOutput());
    }

    public function testItEscapesConsoleMarkupInErrorMessages(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root . '/build/e2e/<error>x</error>';

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }

        file_put_contents($path, 'not a directory');

        $process = new Process([
            $this->phpBinary(),
            $root . '/bin/infraregister',
            'asset:register',
            'Core Router 01',
            '--store',
            'build/e2e/<error>x</error>/assets.json',
        ], $root);

        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('&lt;error&gt;x&lt;/error&gt;', $process->getOutput());
        self::assertStringNotContainsString('<error>x</error>', $process->getOutput());
        self::assertStringNotContainsString("\033[", $process->getOutput());
    }

    public function testItEscapesConsoleMarkupInSuccessMessages(): void
    {
        $store = dirname(__DIR__, 2) . '/build/e2e/markup-success-assets.json';
        @unlink($store);

        $process = new Process([
            $this->phpBinary(),
            dirname(__DIR__, 2) . '/bin/infraregister',
            'asset:register',
            'Core Router <error>FAKE</error>',
            '--store',
            $store,
        ], dirname(__DIR__, 2));

        $process->mustRun();

        self::assertStringContainsString('Core Router &lt;error&gt;FAKE&lt;/error&gt;', $process->getOutput());
        self::assertStringNotContainsString('Core Router <error>FAKE</error>', $process->getOutput());
        self::assertStringNotContainsString("\033[", $process->getOutput());
    }

    public function testItRejectsSymlinkedStorePathsOutsideTheWorkingTree(): void
    {
        $root = dirname(__DIR__, 2);
        $link = $root . '/build/e2e/outside-link';
        $outside = sys_get_temp_dir() . '/infraregister-outside-store';

        if (!is_dir(dirname($link))) {
            mkdir(dirname($link), 0o755, true);
        }

        if (!is_dir($outside)) {
            mkdir($outside, 0o755, true);
        }

        @unlink($link);
        symlink($outside, $link);

        $process = new Process([
            $this->phpBinary(),
            $root . '/bin/infraregister',
            'asset:register',
            'Core Router 01',
            '--store',
            'build/e2e/outside-link/assets.json',
        ], $root);

        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Store path must stay inside the current working directory.', $process->getOutput());
    }

    public function testItRejectsBlankAssetNames(): void
    {
        $process = new Process([
            $this->phpBinary(),
            dirname(__DIR__, 2) . '/bin/infraregister',
            'asset:register',
            '   ',
        ], dirname(__DIR__, 2));

        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('Asset name cannot be blank.', $process->getOutput());
    }

    public function testItRejectsInvalidUtf8AssetNamesWithoutAStackTrace(): void
    {
        $process = new Process([
            $this->phpBinary(),
            dirname(__DIR__, 2) . '/bin/infraregister',
            'asset:register',
            "asset\xFF",
        ], dirname(__DIR__, 2));

        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('Asset name must be valid UTF-8.', $process->getOutput());
        self::assertStringNotContainsString('Stack trace', $process->getErrorOutput() . $process->getOutput());
    }

    public function testConcurrentRegistrationsDoNotLoseUpdates(): void
    {
        $store = dirname(__DIR__, 2) . '/build/e2e/concurrent-assets.json';
        @unlink($store);
        $processes = [];

        foreach (range(1, 5) as $index) {
            $processes[] = new Process([
                $this->phpBinary(),
                dirname(__DIR__, 2) . '/bin/infraregister',
                'asset:register',
                sprintf('Access Switch %02d', $index),
                '--store',
                $store,
            ], dirname(__DIR__, 2));
        }

        foreach ($processes as $process) {
            $process->start();
        }

        foreach ($processes as $process) {
            $process->wait();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        }

        $contents = file_get_contents($store);

        self::assertIsString($contents);

        $records = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($records);
        self::assertCount(5, $records);
    }

    private function phpBinary(): string
    {
        if (PHP_SAPI !== 'phpdbg') {
            return PHP_BINARY;
        }

        return 'php';
    }
}
