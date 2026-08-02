<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Tests\Infrastructure;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RelenzWorks\InfraRegister\Application\Asset\RegisterAssetHandler;
use RelenzWorks\InfraRegister\Domain\Asset\AssetId;
use RelenzWorks\InfraRegister\Domain\Security\Role;
use RelenzWorks\InfraRegister\Infrastructure\Http\AssetWebApp;
use RelenzWorks\InfraRegister\Infrastructure\Persistence\JsonAssetRepository;
use RelenzWorks\InfraRegister\Infrastructure\Security\LocalUserDirectory;
use Symfony\Component\HttpFoundation\Request;

final class AssetWebAppTest extends TestCase
{
    public function testItRendersTheRegistrationForm(): void
    {
        $response = $this->app('form')->handle(Request::create('/assets/register'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<a class="skip-link" href="#content">Skip to content</a>', (string) $response->getContent());
        self::assertStringContainsString('<header class="app-header">', (string) $response->getContent());
        self::assertStringContainsString('<main id="content" tabindex="-1">', (string) $response->getContent());
        self::assertStringContainsString('<section class="panel" aria-labelledby="registration-title">', (string) $response->getContent());
        self::assertStringContainsString('<nav class="sidebar" aria-label="Primary navigation">', (string) $response->getContent());
        self::assertStringContainsString('<ul class="nav-section-list">', (string) $response->getContent());
        self::assertStringContainsString('<p class="nav-section-title">Inventory</p>', (string) $response->getContent());
        self::assertStringContainsString('<a class="nav-link" href="/assets"', (string) $response->getContent());
        self::assertStringContainsString('<a class="button-link" href="/assets/register">Register Asset</a>', (string) $response->getContent());
        self::assertStringContainsString('<form class="global-search" role="search" method="get" action="/search">', (string) $response->getContent());
        self::assertStringContainsString('<input type="search" name="q" value="" aria-label="Global search" placeholder="Search assets, hosts, IPs">', (string) $response->getContent());
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

    public function testItCanInjectAUserDirectory(): void
    {
        $path = $this->storePath('injected-directory');
        $app = AssetWebApp::fromStore($path, dirname($path))
            ->withUserDirectory(LocalUserDirectory::fromLegacyWriteAuth('writer:secret'));

        $response = $app->handle($this->postRegister(['name' => 'Injected Directory Router']));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Registered asset Injected Directory Router.', (string) $response->getContent());
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function screenProvider(): iterable
    {
        yield 'dashboard' => ['/', 'Operations Overview', 'Attention Queue', 'Tracked assets'];
        yield 'search' => ['/search', 'Global Search', 'Grouped Search Results', 'Search targets'];
        yield 'assets' => ['/assets', 'Asset Index', 'Asset Register', 'Routers'];
        yield 'register' => ['/assets/register', 'Asset Registration', 'Full Registration Flow', 'Identity'];
        yield 'saved views' => ['/assets/views', 'Saved Asset Views', 'Saved View Library', 'Saved views'];
        yield 'imports' => ['/imports', 'Bulk Import', 'Import Batches', 'Staged rows'];
        yield 'network' => ['/network', 'Network Inventory', 'Topology Worklist', 'Interfaces'];
        yield 'interfaces' => ['/network/interfaces', 'Interface Registry', 'Interface Worklist', 'Ports'];
        yield 'ipam' => ['/network/ipam', 'IP Address Registry', 'Prefix and Address Worklist', 'Prefixes'];
        yield 'locations' => ['/locations', 'Location Directory', 'Location Directory', 'Sites'];
        yield 'rack elevation' => ['/locations/racks', 'Rack Elevation', 'Rack Placement Worklist', 'Racks tracked'];
        yield 'people' => ['/people', 'People Directory', 'Custodian Directory', 'Custodians'];
        yield 'custody' => ['/custody', 'Custody Queue', 'Custody Transfers', 'Pending transfers'];
        yield 'procurement' => ['/procurement', 'Procurement and Receiving', 'Receiving Queue', 'Open POs'];
        yield 'receiving' => ['/procurement/receiving', 'Receiving Workbench', 'Receiving Workbench', 'Open batches'];
        yield 'vendors' => ['/procurement/vendors', 'Vendors and Models', 'Vendor and Model Catalog', 'Vendors'];
        yield 'contracts' => ['/contracts', 'Contracts and Warranty', 'Renewal Pipeline', 'Active contracts'];
        yield 'maintenance' => ['/maintenance', 'Maintenance Work', 'Maintenance Work', 'Open work'];
        yield 'maintenance calendar' => ['/maintenance/calendar', 'Maintenance Calendar', 'Maintenance Calendar', 'Windows'];
        yield 'rma' => ['/maintenance/rma', 'RMA and Repair', 'RMA and Repair Queue', 'Open RMAs'];
        yield 'spare pools' => ['/maintenance/spares', 'Spare Pools', 'Spare Pool Thresholds', 'Pools'];
        yield 'monitoring' => ['/monitoring', 'Monitoring Links', 'Monitoring Exceptions', 'Linked hosts'];
        yield 'cacti linkage' => ['/monitoring/cacti', 'Cacti Linkage', 'Cacti Linkage Worklist', 'Cacti hosts'];
        yield 'monitoring exceptions' => ['/monitoring/exceptions', 'Monitoring Exceptions', 'Monitoring Exception Queue', 'Open exceptions'];
        yield 'reports' => ['/reports', 'Report Library', 'Report Library', 'Saved reports'];
        yield 'report builder' => ['/reports/builder', 'Report Builder', 'Report Builder Drafts', 'Fields'];
        yield 'admin' => ['/admin', 'Administration', 'Configuration Checklist', 'Roles'];
        yield 'settings' => ['/admin/settings', 'Settings', 'Settings Checklist', 'Asset types'];
        yield 'roles' => ['/admin/roles', 'Roles and Permissions', 'Role Permission Matrix', 'LDAP maps'];
        yield 'audit log' => ['/admin/audit-log', 'Audit Log', 'Audit Event Stream', 'Events today'];
        yield 'integrations' => ['/admin/integrations', 'Integrations', 'Integration Registry', 'Integrations'];
    }

    #[DataProvider('screenProvider')]
    public function testItRendersApplicationScreens(string $path, string $title, string $primaryWork, string $metric): void
    {
        $response = $this->app('screen-' . trim(str_replace('/', '-', $path), '-'))->handle(Request::create($path));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(sprintf('<title>%s - InfraRegister</title>', $title), (string) $response->getContent());
        self::assertStringContainsString(sprintf('<h1>%s</h1>', $title), (string) $response->getContent());
        self::assertStringContainsString('<span class="metadata">', (string) $response->getContent());
        self::assertStringNotContainsString('<span class="metadata">Inventory /', (string) $response->getContent());
        self::assertStringContainsString('aria-label="Breadcrumb"', (string) $response->getContent());
        self::assertStringContainsString('aria-label="Primary navigation"', (string) $response->getContent());
        self::assertStringContainsString(sprintf('href="%s" aria-current="page"', $path), (string) $response->getContent());
        self::assertStringContainsString($primaryWork, (string) $response->getContent());
        self::assertStringContainsString($metric, (string) $response->getContent());
        self::assertStringNotContainsString('class="back-link"', (string) $response->getContent());
    }

    public function testItRendersFleshedOutOperationalScreens(): void
    {
        $response = $this->app('fleshed-out')->handle(Request::create('/monitoring'));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<section class="metric-grid" aria-label="Key metrics">', $content);
        self::assertStringContainsString('<table class="data-table">', $content);
        self::assertStringContainsString('Run Reconcile', $content);
        self::assertStringContainsString('Hostname mismatch', $content);
        self::assertStringContainsString('Reconcile Sources', $content);
        self::assertStringNotContainsString('Screen Plan', $content);
    }

    public function testItRendersRouteAwareScreenActions(): void
    {
        $response = $this->app('screen-actions')->handle(Request::create('/network'));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<a class="secondary-action" href="/network/interfaces">Import LLDP</a>', $content);
        self::assertStringContainsString('<a class="secondary-action" href="/network/interfaces">Reconcile Peers</a>', $content);
        self::assertStringContainsString('<a class="secondary-action" href="/network/ipam">Reserve Prefix</a>', $content);
    }

    public function testItLeavesUnavailableScreenActionsDisabled(): void
    {
        $response = $this->app('disabled-actions')->handle(Request::create('/assets'));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<a class="secondary-action" href="/assets/views">Save View</a>', $content);
        self::assertStringContainsString('<span class="secondary-action" aria-disabled="true">Bulk Edit</span>', $content);
    }

    public function testItNormalizesTrailingSlashesForScreens(): void
    {
        $response = $this->app('trailing-slash')->handle(Request::create('/assets/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>Asset Index</h1>', (string) $response->getContent());
        self::assertStringContainsString('href="/assets" aria-current="page"', (string) $response->getContent());
    }

    public function testItGroupsNavigationBySectionForNestedScreens(): void
    {
        $response = $this->app('sectioned-nav')->handle(Request::create('/admin/integrations'));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<p class="nav-section-title">Configuration</p>', $content);
        self::assertStringContainsString('href="/admin/integrations" aria-current="page"', $content);
        self::assertStringContainsString('<h1>Integrations</h1>', $content);
    }

    public function testItRendersBreadcrumbsForNestedScreens(): void
    {
        $response = $this->app('nested-breadcrumbs')->handle(Request::create('/admin/integrations'));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            '<nav class="breadcrumbs" aria-label="Breadcrumb"><ol><li><a href="/">Dashboard</a></li><li><a href="/admin">Admin</a></li><li aria-current="page">Integrations</li></ol></nav>',
            $content,
        );
    }

    public function testItPreservesGlobalSearchQueryContext(): void
    {
        $response = $this->app('search-query')->handle(Request::create('/search', 'GET', ['q' => 'core <router>']));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('value="core &lt;router&gt;"', $content);
        self::assertStringContainsString('Showing representative grouped results for &quot;core &lt;router&gt;&quot;.', $content);
        self::assertStringContainsString('Grouped Search Results', $content);
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

    public function testItRendersRegisteredAssetsOnTheAssetIndex(): void
    {
        $path = $this->storePath('asset-index-read-model');
        $app = AssetWebApp::fromStore($path, dirname($path), 'writer:secret');

        $app->handle($this->postRegister(['name' => 'Live Router 01']));
        $response = $app->handle(Request::create('/assets'));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Registered assets', $content);
        self::assertStringContainsString('Live Router 01', $content);
        self::assertStringContainsString('<a href="/assets?id=', $content);
        self::assertStringContainsString('In service', $content);
        self::assertStringContainsString('Unassigned', $content);
        self::assertStringNotContainsString('IR-10042 core-atl-01', $content);
    }

    public function testItRendersAssetDetailFromTheAssetIndexRoute(): void
    {
        $path = $this->storePath('asset-detail');
        $id = AssetId::generate();
        file_put_contents($path, json_encode([[
            'id' => $id->value,
            'name' => 'Detail Router 01',
            'status' => 'in_service',
        ]], JSON_THROW_ON_ERROR));

        $response = AssetWebApp::fromStore($path, dirname($path), 'writer:secret')
            ->handle(Request::create('/assets', 'GET', ['id' => $id->value]));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<title>Detail Router 01 - Assets - InfraRegister</title>', $content);
        self::assertStringContainsString('<h1>Detail Router 01</h1>', $content);
        self::assertStringContainsString('<span class="metadata">Inventory / In service</span>', $content);
        self::assertStringContainsString(
            '<nav class="breadcrumbs" aria-label="Breadcrumb"><ol><li><a href="/">Dashboard</a></li><li><a href="/assets">Assets</a></li><li aria-current="page">Detail Router 01</li></ol></nav>',
            $content,
        );
        self::assertStringContainsString('Asset Tabs', $content);
        self::assertStringContainsString(
            '<a href="#asset-summary-title" aria-current="location"><span class="side-label">Summary</span><span class="side-value">Identity and lifecycle</span></a>',
            $content,
        );
        self::assertStringContainsString(
            '<li><span class="side-label">Metadata</span><span class="side-value">Add asset tag, serial, type, vendor, and model.</span></li>',
            $content,
        );
        self::assertStringContainsString(
            '<a class="secondary-action" href="/monitoring/cacti">Link Monitoring</a>',
            $content,
        );
        self::assertStringContainsString(
            '<a class="secondary-action" href="/custody">Transfer Custody</a>',
            $content,
        );
        self::assertStringContainsString(
            '<span class="secondary-action" aria-disabled="true">Edit Asset</span>',
            $content,
        );
        self::assertStringContainsString(sprintf('Record identifier: %s', $id->value), $content);
        self::assertStringContainsString('<a class="back-link" href="/assets">Back to Assets</a>', $content);
        self::assertStringContainsString('href="/assets" aria-current="page"', $content);
    }

    public function testItReturnsNotFoundForInvalidAssetDetailIds(): void
    {
        $response = $this->app('invalid-detail-id')->handle(Request::create('/assets', 'GET', ['id' => 'not-a-uuid']));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $response->getContent());
    }

    public function testItReturnsNotFoundForArrayAssetDetailIds(): void
    {
        $response = $this->app('array-detail-id')->handle(Request::create('/assets', 'GET', ['id' => ['not-a-uuid']]));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $response->getContent());
    }

    public function testItReturnsNotFoundForNonStringAssetDetailIds(): void
    {
        $response = $this->app('non-string-detail-id')->handle(Request::create('/assets', 'GET', ['id' => 123]));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $response->getContent());
    }

    public function testItReturnsNotFoundForMissingAssetDetailRecords(): void
    {
        $response = $this->app('missing-detail-record')->handle(Request::create('/assets', 'GET', ['id' => AssetId::generate()->value]));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $response->getContent());
    }

    public function testItLinksLocationRowsToLocationDetail(): void
    {
        $response = $this->app('location-index-links')->handle(Request::create('/locations'));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<a href="/locations?id=sjc1-row-c-rack-14">SJC1 Row C Rack 14</a>', $content);
        self::assertStringContainsString('<a href="/locations?id=truck-nv-12">Truck NV-12</a>', $content);
    }

    public function testItRendersLocationDetailFromTheLocationIndexRoute(): void
    {
        $response = $this->app('location-detail')->handle(Request::create('/locations', 'GET', ['id' => 'sjc1-row-c-rack-14']));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>SJC1 Row C Rack 14</h1>', $content);
        self::assertStringContainsString('Location Tabs', $content);
        self::assertStringContainsString('Rack Elevation', $content);
        self::assertStringContainsString('Plan Move', $content);
        self::assertStringContainsString('Badge and cage escort', $content);
        self::assertStringContainsString('href="/locations" aria-current="page"', $content);
    }

    public function testItReturnsNotFoundForUnknownLocationDetailIds(): void
    {
        $response = $this->app('unknown-location-detail')->handle(Request::create('/locations', 'GET', ['id' => 'unknown-site']));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $response->getContent());
    }

    public function testItLinksCustodyRowsToTransferDetail(): void
    {
        $response = $this->app('custody-index-links')->handle(Request::create('/custody'));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<a href="/custody?id=tr-1044">TR-1044</a>', $content);
        self::assertStringContainsString('<a href="/custody?id=tr-1047">TR-1047</a>', $content);
    }

    public function testItRendersCustodyTransferDetailFromTheCustodyQueueRoute(): void
    {
        $response = $this->app('custody-detail')->handle(Request::create('/custody', 'GET', ['id' => 'tr-1044']));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>TR-1044</h1>', $content);
        self::assertStringContainsString('Transfer Tabs', $content);
        self::assertStringContainsString('Accept Transfer', $content);
        self::assertStringContainsString('Receiver acknowledgement required', $content);
        self::assertStringContainsString('href="/custody" aria-current="page"', $content);
    }

    public function testItReturnsNotFoundForUnknownCustodyTransferIds(): void
    {
        $response = $this->app('unknown-custody-transfer')->handle(Request::create('/custody', 'GET', ['id' => 'tr-9999']));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $response->getContent());
    }

    public function testItLinksMonitoringRowsToExceptionDetail(): void
    {
        $response = $this->app('monitoring-index-links')->handle(Request::create('/monitoring'));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<a href="/monitoring?id=hostname-mismatch-core-atl-01">Hostname mismatch</a>', $content);
        self::assertStringContainsString('<a href="/monitoring?id=retired-still-polling-retired-edge-02">Retired still polling</a>', $content);
    }

    public function testItRendersMonitoringExceptionDetailFromTheMonitoringRoute(): void
    {
        $response = $this->app('monitoring-detail')->handle(Request::create('/monitoring', 'GET', ['id' => 'hostname-mismatch-core-atl-01']));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>Hostname mismatch</h1>', $content);
        self::assertStringContainsString('Exception Tabs', $content);
        self::assertStringContainsString('Link Host', $content);
        self::assertStringContainsString('Confirm hostname and asset alias', $content);
        self::assertStringContainsString('href="/monitoring" aria-current="page"', $content);
    }

    public function testItReturnsNotFoundForUnknownMonitoringExceptionIds(): void
    {
        $response = $this->app('unknown-monitoring-exception')->handle(Request::create('/monitoring', 'GET', ['id' => 'unknown-exception']));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $response->getContent());
    }

    public function testItLinksImportRowsToBatchDetail(): void
    {
        $response = $this->app('import-index-links')->handle(Request::create('/imports'));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<a href="/imports?id=imp-2041">IMP-2041</a>', $content);
        self::assertStringContainsString('<a href="/imports?id=imp-2043">IMP-2043</a>', $content);
    }

    public function testItRendersImportBatchDetailFromTheImportRoute(): void
    {
        $response = $this->app('import-detail')->handle(Request::create('/imports', 'GET', ['id' => 'imp-2041']));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>IMP-2041</h1>', $content);
        self::assertStringContainsString('Import Tabs', $content);
        self::assertStringContainsString('Commit Import', $content);
        self::assertStringContainsString('Review commit summary', $content);
        self::assertStringContainsString('href="/imports" aria-current="page"', $content);
    }

    public function testItReturnsNotFoundForUnknownImportBatchIds(): void
    {
        $response = $this->app('unknown-import-batch')->handle(Request::create('/imports', 'GET', ['id' => 'imp-9999']));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $response->getContent());
    }

    public function testItLinksProcurementRowsToReceivingDetail(): void
    {
        $response = $this->app('procurement-index-links')->handle(Request::create('/procurement'));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<a href="/procurement?id=po-10482">PO-10482</a>', $content);
        self::assertStringContainsString('<a href="/procurement?id=po-10511">PO-10511</a>', $content);
    }

    public function testItRendersReceivingDetailFromTheProcurementRoute(): void
    {
        $response = $this->app('procurement-detail')->handle(Request::create('/procurement', 'GET', ['id' => 'po-10482']));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>PO-10482</h1>', $content);
        self::assertStringContainsString('Receiving Tabs', $content);
        self::assertStringContainsString('Create Assets', $content);
        self::assertStringContainsString('Resolve duplicate serials before commit', $content);
        self::assertStringContainsString('href="/procurement" aria-current="page"', $content);
    }

    public function testItReturnsNotFoundForUnknownReceivingIds(): void
    {
        $response = $this->app('unknown-receiving')->handle(Request::create('/procurement', 'GET', ['id' => 'po-99999']));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $response->getContent());
    }

    public function testItLinksContractRowsToRenewalDetail(): void
    {
        $response = $this->app('contract-index-links')->handle(Request::create('/contracts'));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<a href="/contracts?id=sup-3092">SUP-3092</a>', $content);
        self::assertStringContainsString('<a href="/contracts?id=lease-884">LEASE-884</a>', $content);
    }

    public function testItRendersContractRenewalDetailFromTheContractsRoute(): void
    {
        $response = $this->app('contract-detail')->handle(Request::create('/contracts', 'GET', ['id' => 'sup-3092']));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>SUP-3092</h1>', $content);
        self::assertStringContainsString('Contract Tabs', $content);
        self::assertStringContainsString('Request Approval', $content);
        self::assertStringContainsString('Confirm covered serials before renewal approval', $content);
        self::assertStringContainsString('href="/contracts" aria-current="page"', $content);
    }

    public function testItReturnsNotFoundForUnknownContractRenewalIds(): void
    {
        $response = $this->app('unknown-contract-renewal')->handle(Request::create('/contracts', 'GET', ['id' => 'sup-9999']));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $response->getContent());
    }

    public function testItLinksReportRowsToSavedReportDetail(): void
    {
        $response = $this->app('report-index-links')->handle(Request::create('/reports'));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<a href="/reports?id=asset-audit-exceptions">Asset audit exceptions</a>', $content);
        self::assertStringContainsString('<a href="/reports?id=warehouse-cycle-count">Warehouse cycle count</a>', $content);
    }

    public function testItRendersSavedReportDetailFromTheReportsRoute(): void
    {
        $response = $this->app('report-detail')->handle(Request::create('/reports', 'GET', ['id' => 'asset-audit-exceptions']));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>Asset audit exceptions</h1>', $content);
        self::assertStringContainsString('Report Tabs', $content);
        self::assertStringContainsString('Run Report', $content);
        self::assertStringContainsString('Review 57 compliance gaps before export approval', $content);
        self::assertStringContainsString('href="/reports" aria-current="page"', $content);
    }

    public function testItReturnsNotFoundForUnknownSavedReportIds(): void
    {
        $response = $this->app('unknown-saved-report')->handle(Request::create('/reports', 'GET', ['id' => 'missing-report']));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $response->getContent());
    }

    public function testItLinksMaintenanceRowsToWorkDetail(): void
    {
        $response = $this->app('maintenance-index-links')->handle(Request::create('/maintenance'));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<a href="/maintenance?id=mw-2041">MW-2041</a>', $content);
        self::assertStringContainsString('<a href="/maintenance?id=ret-4412">RET-4412</a>', $content);
    }

    public function testItRendersMaintenanceWorkDetailFromTheMaintenanceRoute(): void
    {
        $response = $this->app('maintenance-detail')->handle(Request::create('/maintenance', 'GET', ['id' => 'mw-2041']));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>MW-2041</h1>', $content);
        self::assertStringContainsString('Maintenance Tabs', $content);
        self::assertStringContainsString('Close Work', $content);
        self::assertStringContainsString('Confirm change approval before dispatch', $content);
        self::assertStringContainsString('href="/maintenance" aria-current="page"', $content);
    }

    public function testItReturnsNotFoundForUnknownMaintenanceWorkIds(): void
    {
        $response = $this->app('unknown-maintenance-work')->handle(Request::create('/maintenance', 'GET', ['id' => 'mw-9999']));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $response->getContent());
    }

    public function testItLinksAdminRowsToConfigurationDetail(): void
    {
        $response = $this->app('admin-index-links')->handle(Request::create('/admin'));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<a href="/admin?id=rbac-ldap-group-role-map">RBAC</a>', $content);
        self::assertStringContainsString('<a href="/admin?id=integrations-cacti-host-sync">Integrations</a>', $content);
    }

    public function testItRendersAdminConfigurationDetailFromTheAdminRoute(): void
    {
        $response = $this->app('admin-detail')->handle(Request::create('/admin', 'GET', ['id' => 'rbac-ldap-group-role-map']));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>LDAP group role map</h1>', $content);
        self::assertStringContainsString('Configuration Tabs', $content);
        self::assertStringContainsString('Validate Policy', $content);
        self::assertStringContainsString('Review privileged mappings before production enablement', $content);
        self::assertStringContainsString('href="/admin" aria-current="page"', $content);
    }

    public function testItReturnsNotFoundForUnknownAdminConfigurationIds(): void
    {
        $response = $this->app('unknown-admin-configuration')->handle(Request::create('/admin', 'GET', ['id' => 'missing-setting']));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $response->getContent());
    }

    public function testItLinksNetworkRowsToTopologyDetail(): void
    {
        $response = $this->app('network-index-links')->handle(Request::create('/network'));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<a href="/network?id=core-atl-01-et-0-0-3">core-atl-01</a>', $content);
        self::assertStringContainsString('<a href="/network?id=cpe-rno-144-ge-0-0-0">cpe-rno-144</a>', $content);
    }

    public function testItRendersNetworkSignalDetailFromTheNetworkRoute(): void
    {
        $response = $this->app('network-detail')->handle(Request::create('/network', 'GET', ['id' => 'core-atl-01-et-0-0-3']));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>core-atl-01</h1>', $content);
        self::assertStringContainsString('Network Tabs', $content);
        self::assertStringContainsString('Attach Graph', $content);
        self::assertStringContainsString('Create or link peer asset before topology approval', $content);
        self::assertStringContainsString('href="/network" aria-current="page"', $content);
    }

    public function testItReturnsNotFoundForUnknownNetworkSignalIds(): void
    {
        $response = $this->app('unknown-network-signal')->handle(Request::create('/network', 'GET', ['id' => 'missing-signal']));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $response->getContent());
    }

    public function testItLinksDashboardRowsToAttentionDetail(): void
    {
        $response = $this->app('dashboard-attention-links')->handle(Request::create('/'));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<a href="/?id=core-router-serial-mismatch-sjc1">Core router serial mismatch at SJC1</a>', $content);
        self::assertStringContainsString('<a href="/?id=po-10482-duplicate-serials">Receiving batch PO-10482 has two duplicate serials</a>', $content);
    }

    public function testItRendersAttentionDetailFromTheDashboardRoute(): void
    {
        $response = $this->app('dashboard-attention-detail')->handle(Request::create('/', 'GET', ['id' => 'core-router-serial-mismatch-sjc1']));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<title>Core router serial mismatch at SJC1 - Dashboard - InfraRegister</title>', $content);
        self::assertStringContainsString('<h1>Core router serial mismatch at SJC1</h1>', $content);
        self::assertStringContainsString('<span class="metadata">Operations / High</span>', $content);
        self::assertStringContainsString('Attention Tabs', $content);
        self::assertStringContainsString('Resolve Item', $content);
        self::assertStringContainsString('Confirm physical serial and update the asset record', $content);
        self::assertStringContainsString('<a class="back-link" href="/">Back to Dashboard</a>', $content);
        self::assertStringContainsString('href="/" aria-current="page"', $content);
    }

    public function testItReturnsNotFoundForUnknownAttentionIds(): void
    {
        $response = $this->app('unknown-attention-item')->handle(Request::create('/', 'GET', ['id' => 'missing-attention']));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $response->getContent());
    }

    public function testItRendersNonDefaultAssetStatusesOnTheAssetIndex(): void
    {
        $path = $this->storePath('asset-index-statuses');
        file_put_contents($path, json_encode([
            [
                'id' => AssetId::generate()->value,
                'name' => 'Warehouse Spare Router',
                'status' => 'in_storage',
            ],
            [
                'id' => AssetId::generate()->value,
                'name' => 'Retired Access Switch',
                'status' => 'retired',
            ],
        ], JSON_THROW_ON_ERROR));

        $response = AssetWebApp::fromStore($path, dirname($path), 'writer:secret')->handle(Request::create('/assets'));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Warehouse Spare Router', $content);
        self::assertStringContainsString('In storage', $content);
        self::assertStringContainsString('Retired Access Switch', $content);
        self::assertStringContainsString('Retired', $content);
    }

    public function testItRendersRecentRegisteredAssetsOnTheDashboard(): void
    {
        $path = $this->storePath('dashboard-read-model');
        $app = AssetWebApp::fromStore($path, dirname($path), 'writer:secret');

        $app->handle($this->postRegister(['name' => 'Edge Switch 17']));
        $response = $app->handle(Request::create('/'));
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Recent Registrations', $content);
        self::assertStringContainsString('Edge Switch 17', $content);
        self::assertStringContainsString('Review metadata', $content);
        self::assertStringNotContainsString('Core router serial mismatch at SJC1', $content);
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
        self::assertSame('Asset registration requires configured authentication.', $response->getContent());
        self::assertFileDoesNotExist($path);
    }

    public function testItRejectsAuthenticatedUsersWithoutRegisterPermission(): void
    {
        $path = $this->storePath('viewer-write');
        $repository = new JsonAssetRepository($path, dirname($path));
        $app = new AssetWebApp(
            new RegisterAssetHandler($repository),
            $repository,
            new LocalUserDirectory([
                'viewer' => [
                    'password' => 'secret',
                    'roles' => [Role::Viewer],
                ],
            ]),
        );

        $response = $app->handle(Request::create('/assets/register', 'POST', [
            'name' => 'Viewer Router',
        ], [], [], [
            'PHP_AUTH_USER' => 'viewer',
            'PHP_AUTH_PW' => 'secret',
            'HTTP_ORIGIN' => 'http://localhost',
        ]));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Authentication Required', $response->getContent());
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
        self::assertSame('Asset registration requires configured authentication.', $response->getContent());
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
