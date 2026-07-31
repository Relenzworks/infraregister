<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Tests\Infrastructure;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RelenzWorks\InfraRegister\Infrastructure\Http\AssetWebApp;
use Symfony\Component\HttpFoundation\Request;

final class AssetWebAppTest extends TestCase
{
    public function testItRendersTheRegistrationForm(): void
    {
        $response = $this->app('form')->handle(Request::create('/assets/register'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<a class="skip-link" href="#content">Skip to content</a>', (string) $response->getContent());
        self::assertStringContainsString('<header class="app-header">', (string) $response->getContent());
        self::assertStringContainsString('<main id="content">', (string) $response->getContent());
        self::assertStringContainsString('<section class="panel" aria-labelledby="registration-title">', (string) $response->getContent());
        self::assertStringContainsString('<nav class="sidebar" aria-label="Primary navigation">', (string) $response->getContent());
        self::assertStringContainsString('<a class="nav-link" href="/assets"', (string) $response->getContent());
        self::assertStringContainsString('<a class="button-link" href="/assets/register">Register Asset</a>', (string) $response->getContent());
        self::assertStringNotContainsString('global-search', (string) $response->getContent());
        self::assertStringContainsString('<form method="post" action="/assets/register" novalidate>', (string) $response->getContent());
        self::assertStringContainsString('aria-describedby="asset-name-requirements"', (string) $response->getContent());
        self::assertStringContainsString('Register Asset', (string) $response->getContent());
    }

    public function testItAllowsReadsWithoutConfiguredWriteProtection(): void
    {
        $path = $this->storePath('public-read');
        $response = AssetWebApp::fromStore($path, dirname($path))->handle(Request::create('/assets'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>Asset Index</h1>', (string) $response->getContent());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function screenProvider(): iterable
    {
        yield 'dashboard' => ['/', 'Operations Overview'];
        yield 'assets' => ['/assets', 'Asset Index'];
        yield 'register' => ['/assets/register', 'Asset Registration'];
        yield 'network' => ['/network', 'Network Inventory'];
        yield 'locations' => ['/locations', 'Location Directory'];
        yield 'custody' => ['/custody', 'Custody Queue'];
        yield 'procurement' => ['/procurement', 'Procurement and Receiving'];
        yield 'contracts' => ['/contracts', 'Contracts and Warranty'];
        yield 'maintenance' => ['/maintenance', 'Maintenance Work'];
        yield 'monitoring' => ['/monitoring', 'Monitoring Links'];
        yield 'reports' => ['/reports', 'Report Library'];
        yield 'admin' => ['/admin', 'Administration'];
    }

    #[DataProvider('screenProvider')]
    public function testItRendersApplicationScreens(string $path, string $title): void
    {
        $response = $this->app('screen-' . trim(str_replace('/', '-', $path), '-'))->handle(Request::create($path));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(sprintf('<h1>%s</h1>', $title), (string) $response->getContent());
        self::assertStringContainsString('aria-label="Primary navigation"', (string) $response->getContent());
        self::assertStringContainsString(sprintf('href="%s" aria-current="page"', $path), (string) $response->getContent());
    }

    public function testItNormalizesTrailingSlashesForScreens(): void
    {
        $response = $this->app('trailing-slash')->handle(Request::create('/assets/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>Asset Index</h1>', (string) $response->getContent());
        self::assertStringContainsString('href="/assets" aria-current="page"', (string) $response->getContent());
    }

    public function testItRegistersAnAssetFromPostData(): void
    {
        $path = $this->storePath('post');
        $response = AssetWebApp::fromStore($path, dirname($path), 'writer:secret')->handle($this->postRegister([
            'name' => 'Core Router 01',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('role="status"', (string) $response->getContent());
        self::assertStringContainsString('Registered asset Core Router 01.', (string) $response->getContent());
        self::assertFileExists($path);

        $contents = file_get_contents($path);

        self::assertIsString($contents);
        self::assertStringContainsString('Core Router 01', $contents);
    }

    public function testItRegistersAShortAssetName(): void
    {
        $response = $this->app('short-name')->handle($this->postRegister([
            'name' => 'server-01',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Registered asset server-01.', (string) $response->getContent());
    }

    public function testItEscapesRegisteredAssetNames(): void
    {
        $response = $this->app('escaped')->handle($this->postRegister([
            'name' => '<script>alert("asset")</script>',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('&lt;script&gt;alert(&quot;asset&quot;)&lt;/script&gt;', (string) $response->getContent());
        self::assertStringNotContainsString('<script>alert("asset")</script>', (string) $response->getContent());
    }

    public function testItRegistersAssetNamesOneCharacterBelowTheMaximumLength(): void
    {
        $name = str_repeat('A', 119);
        $response = $this->app('under-max-length')->handle($this->postRegister([
            'name' => $name,
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(sprintf('Registered asset %s.', $name), (string) $response->getContent());
    }

    public function testItRegistersAssetNamesAtTheMaximumLength(): void
    {
        $name = str_repeat('A', 120);
        $response = $this->app('max-length')->handle($this->postRegister([
            'name' => $name,
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(sprintf('Registered asset %s.', $name), (string) $response->getContent());
    }

    public function testItRejectsAssetNamesOverTheMaximumLength(): void
    {
        $response = $this->app('over-max-length')->handle($this->postRegister([
            'name' => str_repeat('A', 121),
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('role="alert"', (string) $response->getContent());
        self::assertStringContainsString('aria-describedby="asset-name-requirements asset-name-error"', (string) $response->getContent());
        self::assertStringContainsString('Asset name cannot exceed 120 characters.', (string) $response->getContent());
    }

    public function testItRejectsArrayAssetNames(): void
    {
        $response = $this->app('array-name')->handle($this->postRegister([
            'name' => ['server-01'],
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('role="alert"', (string) $response->getContent());
        self::assertStringContainsString('Asset name is required.', (string) $response->getContent());
    }

    public function testItRejectsBlankAssetNames(): void
    {
        $response = $this->app('blank')->handle($this->postRegister([
            'name' => '   ',
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('role="alert"', (string) $response->getContent());
        self::assertStringContainsString('aria-describedby="asset-name-requirements asset-name-error"', (string) $response->getContent());
        self::assertStringContainsString('Asset name cannot be blank.', (string) $response->getContent());
    }

    public function testItRejectsMissingAssetNames(): void
    {
        $response = $this->app('missing')->handle($this->postRegister());

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Asset name is required.', (string) $response->getContent());
    }

    public function testItStillAcceptsLegacyRootRegistrationPosts(): void
    {
        $response = $this->app('legacy-root-post')->handle($this->postRegister([
            'name' => 'Legacy Router',
        ], '/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Registered asset Legacy Router.', (string) $response->getContent());
    }

    public function testItRejectsRegistrationWithoutConfiguredWriteProtection(): void
    {
        $path = $this->storePath('no-write-config');
        $response = AssetWebApp::fromStore($path, dirname($path))->handle(Request::create('/assets/register', 'POST', [
            'name' => 'Core Router 01',
        ]));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Asset registration requires configured write protection.', $response->getContent());
        self::assertFileDoesNotExist($path);
    }

    public function testItRejectsRegistrationWithInvalidWriteCredentials(): void
    {
        $response = $this->app('bad-write-auth')->handle(Request::create('/assets/register', 'POST', [
            'name' => 'Core Router 01',
        ], [], [], [
            'PHP_AUTH_USER' => 'writer',
            'PHP_AUTH_PW' => 'wrong',
        ]));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Basic realm="InfraRegister"', $response->headers->get('WWW-Authenticate'));
        self::assertSame('Authentication Required', $response->getContent());
    }

    public function testItRejectsRegistrationWhenWriteCredentialsAreMissingFromTheRequest(): void
    {
        $response = $this->app('missing-write-auth')->handle(Request::create('/assets/register', 'POST', [
            'name' => 'Core Router 01',
        ]));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Basic realm="InfraRegister"', $response->headers->get('WWW-Authenticate'));
        self::assertSame('Authentication Required', $response->getContent());
    }

    public function testItRejectsRegistrationWithMalformedWriteAuthConfiguration(): void
    {
        $path = $this->storePath('malformed-write-config');
        $response = AssetWebApp::fromStore($path, dirname($path), 'malformed')->handle($this->postRegister([
            'name' => 'Core Router 01',
        ]));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Asset registration requires configured write protection.', $response->getContent());
        self::assertFileDoesNotExist($path);
    }

    public function testItRejectsCrossOriginRegistrationPosts(): void
    {
        $response = $this->app('cross-origin')->handle($this->postRegister([
            'name' => 'Core Router 01',
        ], '/assets/register', [
            'HTTP_ORIGIN' => 'http://evil.example',
        ]));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Cross-origin registration requests are not allowed.', $response->getContent());
    }

    public function testItRejectsMalformedOriginRegistrationPosts(): void
    {
        $response = $this->app('malformed-origin')->handle($this->postRegister([
            'name' => 'Core Router 01',
        ], '/assets/register', [
            'HTTP_ORIGIN' => 'not-a-url',
        ]));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Cross-origin registration requests are not allowed.', $response->getContent());
    }

    public function testItAllowsSameOriginRegistrationWithRefererFallback(): void
    {
        $response = $this->app('same-origin-referer')->handle($this->postRegister([
            'name' => 'Referer Router',
        ], '/assets/register', [
            'HTTP_ORIGIN' => '',
            'HTTP_REFERER' => 'http://localhost/assets/register',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Registered asset Referer Router.', (string) $response->getContent());
    }

    public function testItAllowsWritePasswordsContainingColons(): void
    {
        $path = $this->storePath('colon-password');
        $response = AssetWebApp::fromStore($path, dirname($path), 'writer:pa:ss')->handle(
            Request::create('/assets/register', 'POST', [
                'name' => 'Colon Password Router',
            ], [], [], [
                'PHP_AUTH_USER' => 'writer',
                'PHP_AUTH_PW' => 'pa:ss',
                'HTTP_ORIGIN' => 'http://localhost',
            ]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Registered asset Colon Password Router.', (string) $response->getContent());
    }

    public function testItRejectsPostsToReadOnlyScreens(): void
    {
        $response = $this->app('read-only-post')->handle(Request::create('/assets', 'POST', [
            'name' => 'Core Router 01',
        ]));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->headers->get('Allow'));
    }

    public function testItReturnsNotFoundForUnknownRoutes(): void
    {
        $response = $this->app('not-found')->handle(Request::create('/missing'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $response->getContent());
    }

    public function testItReturnsMethodNotAllowedForUnsupportedMethods(): void
    {
        $response = $this->app('method')->handle(Request::create('/', 'DELETE'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, POST', $response->headers->get('Allow'));
    }

    public function testItReturnsReadOnlyAllowHeaderForUnsupportedMethodsOnReadOnlyScreens(): void
    {
        $response = $this->app('read-only-delete')->handle(Request::create('/assets', 'DELETE'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->headers->get('Allow'));
    }

    public function testItReturnsMethodNotAllowedForHeadRequests(): void
    {
        $response = $this->app('head')->handle(Request::create('/', 'HEAD'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, POST', $response->headers->get('Allow'));
    }

    public function testItReturnsMethodNotAllowedForOptionsRequests(): void
    {
        $response = $this->app('options')->handle(Request::create('/', 'OPTIONS'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, POST', $response->headers->get('Allow'));
    }

    public function testItReportsStoreFailures(): void
    {
        $path = $this->storePath('store-failure');
        file_put_contents($path, 'not a directory');

        $response = AssetWebApp::fromStore($path . '/assets.json', dirname($path), 'writer:secret')->handle($this->postRegister([
            'name' => 'Core Router 01',
        ]));

        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('Asset registration is temporarily unavailable.', (string) $response->getContent());
        self::assertStringNotContainsString($path, (string) $response->getContent());
        self::assertStringNotContainsString('Unable to open asset store lock:', (string) $response->getContent());
    }

    private function app(string $name): AssetWebApp
    {
        $path = $this->storePath($name);

        return AssetWebApp::fromStore($path, dirname($path), 'writer:secret');
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, string> $server
     */
    private function postRegister(array $parameters = [], string $path = '/assets/register', array $server = []): Request
    {
        return Request::create($path, 'POST', $parameters, [], [], $server + [
            'PHP_AUTH_USER' => 'writer',
            'PHP_AUTH_PW' => 'secret',
            'HTTP_ORIGIN' => 'http://localhost',
        ]);
    }

    private function storePath(string $name): string
    {
        $directory = dirname(__DIR__, 2) . '/build/web';

        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0o755, true));
        }

        $path = $directory . '/' . $name . '-assets.json';
        @unlink($path);

        return $path;
    }
}
