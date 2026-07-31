<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RegisterAssetWebTest extends TestCase
{
    public function testItRegistersAnAssetThroughTheWebEntrypoint(): void
    {
        $root = dirname(__DIR__, 2);
        $store = $root . '/build/e2e/web-assets.json';
        @unlink($store);
        $port = $this->openPort();
        $url = sprintf('http://127.0.0.1:%d/assets/register', $port);
        $origin = sprintf('http://127.0.0.1:%d', $port);

        $server = new Process([
            $this->phpBinary(),
            '-S',
            sprintf('127.0.0.1:%d', $port),
            '-t',
            'public',
            'public/index.php',
        ], $root, [
            'INFRAREGISTER_STORE' => $store,
            'INFRAREGISTER_WRITE_AUTH' => 'writer:secret',
        ]);

        $server->start();

        try {
            $this->waitForServer($url);

            $response = file_get_contents($url, false, stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\nAuthorization: Basic " . base64_encode('writer:secret') . "\r\nOrigin: " . $origin . "\r\n",
                    'content' => http_build_query(['name' => 'Web Router 01']),
                ],
            ]));

            self::assertIsString($response);
            self::assertStringContainsString('Registered asset Web Router 01.', $response);
            self::assertFileExists($store);
        } finally {
            $server->stop(1);
        }
    }

    private function waitForServer(string $url): void
    {
        $deadline = microtime(true) + 5.0;

        do {
            $response = @file_get_contents($url);

            if (is_string($response)) {
                return;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        self::fail('Web server did not start in time.');
    }

    private function phpBinary(): string
    {
        if (PHP_SAPI === 'phpdbg') {
            return 'php';
        }

        return PHP_BINARY;
    }

    private function openPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        self::assertIsResource($socket);

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        self::assertIsString($name);
        $parts = explode(':', $name);
        $port = (int) end($parts);

        self::assertGreaterThan(0, $port);

        return $port;
    }
}
