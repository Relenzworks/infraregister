<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Infrastructure\Http;

use InvalidArgumentException;
use RelenzWorks\InfraRegister\Application\Asset\RegisterAsset;
use RelenzWorks\InfraRegister\Application\Asset\RegisterAssetHandler;
use RelenzWorks\InfraRegister\Application\Security\AccessPolicy;
use RelenzWorks\InfraRegister\Domain\Security\Permission;
use RelenzWorks\InfraRegister\Infrastructure\Persistence\AssetStoreUnavailable;
use RelenzWorks\InfraRegister\Infrastructure\Persistence\JsonAssetRepository;
use RelenzWorks\InfraRegister\Infrastructure\Security\LocalUserDirectory;
use RelenzWorks\InfraRegister\Port\UserDirectory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AssetWebApp
{
    /**
     * @var array<string, array{
     *     label: string,
     *     section: string,
     *     title: string,
     *     summary: string,
     *     items: list<array{title: string, body: string}>
     * }>
     */
    private const array SCREENS = [
        '/' => [
            'label' => 'Dashboard',
            'section' => 'Operations',
            'title' => 'Operations Overview',
            'summary' => 'Track inventory health, open transfers, monitoring gaps, and work that needs attention.',
            'items' => [
                ['title' => 'Inventory Health', 'body' => 'Assets by lifecycle state, missing metadata, and stale audit status.'],
                ['title' => 'Attention Queue', 'body' => 'Custody transfers, expiring contracts, receiving exceptions, and monitoring mismatches.'],
                ['title' => 'Recent Activity', 'body' => 'New registrations, location moves, contract links, and lifecycle changes.'],
            ],
        ],
        '/assets' => [
            'label' => 'Assets',
            'section' => 'Inventory',
            'title' => 'Asset Index',
            'summary' => 'Search, filter, and bulk-manage routers, switches, optics, CPE, servers, and spares.',
            'items' => [
                ['title' => 'Saved Views', 'body' => 'Core routers, CPE pending deployment, unmonitored assets, and spares below threshold.'],
                ['title' => 'Bulk Actions', 'body' => 'Assign ownership, move locations, export labels, and start lifecycle transitions.'],
                ['title' => 'Duplicate Signals', 'body' => 'Serial, asset tag, MAC, hostname, and Cacti-device matching before save.'],
            ],
        ],
        '/assets/register' => [
            'label' => 'Register',
            'section' => 'Inventory',
            'title' => 'Asset Registration',
            'summary' => 'Create one asset with validated identity and a low-error entry flow.',
            'items' => [
                ['title' => 'Identity', 'body' => 'Name, asset tag, serial, type, vendor, model, and criticality.'],
                ['title' => 'Placement', 'body' => 'Organization, site, rack, vehicle, warehouse bin, or customer premise.'],
                ['title' => 'Ownership', 'body' => 'Owner, custodian, department, cost center, and monitoring link.'],
            ],
        ],
        '/network' => [
            'label' => 'Network',
            'section' => 'Infrastructure',
            'title' => 'Network Inventory',
            'summary' => 'Manage device relationships, interfaces, circuits, prefixes, VLANs, optics, and peers.',
            'items' => [
                ['title' => 'Interfaces', 'body' => 'Ports, speed, media, optic identity, peer device, circuit, and graph links.'],
                ['title' => 'IP Registry', 'body' => 'Prefixes, VRFs, reservations, assignments, and device ownership.'],
                ['title' => 'Topology Worklist', 'body' => 'Missing peers, inconsistent labels, and active links without asset context.'],
            ],
        ],
        '/locations' => [
            'label' => 'Locations',
            'section' => 'Facilities',
            'title' => 'Location Directory',
            'summary' => 'Track where assets live across sites, buildings, rooms, racks, towers, warehouses, and vehicles.',
            'items' => [
                ['title' => 'Rack Elevation', 'body' => 'Front and rear RU occupancy, power adjacency, and placement conflicts.'],
                ['title' => 'Storage Positions', 'body' => 'Warehouse bins, vehicles, spares shelves, and field kits.'],
                ['title' => 'Access Notes', 'body' => 'Contacts, entry details, environmental notes, and open transfer activity.'],
            ],
        ],
        '/custody' => [
            'label' => 'Custody',
            'section' => 'People',
            'title' => 'Custody Queue',
            'summary' => 'Manage check-in, check-out, transfer acceptance, overdue returns, and audit evidence.',
            'items' => [
                ['title' => 'Transfers', 'body' => 'Draft, pending, in-transit, accepted, rejected, and overdue handoffs.'],
                ['title' => 'Assignments', 'body' => 'People, teams, vehicles, sites, and customer locations with active custody.'],
                ['title' => 'Evidence', 'body' => 'Comments, timestamps, receiver acknowledgement, labels, and photos.'],
            ],
        ],
        '/procurement' => [
            'label' => 'Procurement',
            'section' => 'Supply',
            'title' => 'Procurement and Receiving',
            'summary' => 'Connect purchase orders, vendors, model catalogs, receiving, label printing, and discrepancies.',
            'items' => [
                ['title' => 'Receiving', 'body' => 'Match serials to PO lines, create assets, assign storage, and print labels.'],
                ['title' => 'Vendors', 'body' => 'Vendor profiles, model normalization, support dates, and required fields.'],
                ['title' => 'Discrepancies', 'body' => 'Short shipments, duplicate serials, substitutions, and damaged goods.'],
            ],
        ],
        '/contracts' => [
            'label' => 'Contracts',
            'section' => 'Commercial',
            'title' => 'Contracts and Warranty',
            'summary' => 'Track support, warranty, lease, licensing, renewal risk, and covered asset relationships.',
            'items' => [
                ['title' => 'Renewals', 'body' => 'Upcoming renewals, owner, vendor, term, cost, and renewal state.'],
                ['title' => 'Coverage', 'body' => 'Covered assets, uncovered critical assets, and warranty expiration.'],
                ['title' => 'Documents', 'body' => 'Contracts, invoices, quotes, service records, and renewal history.'],
            ],
        ],
        '/maintenance' => [
            'label' => 'Maintenance',
            'section' => 'Operations',
            'title' => 'Maintenance Work',
            'summary' => 'Plan work windows, RMA, repair, calibration, spare-pool thresholds, and lifecycle actions.',
            'items' => [
                ['title' => 'Calendar', 'body' => 'Maintenance windows, affected assets, owners, status, and related tickets.'],
                ['title' => 'RMA', 'body' => 'Failure reason, vendor case, shipment, replacement, and final disposition.'],
                ['title' => 'Spare Pools', 'body' => 'Quantity on hand, reserved count, threshold, and reorder signals.'],
            ],
        ],
        '/monitoring' => [
            'label' => 'Monitoring',
            'section' => 'Cacti',
            'title' => 'Monitoring Links',
            'summary' => 'Reconcile InfraRegister records with Cacti devices, graph trees, polling state, and exceptions.',
            'items' => [
                ['title' => 'Linked Devices', 'body' => 'Assets connected to Cacti hosts, graphs, tree nodes, and polling state.'],
                ['title' => 'Coverage Gaps', 'body' => 'Active assets without monitoring and monitored devices without asset records.'],
                ['title' => 'Exceptions', 'body' => 'Hostname mismatch, duplicate links, retired assets still polling, and missing serials.'],
            ],
        ],
        '/reports' => [
            'label' => 'Reports',
            'section' => 'Insights',
            'title' => 'Report Library',
            'summary' => 'Run operational, financial, compliance, lifecycle, custody, and monitoring coverage reports.',
            'items' => [
                ['title' => 'Operational', 'body' => 'Assets by site, missing metadata, retired inventory, and receiving status.'],
                ['title' => 'Financial', 'body' => 'Warranty expiration, contract renewal, depreciation, and cost-center ownership.'],
                ['title' => 'Exports', 'body' => 'Saved filters, CSV output, scheduled reports, and PDF summaries.'],
            ],
        ],
        '/admin' => [
            'label' => 'Admin',
            'section' => 'Configuration',
            'title' => 'Administration',
            'summary' => 'Configure organizations, lifecycle states, custom fields, permissions, imports, and integrations.',
            'items' => [
                ['title' => 'Settings', 'body' => 'Asset types, required fields, numbering policy, duplicate rules, and notifications.'],
                ['title' => 'Permissions', 'body' => 'View, create, edit, transfer, retire, contract, export, and settings access.'],
                ['title' => 'Integrations', 'body' => 'Cacti, CSV import/export, webhooks, SSO, ticketing, procurement, and IPAM.'],
            ],
        ],
    ];

    public function __construct(
        private readonly RegisterAssetHandler $registerAsset,
        private readonly ?UserDirectory $userDirectory,
        private readonly AccessPolicy $accessPolicy = new AccessPolicy(),
    ) {}

    public static function fromStore(string $path, ?string $basePath, ?string $writeAuth = null): self
    {
        return new self(
            new RegisterAssetHandler(new JsonAssetRepository($path, $basePath)),
            LocalUserDirectory::fromLegacyWriteAuth($writeAuth),
        );
    }

    public function withUserDirectory(?UserDirectory $userDirectory): self
    {
        return new self($this->registerAsset, $userDirectory, $this->accessPolicy);
    }

    public function handle(Request $request): Response
    {
        $path = $this->normalizePath($request->getPathInfo());

        if (!array_key_exists($path, self::SCREENS)) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }

        if ($request->getMethod() === Request::METHOD_POST) {
            // Keep root POST support for clients created before the registration route was split out.
            return $path === '/assets/register' || $path === '/'
                ? $this->register($request)
                : new Response('Method Not Allowed', Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => 'GET']);
        }

        return match ($request->getMethod()) {
            Request::METHOD_GET => $this->render($path),
            default => new Response(
                'Method Not Allowed',
                Response::HTTP_METHOD_NOT_ALLOWED,
                ['Allow' => $this->allowedMethods($path)],
            ),
        };
    }

    private function register(Request $request): Response
    {
        if (!$this->isGranted($request, Permission::AssetRegister)) {
            return $this->writeDeniedResponse();
        }

        if (!$this->hasSameOrigin($request)) {
            error_log('InfraRegister rejected cross-origin asset registration request.');

            return new Response('Cross-origin registration requests are not allowed.', Response::HTTP_FORBIDDEN);
        }

        $parameters = $request->request->all();
        $name = $parameters['name'] ?? null;

        if (!is_string($name)) {
            return $this->render('/assets/register', error: 'Asset name is required.', status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $asset = ($this->registerAsset)(new RegisterAsset($name));
        } catch (InvalidArgumentException $exception) {
            return $this->render('/assets/register', error: $exception->getMessage(), status: Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (AssetStoreUnavailable) {
            return $this->render(
                '/assets/register',
                error: 'Asset registration is temporarily unavailable.',
                status: Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        error_log(sprintf('InfraRegister registered asset %s.', $asset->id->value));

        return $this->render('/assets/register', success: sprintf('Registered asset %s.', $asset->name->value));
    }

    private function render(
        string $path,
        ?string $success = null,
        ?string $error = null,
        int $status = Response::HTTP_OK,
    ): Response {
        $screen = self::SCREENS[$path];
        $successHtml = $success === null ? '' : sprintf(
            '<p class="notice" role="status">%s</p>',
            $this->escape($success),
        );
        $errorHtml = $error === null ? '' : sprintf(
            '<p class="error" id="asset-name-error" role="alert">%s</p>',
            $this->escape($error),
        );
        $inputDescription = $this->escape(
            $error === null ? 'asset-name-requirements' : 'asset-name-requirements asset-name-error',
        );
        $navigation = $this->renderNavigation($path);
        $content = $path === '/assets/register'
            ? $this->renderRegistrationContent($screen, $successHtml, $errorHtml, $inputDescription)
            : $this->renderScreenContent($screen);

        return new Response(<<<HTML
            <!doctype html>
            <html lang="en">
            <head>
              <meta charset="utf-8">
              <meta name="viewport" content="width=device-width, initial-scale=1">
              <title>{$this->escape($screen['title'])} - InfraRegister</title>
              <style>
                :root {
                  color-scheme: light;
                  --page: #f7f8fa;
                  --surface: #ffffff;
                  --text: #17202a;
                  --muted: #52616f;
                  --line: #c9d2dc;
                  --line-strong: #7d8b99;
                  --accent: #0f6b7a;
                  --accent-strong: #0a5360;
                  --focus: #9b5de5;
                  --success-bg: #e7f7ef;
                  --success-text: #0f5132;
                  --success-line: #8fd1aa;
                  --error-bg: #fff0f0;
                  --error-text: #842029;
                  --error-line: #e7a2a8;
                  font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                }

                * { box-sizing: border-box; }

                body {
                  margin: 0;
                  min-width: 320px;
                  background: var(--page);
                  color: var(--text);
                  font-size: 16px;
                  line-height: 1.5;
                }

                a, button, input { touch-action: manipulation; }

                :focus-visible {
                  outline: 3px solid var(--focus);
                  outline-offset: 3px;
                }

                .skip-link {
                  position: absolute;
                  left: 16px;
                  top: 16px;
                  z-index: 2;
                  transform: translateY(-140%);
                  padding: 8px 12px;
                  background: var(--surface);
                  color: var(--text);
                  border: 1px solid var(--line-strong);
                  border-radius: 6px;
                }

                .skip-link:focus-visible { transform: translateY(0); }

                .app-header {
                  border-bottom: 1px solid var(--line);
                  background: var(--surface);
                }

                .header-inner {
                  display: flex;
                  align-items: center;
                  justify-content: space-between;
                  gap: 16px;
                  width: min(1280px, 100%);
                  min-height: 64px;
                  margin: 0 auto;
                  padding: 0 20px;
                }

                .brand {
                  display: flex;
                  align-items: center;
                  gap: 10px;
                  min-width: 0;
                  font-weight: 750;
                  color: var(--text);
                  text-decoration: none;
                }

                .brand-mark {
                  display: grid;
                  place-items: center;
                  width: 34px;
                  height: 34px;
                  flex: 0 0 auto;
                  border-radius: 7px;
                  background: var(--accent);
                  color: #ffffff;
                  font-weight: 800;
                }

                .app-layout {
                  display: grid;
                  grid-template-columns: 232px minmax(0, 1fr);
                  width: min(1280px, 100%);
                  margin: 0 auto;
                }

                .sidebar {
                  min-height: calc(100vh - 65px);
                  padding: 20px 12px;
                  border-right: 1px solid var(--line);
                }

                .nav-list {
                  display: grid;
                  gap: 4px;
                  margin: 0;
                  padding: 0;
                  list-style: none;
                }

                .nav-link {
                  display: flex;
                  min-height: 40px;
                  align-items: center;
                  padding: 7px 10px;
                  border-radius: 7px;
                  color: var(--text);
                  text-decoration: none;
                  font-weight: 650;
                }

                .nav-link:hover {
                  background: #edf2f7;
                }

                .nav-link[aria-current="page"] {
                  background: #dff2f5;
                  color: var(--accent-strong);
                }

                main {
                  min-width: 0;
                  padding: 28px 24px 48px;
                }

                .page-title {
                  display: flex;
                  align-items: end;
                  justify-content: space-between;
                  gap: 16px;
                  margin-bottom: 18px;
                }

                h1 {
                  margin: 0;
                  font-size: 28px;
                  line-height: 1.2;
                }

                .metadata {
                  color: var(--muted);
                  font-size: 14px;
                  white-space: nowrap;
                }

                .summary {
                  max-width: 780px;
                  margin: -8px 0 20px;
                  color: var(--muted);
                }

                .workspace {
                  display: grid;
                  grid-template-columns: minmax(0, 1fr);
                  gap: 18px;
                  align-items: start;
                }

                .panel {
                  background: var(--surface);
                  border: 1px solid var(--line);
                  border-radius: 8px;
                }

                .panel-header {
                  display: flex;
                  align-items: center;
                  justify-content: space-between;
                  gap: 16px;
                  padding: 16px 18px;
                  border-bottom: 1px solid var(--line);
                }

                h2 {
                  margin: 0;
                  font-size: 18px;
                  line-height: 1.3;
                }

                .screen-grid {
                  display: grid;
                  grid-template-columns: repeat(3, minmax(0, 1fr));
                  gap: 12px;
                  padding: 18px;
                }

                .screen-card {
                  min-height: 132px;
                  padding: 14px;
                  border: 1px solid var(--line);
                  border-radius: 7px;
                  background: #fbfcfd;
                }

                .screen-card h3 {
                  margin: 0 0 8px;
                  font-size: 16px;
                }

                .screen-card p {
                  margin: 0;
                  color: var(--muted);
                  font-size: 14px;
                }

                form {
                  display: grid;
                  gap: 14px;
                  padding: 18px;
                }

                label {
                  display: inline-flex;
                  width: fit-content;
                  font-weight: 700;
                }

                input {
                  width: 100%;
                  min-height: 46px;
                  padding: 9px 11px;
                  border: 1px solid var(--line-strong);
                  border-radius: 6px;
                  background: #ffffff;
                  color: var(--text);
                  font: inherit;
                }

                input:user-invalid {
                  border-color: var(--error-text);
                }

                button,
                .button-link {
                  display: inline-flex;
                  align-items: center;
                  justify-content: center;
                  min-height: 44px;
                  padding: 0 16px;
                  border: 1px solid var(--accent-strong);
                  border-radius: 6px;
                  background: var(--accent);
                  color: #ffffff;
                  font: inherit;
                  font-weight: 750;
                  text-decoration: none;
                  white-space: nowrap;
                  cursor: pointer;
                }

                button:hover,
                .button-link:hover {
                  background: var(--accent-strong);
                }

                .notice,
                .error {
                  margin: 18px 18px 0;
                  padding: 10px 12px;
                  border-radius: 6px;
                  font-weight: 650;
                }

                .notice {
                  background: var(--success-bg);
                  color: var(--success-text);
                  border: 1px solid var(--success-line);
                }

                .error {
                  background: var(--error-bg);
                  color: var(--error-text);
                  border: 1px solid var(--error-line);
                }

                .field-note {
                  margin: -4px 0 0;
                  color: var(--muted);
                  font-size: 14px;
                }

                @media (max-width: 900px) {
                  .header-inner {
                    min-height: 58px;
                    padding: 0 16px;
                  }

                  .app-layout {
                    grid-template-columns: 1fr;
                  }

                  .sidebar {
                    min-height: auto;
                    overflow-x: auto;
                    padding: 10px 16px;
                    border-right: 0;
                    border-bottom: 1px solid var(--line);
                  }

                  .nav-list {
                    grid-auto-flow: column;
                    grid-auto-columns: max-content;
                  }

                  main {
                    padding: 20px 16px 36px;
                  }

                  .page-title {
                    display: grid;
                    grid-template-columns: 1fr;
                  }

                  .metadata {
                    white-space: normal;
                  }

                  .screen-grid {
                    grid-template-columns: 1fr;
                  }
                }

                @media (prefers-reduced-motion: reduce) {
                  *,
                  *::before,
                  *::after {
                    scroll-behavior: auto !important;
                    transition-duration: 0.01ms !important;
                    animation-duration: 0.01ms !important;
                    animation-iteration-count: 1 !important;
                  }
                }

                @media print {
                  body { background: #ffffff; }
                  .skip-link, .sidebar, button, .button-link { display: none; }
                  .app-layout { display: block; }
                  .panel { border-color: #000000; }
                }
              </style>
            </head>
            <body>
              <a class="skip-link" href="#content">Skip to content</a>
              <header class="app-header">
                <div class="header-inner">
                  <a class="brand" href="/" aria-label="InfraRegister dashboard">
                    <span class="brand-mark" aria-hidden="true">IR</span>
                    <span>InfraRegister</span>
                  </a>
                  <a class="button-link" href="/assets/register">Register Asset</a>
                </div>
              </header>
              <div class="app-layout">
                <nav class="sidebar" aria-label="Primary navigation">
                  {$navigation}
                </nav>
                <main id="content">
                  <div class="page-title">
                    <h1>{$this->escape($screen['title'])}</h1>
                    <span class="metadata">{$this->escape($screen['section'])}</span>
                  </div>
                  <p class="summary">{$this->escape($screen['summary'])}</p>
                  {$content}
                </main>
              </div>
            </body>
            </html>
            HTML, $status);
    }

    /**
     * @param array{
     *     label: string,
     *     section: string,
     *     title: string,
     *     summary: string,
     *     items: list<array{title: string, body: string}>
     * } $screen
     */
    private function renderRegistrationContent(
        array $screen,
        string $successHtml,
        string $errorHtml,
        string $inputDescription,
    ): string {
        $overview = $this->renderCards($screen['items']);

        return <<<HTML
            <div class="workspace">
              <section class="panel" aria-labelledby="registration-title">
                <div class="panel-header">
                  <h2 id="registration-title">Register Asset</h2>
                </div>
                {$successHtml}
                {$errorHtml}
                <form method="post" action="/assets/register" novalidate>
                  <label for="name">Asset name</label>
                  <input id="name" name="name" maxlength="120" required autocomplete="off" aria-describedby="{$inputDescription}">
                  <p class="field-note" id="asset-name-requirements">Required, 120 characters maximum.</p>
                  <button type="submit">Register Asset</button>
                </form>
              </section>
              <section class="panel" aria-labelledby="registration-plan-title">
                <div class="panel-header">
                  <h2 id="registration-plan-title">Full Registration Flow</h2>
                </div>
                {$overview}
              </section>
            </div>
            HTML;
    }

    /**
     * @param array{
     *     label: string,
     *     section: string,
     *     title: string,
     *     summary: string,
     *     items: list<array{title: string, body: string}>
     * } $screen
     */
    private function renderScreenContent(array $screen): string
    {
        $cards = $this->renderCards($screen['items']);

        return <<<HTML
            <section class="panel" aria-labelledby="screen-overview-title">
              <div class="panel-header">
                <h2 id="screen-overview-title">Screen Plan</h2>
              </div>
              {$cards}
            </section>
            HTML;
    }

    /**
     * @param list<array{title: string, body: string}> $items
     */
    private function renderCards(array $items): string
    {
        $cards = '';

        foreach ($items as $item) {
            $cards .= sprintf(
                '<article class="screen-card"><h3>%s</h3><p>%s</p></article>',
                $this->escape($item['title']),
                $this->escape($item['body']),
            );
        }

        return sprintf('<div class="screen-grid">%s</div>', $cards);
    }

    private function renderNavigation(string $currentPath): string
    {
        $items = '';

        foreach (self::SCREENS as $path => $screen) {
            $current = $path === $currentPath ? ' aria-current="page"' : '';
            $items .= sprintf(
                '<li><a class="nav-link" href="%s"%s>%s</a></li>',
                $this->escape($path),
                $current,
                $this->escape($screen['label']),
            );
        }

        return sprintf('<ul class="nav-list">%s</ul>', $items);
    }

    private function normalizePath(string $path): string
    {
        if ($path === '/') {
            return '/';
        }

        return rtrim($path, '/');
    }

    private function allowedMethods(string $path): string
    {
        return $path === '/' || $path === '/assets/register' ? 'GET, POST' : 'GET';
    }

    private function isGranted(Request $request, Permission $permission): bool
    {
        if ($this->userDirectory === null) {
            return false;
        }

        $requestUser = $request->server->get('PHP_AUTH_USER');
        $requestPassword = $request->server->get('PHP_AUTH_PW');

        if (!is_string($requestUser) || !is_string($requestPassword)) {
            return false;
        }

        $user = $this->userDirectory->authenticate($requestUser, $requestPassword);

        return $user !== null && $this->accessPolicy->allows($user, $permission);
    }

    private function writeDeniedResponse(): Response
    {
        if ($this->userDirectory === null) {
            error_log('InfraRegister rejected asset registration because no user directory is configured.');

            return new Response('Asset registration requires configured authentication.', Response::HTTP_FORBIDDEN);
        }

        error_log('InfraRegister rejected asset registration because authentication or authorization failed.');

        return new Response('Authentication Required', Response::HTTP_UNAUTHORIZED, [
            'WWW-Authenticate' => 'Basic realm="InfraRegister"',
        ]);
    }

    private function hasSameOrigin(Request $request): bool
    {
        // Phase 1 Docker usage is loopback-only. Configure trusted proxies before placing this behind TLS termination.
        $origin = $request->headers->get('Origin');

        if (is_string($origin) && $origin !== '') {
            return $this->originMatchesRequest($origin, $request);
        }

        $referer = $request->headers->get('Referer');

        return is_string($referer) && $referer !== '' && $this->originMatchesRequest($referer, $request);
    }

    private function originMatchesRequest(string $origin, Request $request): bool
    {
        $scheme = parse_url($origin, PHP_URL_SCHEME);
        $host = parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);

        if (!is_string($scheme) || !is_string($host)) {
            return false;
        }

        $originPort = is_int($port) ? $port : ($scheme === 'https' ? 443 : 80);
        $expected = sprintf('%s://%s:%d', $request->getScheme(), $request->getHost(), $request->getPort());
        $actual = sprintf('%s://%s:%d', $scheme, $host, $originPort);

        return hash_equals($expected, $actual);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
