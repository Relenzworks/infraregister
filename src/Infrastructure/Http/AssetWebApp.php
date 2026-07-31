<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Infrastructure\Http;

use InvalidArgumentException;
use RelenzWorks\InfraRegister\Application\Asset\RegisterAsset;
use RelenzWorks\InfraRegister\Application\Asset\RegisterAssetHandler;
use RelenzWorks\InfraRegister\Application\Security\AccessPolicy;
use RelenzWorks\InfraRegister\Domain\Asset\Asset;
use RelenzWorks\InfraRegister\Domain\Asset\AssetId;
use RelenzWorks\InfraRegister\Domain\Asset\AssetStatus;
use RelenzWorks\InfraRegister\Domain\Security\Permission;
use RelenzWorks\InfraRegister\Infrastructure\Persistence\AssetStoreUnavailable;
use RelenzWorks\InfraRegister\Infrastructure\Persistence\JsonAssetRepository;
use RelenzWorks\InfraRegister\Infrastructure\Security\LocalUserDirectory;
use RelenzWorks\InfraRegister\Port\AssetRepository;
use RelenzWorks\InfraRegister\Port\UserDirectory;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
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
        '/search' => [
            'label' => 'Search',
            'section' => 'Operations',
            'title' => 'Global Search',
            'summary' => 'Find assets, people, locations, contracts, purchase orders, Cacti hosts, IP addresses, and serials.',
            'items' => [
                ['title' => 'Grouped Results', 'body' => 'Search results are grouped by entity type so operators can quickly scan assets, monitoring, commercial, and custody hits.'],
                ['title' => 'Keyboard Flow', 'body' => 'The top bar form is available from every screen and submits without mutating data.'],
                ['title' => 'Operational Targets', 'body' => 'Targets include asset tag, serial, MAC, IP, hostname, Cacti device, purchase order, contract, person, and location.'],
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
        '/assets/views' => [
            'label' => 'Saved Views',
            'section' => 'Inventory',
            'title' => 'Saved Asset Views',
            'summary' => 'Preserve operational lenses for teams that repeatedly audit, deploy, and reconcile assets.',
            'items' => [
                ['title' => 'Team Views', 'body' => 'Curated filters for NetOps, Field Ops, Supply, Finance, and Facilities.'],
                ['title' => 'Shared Filters', 'body' => 'Pinned columns, lifecycle state, monitoring state, support risk, and stale-audit windows.'],
                ['title' => 'Review Cadence', 'body' => 'Recurring views can drive scheduled audit, deployment, renewal, and cleanup work.'],
            ],
        ],
        '/imports' => [
            'label' => 'Imports',
            'section' => 'Inventory',
            'title' => 'Bulk Import',
            'summary' => 'Validate staged asset data before creating or updating inventory records.',
            'items' => [
                ['title' => 'Upload', 'body' => 'CSV batches with field mapping, source tracking, and operator ownership.'],
                ['title' => 'Validate', 'body' => 'Required fields, duplicate signals, status mapping, and referential checks.'],
                ['title' => 'Commit', 'body' => 'Review summary, create records, update safe fields, and preserve audit evidence.'],
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
        '/network/interfaces' => [
            'label' => 'Interfaces',
            'section' => 'Infrastructure',
            'title' => 'Interface Registry',
            'summary' => 'Track ports, optics, peers, circuits, VLANs, and graph links as operational records.',
            'items' => [
                ['title' => 'Port Identity', 'body' => 'Device, interface name, admin state, speed, media, optic serial, and role.'],
                ['title' => 'Link Context', 'body' => 'Peer device, peer port, circuit, provider handoff, VLAN membership, and customer edge.'],
                ['title' => 'Monitoring Links', 'body' => 'Cacti graph references, polling state, graph gaps, and reconcile exceptions.'],
            ],
        ],
        '/network/ipam' => [
            'label' => 'IPAM',
            'section' => 'Infrastructure',
            'title' => 'IP Address Registry',
            'summary' => 'Manage prefixes, VRFs, reservations, assignments, owners, and drift signals.',
            'items' => [
                ['title' => 'Prefixes', 'body' => 'Allocated networks, site scope, VRF ownership, utilization, and reserved pools.'],
                ['title' => 'Assignments', 'body' => 'Device interfaces, loopbacks, customer handoffs, VIPs, and management addresses.'],
                ['title' => 'Drift Checks', 'body' => 'Overlaps, stale reservations, unassigned live addresses, and mismatched VRF metadata.'],
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
        '/locations/racks' => [
            'label' => 'Rack Elevation',
            'section' => 'Facilities',
            'title' => 'Rack Elevation',
            'summary' => 'Plan RU placement, power adjacency, and rack conflicts before hardware moves.',
            'items' => [
                ['title' => 'Front and Rear', 'body' => 'Track occupied RUs, reserved space, rear-mounted gear, and blanking panels.'],
                ['title' => 'Power Context', 'body' => 'Surface feed, outlet, draw, redundancy, and oversubscription concerns.'],
                ['title' => 'Placement Rules', 'body' => 'Catch height, weight, airflow, and neighbor conflicts before dispatch.'],
            ],
        ],
        '/people' => [
            'label' => 'People',
            'section' => 'People',
            'title' => 'People Directory',
            'summary' => 'Track custodians, assignees, teams, locations, and overdue returns.',
            'items' => [
                ['title' => 'Custodians', 'body' => 'People, teams, contractors, vehicles, and external contacts that can hold assets.'],
                ['title' => 'Assignments', 'body' => 'Current asset counts, critical holdings, overdue returns, and acceptance state.'],
                ['title' => 'Contact Context', 'body' => 'Team, location, escalation path, and access notes used during transfer work.'],
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
        '/procurement/receiving' => [
            'label' => 'Receiving',
            'section' => 'Supply',
            'title' => 'Receiving Workbench',
            'summary' => 'Turn delivered goods into validated asset records with labels and initial placement.',
            'items' => [
                ['title' => 'Serial Capture', 'body' => 'Scan or enter serials, model identifiers, quantities, and receiving exceptions.'],
                ['title' => 'Asset Creation', 'body' => 'Create assets from PO lines with type, owner, storage, and label metadata.'],
                ['title' => 'Exception Handling', 'body' => 'Hold duplicate serials, substitutions, damaged goods, and short shipments.'],
            ],
        ],
        '/procurement/vendors' => [
            'label' => 'Vendors',
            'section' => 'Supply',
            'title' => 'Vendors and Models',
            'summary' => 'Normalize suppliers, model catalogs, lifecycle support data, and required fields.',
            'items' => [
                ['title' => 'Vendor Profiles', 'body' => 'Support contacts, contract references, RMA rules, and preferred part mappings.'],
                ['title' => 'Model Catalog', 'body' => 'Canonical names, aliases, asset type, field requirements, and lifecycle dates.'],
                ['title' => 'Data Quality', 'body' => 'Highlight duplicate models, missing support windows, and unsafe substitutions.'],
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
        '/maintenance/calendar' => [
            'label' => 'Calendar',
            'section' => 'Operations',
            'title' => 'Maintenance Calendar',
            'summary' => 'Coordinate planned work windows, owners, affected assets, tickets, and readiness.',
            'items' => [
                ['title' => 'Work Windows', 'body' => 'Planned start, end, risk, affected assets, services, and owning team.'],
                ['title' => 'Readiness', 'body' => 'Confirm approvals, spare reservations, rollback notes, and customer impact.'],
                ['title' => 'Evidence', 'body' => 'Keep tickets, photos, vendor notices, and completion records attached.'],
            ],
        ],
        '/maintenance/rma' => [
            'label' => 'RMA',
            'section' => 'Operations',
            'title' => 'RMA and Repair',
            'summary' => 'Track failed equipment through vendor cases, shipping, replacement, and disposition.',
            'items' => [
                ['title' => 'Failure Intake', 'body' => 'Capture failure reason, asset, spare used, owner, and service impact.'],
                ['title' => 'Vendor Case', 'body' => 'Track case number, shipment, replacement, credit, and expected return.'],
                ['title' => 'Disposition', 'body' => 'Return to stock, retire, repair, scrap, or wait for vendor confirmation.'],
            ],
        ],
        '/maintenance/spares' => [
            'label' => 'Spare Pools',
            'section' => 'Operations',
            'title' => 'Spare Pools',
            'summary' => 'Keep critical stock above minimum thresholds across sites, warehouses, and vehicles.',
            'items' => [
                ['title' => 'Thresholds', 'body' => 'Model, site, minimum count, reserved quantity, and reorder point.'],
                ['title' => 'Reservations', 'body' => 'Hold spares for maintenance windows, RMAs, deployments, and field kits.'],
                ['title' => 'Replenishment', 'body' => 'Surface shortages, substitutions, lead time, and open purchase order links.'],
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
        '/monitoring/cacti' => [
            'label' => 'Cacti Linkage',
            'section' => 'Cacti',
            'title' => 'Cacti Linkage',
            'summary' => 'Bridge InfraRegister assets with Cacti hosts, graph trees, polling state, and graph evidence.',
            'items' => [
                ['title' => 'Host Links', 'body' => 'Attach assets to Cacti hosts with hostname, device ID, polling state, and graph context.'],
                ['title' => 'Graph Trees', 'body' => 'Preserve tree placement, interface graphs, and evidence needed for audits.'],
                ['title' => 'Coverage', 'body' => 'Find active assets without polling and monitored hosts without inventory records.'],
            ],
        ],
        '/monitoring/exceptions' => [
            'label' => 'Exceptions',
            'section' => 'Cacti',
            'title' => 'Monitoring Exceptions',
            'summary' => 'Resolve inventory and monitoring mismatches before they drift into audit or operations debt.',
            'items' => [
                ['title' => 'Mismatch Types', 'body' => 'Hostname mismatch, duplicate links, missing serials, and graph gaps.'],
                ['title' => 'Triage', 'body' => 'Assign owner, confirm source data, link records, suppress known exceptions, or retire stale hosts.'],
                ['title' => 'Evidence', 'body' => 'Keep reconcile result, source checks, and closure notes for audit history.'],
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
        '/reports/builder' => [
            'label' => 'Report Builder',
            'section' => 'Insights',
            'title' => 'Report Builder',
            'summary' => 'Create saved reports from asset fields, relationships, filters, groups, and schedules.',
            'items' => [
                ['title' => 'Field Selection', 'body' => 'Choose asset, location, custody, contract, monitoring, and financial fields.'],
                ['title' => 'Filters and Groups', 'body' => 'Build reusable filter sets with grouping, sorting, and ownership context.'],
                ['title' => 'Scheduled Exports', 'body' => 'Prepare CSV and PDF outputs with owner, cadence, retention, and audience.'],
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
        '/admin/settings' => [
            'label' => 'Settings',
            'section' => 'Configuration',
            'title' => 'Settings',
            'summary' => 'Configure asset types, lifecycle states, numbering, duplicate rules, imports, and notifications.',
            'items' => [
                ['title' => 'Asset Model', 'body' => 'Asset types, custom fields, required metadata, lifecycle states, and numbering policy.'],
                ['title' => 'Validation', 'body' => 'Duplicate detection, import templates, field requirements, and safe update rules.'],
                ['title' => 'Notifications', 'body' => 'Reminder rules for renewals, stale audits, transfer acceptance, and stock thresholds.'],
            ],
        ],
        '/admin/roles' => [
            'label' => 'Roles',
            'section' => 'Configuration',
            'title' => 'Roles and Permissions',
            'summary' => 'Control who can view, create, edit, transfer, retire, export, and administer records.',
            'items' => [
                ['title' => 'Role Matrix', 'body' => 'Viewer, operator, manager, admin, and future plugin-specific permission bundles.'],
                ['title' => 'LDAP Mapping', 'body' => 'Map groups to roles with explicit write permissions and fail-closed defaults.'],
                ['title' => 'Review Evidence', 'body' => 'Audit role changes, last review, owner, and production readiness.'],
            ],
        ],
        '/admin/audit-log' => [
            'label' => 'Audit Log',
            'section' => 'Configuration',
            'title' => 'Audit Log',
            'summary' => 'Review actor, action, target, source adapter, timestamp, and before/after details.',
            'items' => [
                ['title' => 'Activity Stream', 'body' => 'Immutable events for creates, updates, transfers, imports, exports, and integration syncs.'],
                ['title' => 'Change Detail', 'body' => 'Before and after values, source adapter, request context, and linked evidence.'],
                ['title' => 'Compliance Exports', 'body' => 'Filterable export bundles for asset audits, custody checks, and security reviews.'],
            ],
        ],
        '/admin/integrations' => [
            'label' => 'Integrations',
            'section' => 'Configuration',
            'title' => 'Integrations',
            'summary' => 'Configure Cacti, CSV, webhooks, LDAP, ticketing, procurement, and IPAM adapters.',
            'items' => [
                ['title' => 'Cacti', 'body' => 'Host sync, graph evidence, tree context, and exception reconciliation.'],
                ['title' => 'Data Exchange', 'body' => 'CSV import/export, webhooks, scheduled reports, and signed outbound events.'],
                ['title' => 'Enterprise Systems', 'body' => 'LDAP or SSO, ticketing, procurement, IPAM, and future service adapters.'],
            ],
        ],
    ];

    /**
     * @var array<string, array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * }>
     */
    private const array WORKSPACES = [
        '/' => [
            'actions' => ['Export Queue', 'Start Audit'],
            'metrics' => [
                ['label' => 'Tracked assets', 'value' => '14,284', 'detail' => '312 added this month'],
                ['label' => 'Unassigned', 'value' => '187', 'detail' => 'Missing owner or custodian'],
                ['label' => 'Monitoring gaps', 'value' => '42', 'detail' => 'Active assets without polling context'],
                ['label' => 'Expiring coverage', 'value' => '29', 'detail' => 'Support ends inside 60 days'],
            ],
            'tableTitle' => 'Attention Queue',
            'columns' => ['Priority', 'Item', 'Owner', 'Due'],
            'rows' => [
                ['High', 'Core router serial mismatch at SJC1', 'NetOps', 'Today'],
                ['High', 'UPS warranty expires for DAL aggregation room', 'Facilities', '3 days'],
                ['Medium', 'Nine CPE devices missing customer custody', 'Field Ops', 'This week'],
                ['Medium', 'Receiving batch PO-10482 has two duplicate serials', 'Supply', 'This week'],
            ],
            'sideTitle' => 'Recent Activity',
            'sideItems' => [
                ['label' => 'Registered', 'value' => 'MX204 edge router, SJC1'],
                ['label' => 'Transferred', 'value' => 'Spare optics kit to Denver field truck'],
                ['label' => 'Linked', 'value' => 'Cacti host core-atl-01 to asset IR-10042'],
            ],
        ],
        '/search' => [
            'actions' => ['Save Search', 'Export Results', 'Open Advanced Filters'],
            'metrics' => [
                ['label' => 'Search targets', 'value' => '11', 'detail' => 'Entities and identifiers indexed'],
                ['label' => 'Asset hits', 'value' => '42', 'detail' => 'Representative matching assets'],
                ['label' => 'Monitoring hits', 'value' => '17', 'detail' => 'Cacti hosts and graph context'],
                ['label' => 'Commercial hits', 'value' => '12', 'detail' => 'PO, contract, and vendor records'],
            ],
            'tableTitle' => 'Grouped Search Results',
            'columns' => ['Type', 'Result', 'Context', 'Action'],
            'rows' => [
                ['Asset', 'IR-10042 core-atl-01', 'Router in ATL1 with Cacti host link', 'Open asset'],
                ['Cacti Host', 'core-atl-01', 'Linked to IR-10042 with hostname alias signal', 'Review linkage'],
                ['IP Address', '10.42.0.17', 'prod-core VRF on core-atl-01', 'Open IPAM'],
                ['Contract', 'SUP-3092', 'Juniper support covering core routers', 'Review renewal'],
            ],
            'sideTitle' => 'Search Targets',
            'sideItems' => [
                ['label' => 'Identifiers', 'value' => 'Asset tag, serial, MAC, IP'],
                ['label' => 'Operations', 'value' => 'Hostname, Cacti device, location'],
                ['label' => 'Commercial', 'value' => 'PO, contract, vendor'],
            ],
        ],
        '/assets' => [
            'actions' => ['Save View', 'Bulk Edit', 'Export CSV'],
            'metrics' => [
                ['label' => 'Routers', 'value' => '426', 'detail' => '18 missing support coverage'],
                ['label' => 'Switches', 'value' => '1,882', 'detail' => '64 pending audit'],
                ['label' => 'Optics', 'value' => '8,924', 'detail' => '1,102 in spare pools'],
                ['label' => 'CPE', 'value' => '2,731', 'detail' => '117 awaiting deployment'],
            ],
            'tableTitle' => 'Asset Register',
            'columns' => ['Asset', 'Type', 'Site', 'Status', 'Custodian'],
            'rows' => [
                ['IR-10042 core-atl-01', 'Router', 'ATL1', 'In service', 'NetOps'],
                ['IR-10077 agg-den-03', 'Switch', 'DEN2', 'In service', 'Regional Ops'],
                ['IR-10112 spare-qsfp-40g-17', 'Optic', 'SJC1 Stores', 'Available', 'Supply'],
                ['IR-10204 cpe-warehouse-044', 'CPE', 'RNO Warehouse', 'Reserved', 'Field Ops'],
            ],
            'sideTitle' => 'Saved Views',
            'sideItems' => [
                ['label' => 'Core routers', 'value' => '426 assets'],
                ['label' => 'Audit stale', 'value' => '64 assets'],
                ['label' => 'No monitoring link', 'value' => '42 assets'],
            ],
        ],
        '/assets/views' => [
            'actions' => ['Create View', 'Share View', 'Schedule Review'],
            'metrics' => [
                ['label' => 'Saved views', 'value' => '18', 'detail' => 'Across five operating teams'],
                ['label' => 'Scheduled', 'value' => '7', 'detail' => 'Drive recurring review work'],
                ['label' => 'Shared', 'value' => '11', 'detail' => 'Visible to more than one team'],
                ['label' => 'Stale', 'value' => '3', 'detail' => 'No review in 90 days'],
            ],
            'tableTitle' => 'Saved View Library',
            'columns' => ['View', 'Owner', 'Filter Focus', 'Cadence', 'Next Review'],
            'rows' => [
                ['Core routers missing contract', 'NetOps', 'Routers without support', 'Weekly', 'Monday'],
                ['CPE pending deployment', 'Field Ops', 'Reserved CPE in warehouse', 'Daily', 'Tomorrow'],
                ['Spares below threshold', 'Supply', 'Optics and power supplies', 'Weekly', 'Friday'],
                ['Retired still graphing', 'Platform', 'Retired assets with Cacti hosts', 'Daily', '06:30'],
            ],
            'sideTitle' => 'Pinned Columns',
            'sideItems' => [
                ['label' => 'Identity', 'value' => 'Asset, type, serial, site'],
                ['label' => 'Ownership', 'value' => 'Owner, custodian, cost center'],
                ['label' => 'Signals', 'value' => 'Monitoring, support, audit age'],
            ],
        ],
        '/imports' => [
            'actions' => ['Upload CSV', 'Map Fields', 'Validate Batch'],
            'metrics' => [
                ['label' => 'Staged rows', 'value' => '2,148', 'detail' => 'Across three import batches'],
                ['label' => 'Valid rows', 'value' => '2,091', 'detail' => 'Ready for commit review'],
                ['label' => 'Blocked rows', 'value' => '57', 'detail' => 'Duplicates or missing required fields'],
                ['label' => 'Pending commits', 'value' => '2', 'detail' => 'Awaiting operator approval'],
            ],
            'tableTitle' => 'Import Batches',
            'columns' => ['Batch', 'Source', 'Rows', 'State', 'Owner'],
            'rows' => [
                ['IMP-2041', 'Warehouse cycle count', '1,284', 'Validated', 'Supply'],
                ['IMP-2042', 'CPE deployment sheet', '512', 'Needs mapping', 'Field Ops'],
                ['IMP-2043', 'Core optics audit', '352', 'Blocked', 'NetOps'],
            ],
            'sideTitle' => 'Validation Signals',
            'sideItems' => [
                ['label' => 'Duplicate serials', 'value' => '19 rows'],
                ['label' => 'Unknown asset types', 'value' => '23 rows'],
                ['label' => 'Missing custody', 'value' => '15 rows'],
            ],
        ],
        '/network' => [
            'actions' => ['Import LLDP', 'Reconcile Peers', 'Reserve Prefix'],
            'metrics' => [
                ['label' => 'Interfaces', 'value' => '31,442', 'detail' => '96 missing peer'],
                ['label' => 'Circuits', 'value' => '1,238', 'detail' => '14 pending carrier turn-up'],
                ['label' => 'Prefixes', 'value' => '6,104', 'detail' => '211 reserved'],
                ['label' => 'Optic alerts', 'value' => '37', 'detail' => 'Serial or speed mismatch'],
            ],
            'tableTitle' => 'Topology Worklist',
            'columns' => ['Device', 'Interface', 'Peer', 'Signal'],
            'rows' => [
                ['core-atl-01', 'et-0/0/3', 'agg-atl-04', 'Peer asset missing'],
                ['agg-den-03', 'xe-1/1/0', 'carrier handoff', 'Circuit pending'],
                ['edge-sjc-02', 'et-0/0/7', 'core-sjc-01', 'Optic serial mismatch'],
                ['cpe-rno-144', 'ge-0/0/0', 'customer access', 'No Cacti graph'],
            ],
            'sideTitle' => 'IPAM Signals',
            'sideItems' => [
                ['label' => 'Overlapping reservations', 'value' => '3 prefixes'],
                ['label' => 'Unused assignments', 'value' => '18 addresses'],
                ['label' => 'VRF drift', 'value' => '5 devices'],
            ],
        ],
        '/network/interfaces' => [
            'actions' => ['Import Interfaces', 'Reconcile Optics', 'Link Graphs'],
            'metrics' => [
                ['label' => 'Ports', 'value' => '31,442', 'detail' => 'Physical and logical interfaces'],
                ['label' => 'Peer gaps', 'value' => '96', 'detail' => 'Missing peer device or port'],
                ['label' => 'Optic drift', 'value' => '37', 'detail' => 'Serial, speed, or media mismatch'],
                ['label' => 'Graph gaps', 'value' => '64', 'detail' => 'Expected Cacti graph missing'],
            ],
            'tableTitle' => 'Interface Worklist',
            'columns' => ['Device', 'Interface', 'Speed', 'Peer', 'Signal'],
            'rows' => [
                ['core-atl-01', 'et-0/0/3', '100G', 'agg-atl-04 xe-0/0/1', 'Peer asset missing'],
                ['edge-sjc-02', 'et-0/0/7', '100G', 'core-sjc-01 et-2/1/0', 'Optic serial mismatch'],
                ['agg-den-03', 'xe-1/1/0', '10G', 'Carrier handoff', 'Circuit pending'],
                ['cpe-rno-144', 'ge-0/0/0', '1G', 'Customer access', 'No Cacti graph'],
            ],
            'sideTitle' => 'Reconcile Inputs',
            'sideItems' => [
                ['label' => 'LLDP snapshot', 'value' => '31,102 neighbors'],
                ['label' => 'Optic inventory', 'value' => '8,924 serials'],
                ['label' => 'Cacti graphs', 'value' => '74,210 checked'],
            ],
        ],
        '/network/ipam' => [
            'actions' => ['Reserve Prefix', 'Assign Address', 'Run Drift Check'],
            'metrics' => [
                ['label' => 'Prefixes', 'value' => '6,104', 'detail' => 'Production, management, and customer pools'],
                ['label' => 'Reservations', 'value' => '211', 'detail' => 'Held for planned work'],
                ['label' => 'Overlaps', 'value' => '3', 'detail' => 'Need owner resolution'],
                ['label' => 'Stale', 'value' => '18', 'detail' => 'Unused assignments detected'],
            ],
            'tableTitle' => 'Prefix and Address Worklist',
            'columns' => ['Prefix', 'VRF', 'Site', 'Owner', 'Signal'],
            'rows' => [
                ['10.42.0.0/22', 'prod-core', 'ATL1', 'NetOps', '84% utilized'],
                ['10.77.16.0/24', 'mgmt', 'DEN2', 'Platform', '18 stale assignments'],
                ['172.18.40.0/23', 'customer-edge', 'RNO', 'Field Ops', 'Overlap candidate'],
                ['192.0.2.64/27', 'lab', 'SJC1', 'Engineering', 'Reservation expires soon'],
            ],
            'sideTitle' => 'Drift Signals',
            'sideItems' => [
                ['label' => 'Live but unassigned', 'value' => '14 addresses'],
                ['label' => 'Wrong VRF', 'value' => '5 interfaces'],
                ['label' => 'Expired holds', 'value' => '9 reservations'],
            ],
        ],
        '/locations' => [
            'actions' => ['Add Site', 'Plan Rack Move', 'Print Rack Labels'],
            'metrics' => [
                ['label' => 'Sites', 'value' => '118', 'detail' => '32 carrier hotels'],
                ['label' => 'Racks', 'value' => '842', 'detail' => '71 above 80% RU use'],
                ['label' => 'Warehouses', 'value' => '9', 'detail' => '4 with pending counts'],
                ['label' => 'Vehicles', 'value' => '46', 'detail' => '12 hold assigned spares'],
            ],
            'tableTitle' => 'Location Directory',
            'columns' => ['Location', 'Type', 'Occupancy', 'Open Work'],
            'rows' => [
                ['SJC1 Row C Rack 14', 'Rack', '38/42 RU', 'Power audit'],
                ['DEN2 Cage B', 'Site', '71% used', 'Access note update'],
                ['RNO Warehouse Bin 7', 'Storage', '184 assets', 'Cycle count'],
                ['Truck NV-12', 'Vehicle', '26 assets', 'Transfer review'],
            ],
            'sideTitle' => 'Placement Conflicts',
            'sideItems' => [
                ['label' => 'RU conflict', 'value' => '2 racks'],
                ['label' => 'Power oversubscription', 'value' => '5 racks'],
                ['label' => 'Missing access contact', 'value' => '7 sites'],
            ],
        ],
        '/locations/racks' => [
            'actions' => ['Reserve RU', 'Check Power', 'Print Elevation'],
            'metrics' => [
                ['label' => 'Racks tracked', 'value' => '842', 'detail' => 'Across sites, rooms, and cages'],
                ['label' => 'Above 80%', 'value' => '71', 'detail' => 'High RU utilization'],
                ['label' => 'Power conflicts', 'value' => '5', 'detail' => 'Need feed review'],
                ['label' => 'Reserved RU', 'value' => '184', 'detail' => 'Held for planned installs'],
            ],
            'tableTitle' => 'Rack Placement Worklist',
            'columns' => ['Rack', 'RU Used', 'Power', 'Reserved', 'Signal'],
            'rows' => [
                ['SJC1 Row C Rack 14', '38/42', 'A/B healthy', '4 RU', 'Power audit'],
                ['ATL1 Row A Rack 09', '41/42', 'A feed high', '0 RU', 'Placement conflict'],
                ['DEN2 Cage B Rack 03', '34/42', 'B feed review', '6 RU', 'Reserve for aggregation'],
                ['RNO Warehouse Rack 02', '22/42', 'Single feed', '12 RU', 'Staging only'],
            ],
            'sideTitle' => 'Placement Rules',
            'sideItems' => [
                ['label' => 'Weight review', 'value' => '9 installs'],
                ['label' => 'Airflow conflict', 'value' => '3 racks'],
                ['label' => 'Blanking panels', 'value' => '28 missing'],
            ],
        ],
        '/people' => [
            'actions' => ['Add Contact', 'Request Return', 'Export Custody'],
            'metrics' => [
                ['label' => 'Custodians', 'value' => '218', 'detail' => 'People and teams with assets'],
                ['label' => 'External contacts', 'value' => '64', 'detail' => 'Vendors and site contacts'],
                ['label' => 'Overdue returns', 'value' => '31', 'detail' => 'Need follow-up'],
                ['label' => 'Critical holdings', 'value' => '49', 'detail' => 'Assets requiring named owner'],
            ],
            'tableTitle' => 'Custodian Directory',
            'columns' => ['Name', 'Team', 'Location', 'Assets', 'Signal'],
            'rows' => [
                ['Maya Chen', 'NetOps', 'SJC1', '18', '4 critical assets'],
                ['Field Ops East', 'Field Ops', 'ATL1', '42', '7 overdue returns'],
                ['Truck NV-12', 'Vehicle', 'RNO', '26', 'Cycle count due'],
                ['Vendor RMA Desk', 'External', 'Remote', '5', 'Awaiting shipment'],
            ],
            'sideTitle' => 'Custody Follow-up',
            'sideItems' => [
                ['label' => 'Missing receiver', 'value' => '11 assets'],
                ['label' => 'No contact route', 'value' => '6 custodians'],
                ['label' => 'Review due', 'value' => '18 assignments'],
            ],
        ],
        '/custody' => [
            'actions' => ['Create Transfer', 'Accept Batch', 'Request Return'],
            'metrics' => [
                ['label' => 'Pending transfers', 'value' => '34', 'detail' => '8 overdue'],
                ['label' => 'Assigned people', 'value' => '218', 'detail' => '31 hold critical assets'],
                ['label' => 'Vehicle kits', 'value' => '46', 'detail' => '6 need count'],
                ['label' => 'Audit evidence', 'value' => '91%', 'detail' => 'Receiver confirmation coverage'],
            ],
            'tableTitle' => 'Custody Transfers',
            'columns' => ['Transfer', 'Assets', 'From', 'To', 'State'],
            'rows' => [
                ['TR-1044', '7', 'RNO Warehouse', 'Truck NV-12', 'Pending accept'],
                ['TR-1045', '1', 'NetOps', 'Vendor RMA', 'In transit'],
                ['TR-1046', '12', 'ATL Stores', 'Field Ops East', 'Draft'],
                ['TR-1047', '3', 'Truck CO-04', 'DEN2 Cage B', 'Overdue'],
            ],
            'sideTitle' => 'Custody Exceptions',
            'sideItems' => [
                ['label' => 'Overdue accepts', 'value' => '8 transfers'],
                ['label' => 'Missing receiver', 'value' => '11 assets'],
                ['label' => 'No audit photo', 'value' => '17 transfers'],
            ],
        ],
        '/procurement' => [
            'actions' => ['Receive PO', 'Normalize Model', 'Print Labels'],
            'metrics' => [
                ['label' => 'Open POs', 'value' => '28', 'detail' => '$1.42M committed'],
                ['label' => 'Receiving holds', 'value' => '9', 'detail' => 'Serial or quantity issue'],
                ['label' => 'Vendor models', 'value' => '614', 'detail' => '23 need normalization'],
                ['label' => 'Labels queued', 'value' => '138', 'detail' => 'Ready for print'],
            ],
            'tableTitle' => 'Receiving Queue',
            'columns' => ['PO', 'Vendor', 'Expected', 'Exception'],
            'rows' => [
                ['PO-10482', 'Juniper', '18 routers', '2 duplicate serials'],
                ['PO-10491', 'FS', '400 optics', 'Awaiting count'],
                ['PO-10502', 'APC', '12 UPS units', 'No asset class mapping'],
                ['PO-10511', 'Dell', '24 servers', 'Ready to receive'],
            ],
            'sideTitle' => 'Vendor Tasks',
            'sideItems' => [
                ['label' => 'Normalize', 'value' => '23 model names'],
                ['label' => 'Support dates', 'value' => '18 lines missing'],
                ['label' => 'RMA credits', 'value' => '4 pending'],
            ],
        ],
        '/procurement/receiving' => [
            'actions' => ['Scan Serials', 'Create Assets', 'Print Labels'],
            'metrics' => [
                ['label' => 'Open batches', 'value' => '9', 'detail' => 'Awaiting receiving work'],
                ['label' => 'Labels queued', 'value' => '138', 'detail' => 'Ready for print'],
                ['label' => 'Holds', 'value' => '14', 'detail' => 'Duplicate or damaged goods'],
                ['label' => 'Ready assets', 'value' => '212', 'detail' => 'Can be committed after review'],
            ],
            'tableTitle' => 'Receiving Workbench',
            'columns' => ['Batch', 'PO', 'Received', 'State', 'Next Step'],
            'rows' => [
                ['RCV-8821', 'PO-10482', '16/18 routers', 'Hold', 'Resolve duplicate serials'],
                ['RCV-8822', 'PO-10491', '400/400 optics', 'Ready', 'Print labels'],
                ['RCV-8823', 'PO-10502', '8/12 UPS units', 'Partial', 'Await remaining shipment'],
                ['RCV-8824', 'PO-10511', '24/24 servers', 'Mapped', 'Create assets'],
            ],
            'sideTitle' => 'Receiving Exceptions',
            'sideItems' => [
                ['label' => 'Duplicate serials', 'value' => '2 units'],
                ['label' => 'Damaged goods', 'value' => '3 units'],
                ['label' => 'Model mismatch', 'value' => '5 lines'],
            ],
        ],
        '/procurement/vendors' => [
            'actions' => ['Add Vendor', 'Normalize Model', 'Review Support'],
            'metrics' => [
                ['label' => 'Vendors', 'value' => '86', 'detail' => 'Active suppliers and OEMs'],
                ['label' => 'Models', 'value' => '614', 'detail' => 'Canonical model records'],
                ['label' => 'Aliases', 'value' => '143', 'detail' => 'Mapped import names'],
                ['label' => 'Needs review', 'value' => '23', 'detail' => 'Missing required fields'],
            ],
            'tableTitle' => 'Vendor and Model Catalog',
            'columns' => ['Vendor', 'Model', 'Type', 'Support State', 'Signal'],
            'rows' => [
                ['Juniper', 'MX204', 'Router', 'Supported', 'Required fields complete'],
                ['FS', 'QSFP-40G-LR4', 'Optic', 'Supported', 'Alias review'],
                ['APC', 'SRT5KRMXLT', 'UPS', 'Supported', 'Support dates missing'],
                ['Dell', 'R660', 'Server', 'Supported', 'Warranty mapping pending'],
            ],
            'sideTitle' => 'Catalog Quality',
            'sideItems' => [
                ['label' => 'Duplicate aliases', 'value' => '12 names'],
                ['label' => 'Missing lifecycle', 'value' => '18 models'],
                ['label' => 'Unsafe substitution', 'value' => '4 models'],
            ],
        ],
        '/contracts' => [
            'actions' => ['Add Contract', 'Review Renewal', 'Attach Document'],
            'metrics' => [
                ['label' => 'Active contracts', 'value' => '342', 'detail' => '$8.7M annualized'],
                ['label' => 'Renewals', 'value' => '29', 'detail' => 'Inside 60 days'],
                ['label' => 'Uncovered critical', 'value' => '18', 'detail' => 'No active support'],
                ['label' => 'Leased assets', 'value' => '441', 'detail' => '22 end this quarter'],
            ],
            'tableTitle' => 'Renewal Pipeline',
            'columns' => ['Contract', 'Vendor', 'Coverage', 'Owner', 'Due'],
            'rows' => [
                ['SUP-3092', 'Juniper', 'Core routers', 'NetOps', '18 days'],
                ['SUP-3110', 'APC', 'UPS fleet', 'Facilities', '31 days'],
                ['LIC-2018', 'IPAM', 'Address registry', 'Platform', '44 days'],
                ['LEASE-884', 'Dell', 'Edge compute', 'Finance', '58 days'],
            ],
            'sideTitle' => 'Coverage Gaps',
            'sideItems' => [
                ['label' => 'Critical routers', 'value' => '4 assets'],
                ['label' => 'UPS devices', 'value' => '9 assets'],
                ['label' => 'Server nodes', 'value' => '5 assets'],
            ],
        ],
        '/maintenance' => [
            'actions' => ['Schedule Window', 'Open RMA', 'Reserve Spare'],
            'metrics' => [
                ['label' => 'Open work', 'value' => '76', 'detail' => '19 scheduled'],
                ['label' => 'RMAs', 'value' => '14', 'detail' => '5 awaiting vendor'],
                ['label' => 'Spare pools', 'value' => '38', 'detail' => '6 below threshold'],
                ['label' => 'Retirements', 'value' => '112', 'detail' => 'Pending disposition'],
            ],
            'tableTitle' => 'Maintenance Work',
            'columns' => ['Work', 'Asset', 'Window', 'Owner', 'State'],
            'rows' => [
                ['MW-2041', 'core-atl-01', 'Tonight 23:00', 'NetOps', 'Ready'],
                ['RMA-8841', 'linecard-sjc-04', 'Vendor ship', 'Supply', 'Waiting'],
                ['MW-2047', 'UPS-DAL-02', 'Saturday 02:00', 'Facilities', 'Review'],
                ['RET-4412', 'CPE batch 2021', 'Queued', 'Field Ops', 'Dispose'],
            ],
            'sideTitle' => 'Spare Pool Alerts',
            'sideItems' => [
                ['label' => '40G optics', 'value' => 'Below threshold'],
                ['label' => 'MX fan trays', 'value' => '2 remaining'],
                ['label' => 'CPE power supplies', 'value' => 'Reorder pending'],
            ],
        ],
        '/maintenance/calendar' => [
            'actions' => ['Schedule Window', 'Confirm Readiness', 'Attach Ticket'],
            'metrics' => [
                ['label' => 'Windows', 'value' => '19', 'detail' => 'Scheduled maintenance'],
                ['label' => 'Ready', 'value' => '11', 'detail' => 'Approved and staffed'],
                ['label' => 'Needs spares', 'value' => '5', 'detail' => 'Awaiting reservation'],
                ['label' => 'Customer impact', 'value' => '7', 'detail' => 'Require notice tracking'],
            ],
            'tableTitle' => 'Maintenance Calendar',
            'columns' => ['Window', 'Asset Scope', 'Owner', 'Risk', 'State'],
            'rows' => [
                ['Tonight 23:00', 'core-atl-01 linecard', 'NetOps', 'High', 'Ready'],
                ['Saturday 02:00', 'UPS-DAL-02 battery', 'Facilities', 'Medium', 'Review'],
                ['Aug 4 01:00', 'DEN aggregation optics', 'Regional Ops', 'Medium', 'Needs spares'],
                ['Aug 6 00:30', 'RNO CPE firmware batch', 'Field Ops', 'Low', 'Draft'],
            ],
            'sideTitle' => 'Readiness Checks',
            'sideItems' => [
                ['label' => 'Approvals missing', 'value' => '4 windows'],
                ['label' => 'Rollback notes', 'value' => '6 windows'],
                ['label' => 'Customer notice', 'value' => '7 windows'],
            ],
        ],
        '/maintenance/rma' => [
            'actions' => ['Open RMA', 'Ship Asset', 'Receive Replacement'],
            'metrics' => [
                ['label' => 'Open RMAs', 'value' => '14', 'detail' => 'Vendor cases in progress'],
                ['label' => 'Awaiting vendor', 'value' => '5', 'detail' => 'No replacement yet'],
                ['label' => 'In transit', 'value' => '4', 'detail' => 'Shipment active'],
                ['label' => 'Disposition due', 'value' => '3', 'detail' => 'Need final action'],
            ],
            'tableTitle' => 'RMA and Repair Queue',
            'columns' => ['Case', 'Asset', 'Vendor', 'State', 'Next Step'],
            'rows' => [
                ['RMA-8841', 'linecard-sjc-04', 'Juniper', 'Waiting', 'Vendor replacement'],
                ['RMA-8842', 'UPS-DAL-02 battery', 'APC', 'Approved', 'Ship failed unit'],
                ['RMA-8843', 'edge-rno-07 PSU', 'Dell', 'In transit', 'Track shipment'],
                ['RMA-8844', 'QSFP batch 18', 'FS', 'Credit review', 'Confirm disposition'],
            ],
            'sideTitle' => 'Repair Signals',
            'sideItems' => [
                ['label' => 'Spares consumed', 'value' => '9 assets'],
                ['label' => 'Credits pending', 'value' => '4 cases'],
                ['label' => 'Vendor SLA risk', 'value' => '2 cases'],
            ],
        ],
        '/maintenance/spares' => [
            'actions' => ['Set Threshold', 'Reserve Spare', 'Create Reorder'],
            'metrics' => [
                ['label' => 'Pools', 'value' => '38', 'detail' => 'Tracked spare pools'],
                ['label' => 'Below threshold', 'value' => '6', 'detail' => 'Need replenishment'],
                ['label' => 'Reserved', 'value' => '112', 'detail' => 'Held for planned work'],
                ['label' => 'Open reorders', 'value' => '9', 'detail' => 'Procurement in progress'],
            ],
            'tableTitle' => 'Spare Pool Thresholds',
            'columns' => ['Pool', 'Site', 'On Hand', 'Reserved', 'Signal'],
            'rows' => [
                ['40G optics', 'SJC1 Stores', '18', '7', 'Below threshold'],
                ['MX fan trays', 'ATL1 Stores', '2', '1', 'Reorder now'],
                ['CPE power supplies', 'RNO Warehouse', '44', '12', 'Reorder pending'],
                ['UPS batteries', 'DAL Facilities', '9', '3', 'Healthy'],
            ],
            'sideTitle' => 'Replenishment',
            'sideItems' => [
                ['label' => 'Lead time risk', 'value' => '3 models'],
                ['label' => 'Substitutions', 'value' => '4 approved'],
                ['label' => 'PO links', 'value' => '9 open'],
            ],
        ],
        '/monitoring' => [
            'actions' => ['Run Reconcile', 'Link Host', 'Suppress Exception'],
            'metrics' => [
                ['label' => 'Linked hosts', 'value' => '5,812', 'detail' => '96.4% of monitored hosts'],
                ['label' => 'Unlinked assets', 'value' => '42', 'detail' => 'Active and expected monitored'],
                ['label' => 'Orphan hosts', 'value' => '17', 'detail' => 'Polling without asset record'],
                ['label' => 'Graph gaps', 'value' => '64', 'detail' => 'Missing key interface graphs'],
            ],
            'tableTitle' => 'Monitoring Exceptions',
            'columns' => ['Signal', 'Asset', 'Cacti Host', 'Action'],
            'rows' => [
                ['Hostname mismatch', 'IR-10042', 'core-atl-01', 'Review alias'],
                ['No asset record', 'Unknown', 'old-cpe-rno-77', 'Create or retire'],
                ['Missing graphs', 'IR-10077', 'agg-den-03', 'Add graph template'],
                ['Retired still polling', 'IR-09011', 'retired-edge-02', 'Disable host'],
            ],
            'sideTitle' => 'Reconcile Sources',
            'sideItems' => [
                ['label' => 'Cacti hosts', 'value' => '6,028 scanned'],
                ['label' => 'Graph trees', 'value' => '214 scanned'],
                ['label' => 'Polling state', 'value' => '17 exceptions'],
            ],
        ],
        '/monitoring/cacti' => [
            'actions' => ['Sync Hosts', 'Link Asset', 'Review Graphs'],
            'metrics' => [
                ['label' => 'Cacti hosts', 'value' => '6,028', 'detail' => 'Discovered from monitoring'],
                ['label' => 'Linked assets', 'value' => '5,812', 'detail' => 'Have inventory record'],
                ['label' => 'Graph trees', 'value' => '214', 'detail' => 'Scanned for context'],
                ['label' => 'Polling gaps', 'value' => '17', 'detail' => 'Need operational review'],
            ],
            'tableTitle' => 'Cacti Linkage Worklist',
            'columns' => ['Cacti Host', 'Asset', 'Graphs', 'Polling', 'Signal'],
            'rows' => [
                ['core-atl-01', 'IR-10042', '128', 'Up', 'Hostname alias review'],
                ['agg-den-03', 'IR-10077', '84', 'Up', 'Interface graph gap'],
                ['old-cpe-rno-77', 'Unlinked', '12', 'Up', 'Create or retire'],
                ['retired-edge-02', 'IR-09011', '21', 'Up', 'Retired still polling'],
            ],
            'sideTitle' => 'Sync Sources',
            'sideItems' => [
                ['label' => 'Host table', 'value' => '6,028 rows'],
                ['label' => 'Graph local', 'value' => '74,210 graphs'],
                ['label' => 'Tree nodes', 'value' => '214 trees'],
            ],
        ],
        '/monitoring/exceptions' => [
            'actions' => ['Assign Exception', 'Suppress Known', 'Resolve Mismatch'],
            'metrics' => [
                ['label' => 'Open exceptions', 'value' => '123', 'detail' => 'Across host and graph checks'],
                ['label' => 'High severity', 'value' => '18', 'detail' => 'Affect monitored core assets'],
                ['label' => 'Suppressed', 'value' => '14', 'detail' => 'Known temporary drift'],
                ['label' => 'Resolved today', 'value' => '9', 'detail' => 'Closed by reconcile work'],
            ],
            'tableTitle' => 'Monitoring Exception Queue',
            'columns' => ['Exception', 'Asset', 'Host', 'Owner', 'State'],
            'rows' => [
                ['Hostname mismatch', 'IR-10042', 'core-atl-01', 'NetOps', 'Review'],
                ['Duplicate link', 'IR-10077', 'agg-den-03', 'Platform', 'Assigned'],
                ['Missing serial', 'Unknown', 'old-cpe-rno-77', 'Field Ops', 'Needs asset'],
                ['Retired polling', 'IR-09011', 'retired-edge-02', 'NetOps', 'Disable host'],
            ],
            'sideTitle' => 'Exception Types',
            'sideItems' => [
                ['label' => 'Hostname mismatch', 'value' => '31 open'],
                ['label' => 'Graph gaps', 'value' => '64 open'],
                ['label' => 'Retired polling', 'value' => '11 open'],
            ],
        ],
        '/reports' => [
            'actions' => ['Schedule Report', 'Export CSV', 'Build Filter'],
            'metrics' => [
                ['label' => 'Saved reports', 'value' => '48', 'detail' => '12 scheduled'],
                ['label' => 'Exports today', 'value' => '31', 'detail' => 'CSV and PDF'],
                ['label' => 'Compliance gaps', 'value' => '57', 'detail' => 'Missing metadata or evidence'],
                ['label' => 'Financial views', 'value' => '9', 'detail' => 'Cost, coverage, lease'],
            ],
            'tableTitle' => 'Report Library',
            'columns' => ['Report', 'Audience', 'Cadence', 'Last Run'],
            'rows' => [
                ['Asset audit exceptions', 'Operations', 'Daily', '07:00'],
                ['Contract renewal risk', 'Finance', 'Weekly', 'Monday'],
                ['Monitoring coverage', 'NetOps', 'Daily', '06:30'],
                ['Warehouse cycle count', 'Supply', 'Monthly', 'Jul 28'],
            ],
            'sideTitle' => 'Export Queue',
            'sideItems' => [
                ['label' => 'CSV ready', 'value' => '7 files'],
                ['label' => 'PDF ready', 'value' => '3 files'],
                ['label' => 'Scheduled next', 'value' => 'Monitoring coverage'],
            ],
        ],
        '/reports/builder' => [
            'actions' => ['Add Field', 'Save Report', 'Schedule Export'],
            'metrics' => [
                ['label' => 'Fields', 'value' => '142', 'detail' => 'Available report fields'],
                ['label' => 'Saved drafts', 'value' => '6', 'detail' => 'Report definitions in progress'],
                ['label' => 'Scheduled', 'value' => '12', 'detail' => 'Reports with cadence'],
                ['label' => 'Audiences', 'value' => '9', 'detail' => 'Operational and finance groups'],
            ],
            'tableTitle' => 'Report Builder Drafts',
            'columns' => ['Draft', 'Owner', 'Fields', 'Filters', 'Output'],
            'rows' => [
                ['Critical asset coverage', 'Operations', '18', 'Support and monitoring', 'CSV'],
                ['Custody overdue by team', 'Field Ops', '12', 'Transfer state', 'PDF'],
                ['Warranty exposure by site', 'Finance', '16', 'Renewal window', 'CSV'],
                ['Warehouse stock variance', 'Supply', '14', 'Site and model', 'CSV'],
            ],
            'sideTitle' => 'Builder Blocks',
            'sideItems' => [
                ['label' => 'Asset fields', 'value' => '58 available'],
                ['label' => 'Relationship fields', 'value' => '44 available'],
                ['label' => 'Export options', 'value' => 'CSV and PDF'],
            ],
        ],
        '/admin' => [
            'actions' => ['Add Role Mapping', 'Import Types', 'Audit Settings'],
            'metrics' => [
                ['label' => 'Roles', 'value' => '4', 'detail' => 'Viewer, operator, manager, admin'],
                ['label' => 'LDAP maps', 'value' => '3', 'detail' => 'Groups mapped to roles'],
                ['label' => 'Asset types', 'value' => '42', 'detail' => '8 require custom fields'],
                ['label' => 'Integrations', 'value' => '6', 'detail' => '2 need credentials'],
            ],
            'tableTitle' => 'Configuration Checklist',
            'columns' => ['Area', 'Setting', 'State', 'Owner'],
            'rows' => [
                ['RBAC', 'LDAP group role map', 'Configured', 'Platform'],
                ['Lifecycle', 'Retirement approvals', 'Draft', 'Operations'],
                ['Fields', 'Optic serial required', 'Enabled', 'NetOps'],
                ['Integrations', 'Cacti host sync', 'Planned', 'Plugin'],
            ],
            'sideTitle' => 'Security Review',
            'sideItems' => [
                ['label' => 'Local users', 'value' => 'Dev only'],
                ['label' => 'LDAP bind', 'value' => 'Service account'],
                ['label' => 'Write action', 'value' => 'asset.register'],
            ],
        ],
        '/admin/settings' => [
            'actions' => ['Add Asset Type', 'Edit Lifecycle', 'Test Duplicate Rules'],
            'metrics' => [
                ['label' => 'Asset types', 'value' => '42', 'detail' => 'Configured inventory classes'],
                ['label' => 'Custom fields', 'value' => '118', 'detail' => 'Across asset types'],
                ['label' => 'Lifecycle states', 'value' => '9', 'detail' => 'Active through retired'],
                ['label' => 'Import templates', 'value' => '7', 'detail' => 'Reusable mappings'],
            ],
            'tableTitle' => 'Settings Checklist',
            'columns' => ['Area', 'Setting', 'State', 'Owner'],
            'rows' => [
                ['Asset types', 'Optic serial required', 'Enabled', 'NetOps'],
                ['Lifecycle', 'Retirement approvals', 'Draft', 'Operations'],
                ['Numbering', 'IR prefix sequence', 'Configured', 'Platform'],
                ['Imports', 'Warehouse CSV template', 'Review', 'Supply'],
            ],
            'sideTitle' => 'Validation Rules',
            'sideItems' => [
                ['label' => 'Duplicate serials', 'value' => 'Blocking'],
                ['label' => 'Missing required fields', 'value' => 'Blocking'],
                ['label' => 'Unsafe lifecycle move', 'value' => 'Review'],
            ],
        ],
        '/admin/roles' => [
            'actions' => ['Add Role Mapping', 'Review Permissions', 'Test LDAP'],
            'metrics' => [
                ['label' => 'Roles', 'value' => '4', 'detail' => 'Viewer, operator, manager, admin'],
                ['label' => 'LDAP maps', 'value' => '3', 'detail' => 'Groups mapped to roles'],
                ['label' => 'Write permissions', 'value' => '7', 'detail' => 'Guarded mutation capabilities'],
                ['label' => 'Review due', 'value' => '2', 'detail' => 'Mappings need owner review'],
            ],
            'tableTitle' => 'Role Permission Matrix',
            'columns' => ['Role', 'View', 'Create', 'Transfer', 'Admin'],
            'rows' => [
                ['Viewer', 'Allowed', 'Denied', 'Denied', 'Denied'],
                ['Operator', 'Allowed', 'Allowed', 'Allowed', 'Denied'],
                ['Manager', 'Allowed', 'Allowed', 'Allowed', 'Review settings'],
                ['Admin', 'Allowed', 'Allowed', 'Allowed', 'Allowed'],
            ],
            'sideTitle' => 'LDAP Mapping',
            'sideItems' => [
                ['label' => 'cn=infra-viewers', 'value' => 'Viewer'],
                ['label' => 'cn=infra-operators', 'value' => 'Operator'],
                ['label' => 'cn=infra-admins', 'value' => 'Admin'],
            ],
        ],
        '/admin/audit-log' => [
            'actions' => ['Filter Events', 'Export Evidence', 'Review Source'],
            'metrics' => [
                ['label' => 'Events today', 'value' => '312', 'detail' => 'Creates, changes, and reads'],
                ['label' => 'Write events', 'value' => '48', 'detail' => 'Mutations needing audit trail'],
                ['label' => 'Import events', 'value' => '21', 'detail' => 'Batch and row activity'],
                ['label' => 'Integration events', 'value' => '96', 'detail' => 'Cacti and adapter sync'],
            ],
            'tableTitle' => 'Audit Event Stream',
            'columns' => ['Time', 'Actor', 'Action', 'Target', 'Source'],
            'rows' => [
                ['07:42', 'mchen', 'asset.register', 'IR-10312', 'web'],
                ['07:38', 'system', 'cacti.reconcile', 'core-atl-01', 'cacti'],
                ['07:31', 'supply-bot', 'import.validate', 'IMP-2041', 'csv'],
                ['07:14', 'admin', 'role.map', 'cn=infra-operators', 'settings'],
            ],
            'sideTitle' => 'Evidence Bundles',
            'sideItems' => [
                ['label' => 'Asset audit', 'value' => 'Ready'],
                ['label' => 'Custody review', 'value' => '31 events'],
                ['label' => 'Security review', 'value' => '2 mappings due'],
            ],
        ],
        '/admin/integrations' => [
            'actions' => ['Configure Cacti', 'Test Webhook', 'Import Mapping'],
            'metrics' => [
                ['label' => 'Integrations', 'value' => '6', 'detail' => 'Configured or planned adapters'],
                ['label' => 'Healthy', 'value' => '4', 'detail' => 'Last check succeeded'],
                ['label' => 'Need credentials', 'value' => '2', 'detail' => 'Blocked until configured'],
                ['label' => 'Webhooks', 'value' => '3', 'detail' => 'Outbound event targets'],
            ],
            'tableTitle' => 'Integration Registry',
            'columns' => ['Integration', 'Purpose', 'State', 'Owner'],
            'rows' => [
                ['Cacti', 'Host and graph linkage', 'Configured', 'Plugin'],
                ['CSV', 'Import and export', 'Configured', 'Platform'],
                ['Webhooks', 'Outbound events', 'Review', 'Platform'],
                ['LDAP', 'Identity and role mapping', 'Needs credentials', 'Security'],
            ],
            'sideTitle' => 'Adapter Signals',
            'sideItems' => [
                ['label' => 'Cacti sync', 'value' => 'Last run 06:30'],
                ['label' => 'Webhook retries', 'value' => '2 pending'],
                ['label' => 'LDAP bind', 'value' => 'Not configured'],
            ],
        ],
    ];

    /**
     * @var array<string, array{
     *     name: string,
     *     type: string,
     *     occupancy: string,
     *     work: string,
     *     address: string,
     *     access: string,
     *     power: string
     * }>
     */
    private const array LOCATIONS = [
        'sjc1-row-c-rack-14' => [
            'name' => 'SJC1 Row C Rack 14',
            'type' => 'Rack',
            'occupancy' => '38/42 RU',
            'work' => 'Power audit',
            'address' => 'San Jose, CA',
            'access' => 'Badge and cage escort',
            'power' => 'A/B feeds monitored',
        ],
        'den2-cage-b' => [
            'name' => 'DEN2 Cage B',
            'type' => 'Site',
            'occupancy' => '71% used',
            'work' => 'Access note update',
            'address' => 'Denver, CO',
            'access' => 'NOC approval required',
            'power' => 'Utility and generator',
        ],
        'rno-warehouse-bin-7' => [
            'name' => 'RNO Warehouse Bin 7',
            'type' => 'Storage',
            'occupancy' => '184 assets',
            'work' => 'Cycle count',
            'address' => 'Reno, NV',
            'access' => 'Warehouse staff',
            'power' => 'Not powered',
        ],
        'truck-nv-12' => [
            'name' => 'Truck NV-12',
            'type' => 'Vehicle',
            'occupancy' => '26 assets',
            'work' => 'Transfer review',
            'address' => 'Northern Nevada route',
            'access' => 'Assigned field crew',
            'power' => 'Vehicle inverter',
        ],
    ];

    /**
     * @var array<string, array{
     *     number: string,
     *     assets: string,
     *     from: string,
     *     to: string,
     *     state: string,
     *     owner: string,
     *     due: string,
     *     evidence: string
     * }>
     */
    private const array CUSTODY_TRANSFERS = [
        'tr-1044' => [
            'number' => 'TR-1044',
            'assets' => '7',
            'from' => 'RNO Warehouse',
            'to' => 'Truck NV-12',
            'state' => 'Pending accept',
            'owner' => 'Field Ops West',
            'due' => 'Today',
            'evidence' => 'Receiver acknowledgement required',
        ],
        'tr-1045' => [
            'number' => 'TR-1045',
            'assets' => '1',
            'from' => 'NetOps',
            'to' => 'Vendor RMA',
            'state' => 'In transit',
            'owner' => 'Maintenance',
            'due' => '3 days',
            'evidence' => 'Carrier tracking attached',
        ],
        'tr-1046' => [
            'number' => 'TR-1046',
            'assets' => '12',
            'from' => 'ATL Stores',
            'to' => 'Field Ops East',
            'state' => 'Draft',
            'owner' => 'Supply',
            'due' => 'This week',
            'evidence' => 'Awaiting pick list',
        ],
        'tr-1047' => [
            'number' => 'TR-1047',
            'assets' => '3',
            'from' => 'Truck CO-04',
            'to' => 'DEN2 Cage B',
            'state' => 'Overdue',
            'owner' => 'Regional Ops',
            'due' => 'Yesterday',
            'evidence' => 'No receiver photo',
        ],
    ];

    /**
     * @var array<string, array{
     *     signal: string,
     *     asset: string,
     *     host: string,
     *     action: string,
     *     severity: string,
     *     source: string,
     *     owner: string,
     *     next: string
     * }>
     */
    private const array MONITORING_EXCEPTIONS = [
        'hostname-mismatch-core-atl-01' => [
            'signal' => 'Hostname mismatch',
            'asset' => 'IR-10042',
            'host' => 'core-atl-01',
            'action' => 'Review alias',
            'severity' => 'Medium',
            'source' => 'Cacti host inventory',
            'owner' => 'NetOps',
            'next' => 'Confirm hostname and asset alias',
        ],
        'no-asset-record-old-cpe-rno-77' => [
            'signal' => 'No asset record',
            'asset' => 'Unknown',
            'host' => 'old-cpe-rno-77',
            'action' => 'Create or retire',
            'severity' => 'High',
            'source' => 'Polling state',
            'owner' => 'Field Ops',
            'next' => 'Create asset record or disable retired host',
        ],
        'missing-graphs-agg-den-03' => [
            'signal' => 'Missing graphs',
            'asset' => 'IR-10077',
            'host' => 'agg-den-03',
            'action' => 'Add graph template',
            'severity' => 'Medium',
            'source' => 'Graph tree scan',
            'owner' => 'Regional Ops',
            'next' => 'Attach interface graph template',
        ],
        'retired-still-polling-retired-edge-02' => [
            'signal' => 'Retired still polling',
            'asset' => 'IR-09011',
            'host' => 'retired-edge-02',
            'action' => 'Disable host',
            'severity' => 'High',
            'source' => 'Lifecycle reconciliation',
            'owner' => 'NetOps',
            'next' => 'Disable polling after final validation',
        ],
    ];

    /**
     * @var array<string, array{
     *     number: string,
     *     source: string,
     *     rows: string,
     *     state: string,
     *     owner: string,
     *     valid: string,
     *     blocked: string,
     *     next: string
     * }>
     */
    private const array IMPORT_BATCHES = [
        'imp-2041' => [
            'number' => 'IMP-2041',
            'source' => 'Warehouse cycle count',
            'rows' => '1,284',
            'state' => 'Validated',
            'owner' => 'Supply',
            'valid' => '1,281',
            'blocked' => '3',
            'next' => 'Review commit summary',
        ],
        'imp-2042' => [
            'number' => 'IMP-2042',
            'source' => 'CPE deployment sheet',
            'rows' => '512',
            'state' => 'Needs mapping',
            'owner' => 'Field Ops',
            'valid' => '498',
            'blocked' => '14',
            'next' => 'Map customer premise fields',
        ],
        'imp-2043' => [
            'number' => 'IMP-2043',
            'source' => 'Core optics audit',
            'rows' => '352',
            'state' => 'Blocked',
            'owner' => 'NetOps',
            'valid' => '312',
            'blocked' => '40',
            'next' => 'Resolve duplicate optic serials',
        ],
    ];

    /**
     * @var array<string, array{
     *     po: string,
     *     vendor: string,
     *     expected: string,
     *     exception: string,
     *     owner: string,
     *     received: string,
     *     labels: string,
     *     next: string
     * }>
     */
    private const array RECEIVING_BATCHES = [
        'po-10482' => [
            'po' => 'PO-10482',
            'vendor' => 'Juniper',
            'expected' => '18 routers',
            'exception' => '2 duplicate serials',
            'owner' => 'Supply',
            'received' => '16',
            'labels' => '16 queued',
            'next' => 'Resolve duplicate serials before commit',
        ],
        'po-10491' => [
            'po' => 'PO-10491',
            'vendor' => 'FS',
            'expected' => '400 optics',
            'exception' => 'Awaiting count',
            'owner' => 'Warehouse',
            'received' => '0',
            'labels' => 'Pending count',
            'next' => 'Complete physical count',
        ],
        'po-10502' => [
            'po' => 'PO-10502',
            'vendor' => 'APC',
            'expected' => '12 UPS units',
            'exception' => 'No asset class mapping',
            'owner' => 'Facilities',
            'received' => '12',
            'labels' => 'Blocked',
            'next' => 'Map UPS model to asset type',
        ],
        'po-10511' => [
            'po' => 'PO-10511',
            'vendor' => 'Dell',
            'expected' => '24 servers',
            'exception' => 'Ready to receive',
            'owner' => 'Platform',
            'received' => '24',
            'labels' => '24 queued',
            'next' => 'Commit received assets',
        ],
    ];

    /**
     * @var array<string, array{
     *     number: string,
     *     vendor: string,
     *     coverage: string,
     *     owner: string,
     *     due: string,
     *     value: string,
     *     term: string,
     *     state: string,
     *     gap: string,
     *     next: string
     * }>
     */
    private const array CONTRACT_RENEWALS = [
        'sup-3092' => [
            'number' => 'SUP-3092',
            'vendor' => 'Juniper',
            'coverage' => 'Core routers',
            'owner' => 'NetOps',
            'due' => '18 days',
            'value' => '$482K',
            'term' => 'Annual support',
            'state' => 'Quote review',
            'gap' => '4 routers uncovered',
            'next' => 'Confirm covered serials before renewal approval',
        ],
        'sup-3110' => [
            'number' => 'SUP-3110',
            'vendor' => 'APC',
            'coverage' => 'UPS fleet',
            'owner' => 'Facilities',
            'due' => '31 days',
            'value' => '$91K',
            'term' => '36 month support',
            'state' => 'Owner review',
            'gap' => '9 UPS devices uncovered',
            'next' => 'Validate support level for remote sites',
        ],
        'lic-2018' => [
            'number' => 'LIC-2018',
            'vendor' => 'IPAM',
            'coverage' => 'Address registry',
            'owner' => 'Platform',
            'due' => '44 days',
            'value' => '$64K',
            'term' => 'Subscription',
            'state' => 'Budget hold',
            'gap' => 'No asset gap',
            'next' => 'Attach purchase approval',
        ],
        'lease-884' => [
            'number' => 'LEASE-884',
            'vendor' => 'Dell',
            'coverage' => 'Edge compute',
            'owner' => 'Finance',
            'due' => '58 days',
            'value' => '$212K',
            'term' => 'Lease renewal',
            'state' => 'Return planning',
            'gap' => '5 server nodes uncovered',
            'next' => 'Decide renew, buyout, or return',
        ],
    ];

    /**
     * @var array<string, array{
     *     name: string,
     *     audience: string,
     *     cadence: string,
     *     lastRun: string,
     *     owner: string,
     *     format: string,
     *     filters: string,
     *     schedule: string,
     *     next: string
     * }>
     */
    private const array SAVED_REPORTS = [
        'asset-audit-exceptions' => [
            'name' => 'Asset audit exceptions',
            'audience' => 'Operations',
            'cadence' => 'Daily',
            'lastRun' => '07:00',
            'owner' => 'Operations',
            'format' => 'CSV and PDF',
            'filters' => 'Missing owner, serial, site, or lifecycle evidence',
            'schedule' => 'Weekdays at 07:00',
            'next' => 'Review 57 compliance gaps before export approval',
        ],
        'contract-renewal-risk' => [
            'name' => 'Contract renewal risk',
            'audience' => 'Finance',
            'cadence' => 'Weekly',
            'lastRun' => 'Monday',
            'owner' => 'Finance',
            'format' => 'PDF summary',
            'filters' => 'Renewals inside 60 days and uncovered critical assets',
            'schedule' => 'Mondays at 08:30',
            'next' => 'Send renewal exceptions to contract owners',
        ],
        'monitoring-coverage' => [
            'name' => 'Monitoring coverage',
            'audience' => 'NetOps',
            'cadence' => 'Daily',
            'lastRun' => '06:30',
            'owner' => 'NetOps',
            'format' => 'CSV',
            'filters' => 'Active assets without Cacti links and polling exceptions',
            'schedule' => 'Daily at 06:30',
            'next' => 'Reconcile unmatched Cacti hosts',
        ],
        'warehouse-cycle-count' => [
            'name' => 'Warehouse cycle count',
            'audience' => 'Supply',
            'cadence' => 'Monthly',
            'lastRun' => 'Jul 28',
            'owner' => 'Supply',
            'format' => 'CSV',
            'filters' => 'Storage locations, spare pools, and count variance',
            'schedule' => 'Last weekday of each month',
            'next' => 'Approve count variance report',
        ],
    ];

    /**
     * @var array<string, array{
     *     number: string,
     *     asset: string,
     *     window: string,
     *     owner: string,
     *     state: string,
     *     type: string,
     *     risk: string,
     *     spare: string,
     *     next: string
     * }>
     */
    private const array MAINTENANCE_WORK = [
        'mw-2041' => [
            'number' => 'MW-2041',
            'asset' => 'core-atl-01',
            'window' => 'Tonight 23:00',
            'owner' => 'NetOps',
            'state' => 'Ready',
            'type' => 'Maintenance window',
            'risk' => 'Core routing redundancy verified',
            'spare' => 'Linecard reserved',
            'next' => 'Confirm change approval before dispatch',
        ],
        'rma-8841' => [
            'number' => 'RMA-8841',
            'asset' => 'linecard-sjc-04',
            'window' => 'Vendor ship',
            'owner' => 'Supply',
            'state' => 'Waiting',
            'type' => 'Vendor RMA',
            'risk' => 'Replacement ETA not confirmed',
            'spare' => 'Temporary spare installed',
            'next' => 'Update vendor shipment evidence',
        ],
        'mw-2047' => [
            'number' => 'MW-2047',
            'asset' => 'UPS-DAL-02',
            'window' => 'Saturday 02:00',
            'owner' => 'Facilities',
            'state' => 'Review',
            'type' => 'Power maintenance',
            'risk' => 'Customer aggregation room impact',
            'spare' => 'Bypass plan required',
            'next' => 'Attach reviewed power plan',
        ],
        'ret-4412' => [
            'number' => 'RET-4412',
            'asset' => 'CPE batch 2021',
            'window' => 'Queued',
            'owner' => 'Field Ops',
            'state' => 'Dispose',
            'type' => 'Retirement disposition',
            'risk' => 'Data wipe evidence required',
            'spare' => 'No spare required',
            'next' => 'Record disposal certificate',
        ],
    ];

    /**
     * @var array<string, array{
     *     area: string,
     *     setting: string,
     *     state: string,
     *     owner: string,
     *     scope: string,
     *     risk: string,
     *     evidence: string,
     *     next: string
     * }>
     */
    private const array ADMIN_CONFIGURATIONS = [
        'rbac-ldap-group-role-map' => [
            'area' => 'RBAC',
            'setting' => 'LDAP group role map',
            'state' => 'Configured',
            'owner' => 'Platform',
            'scope' => 'Viewer, operator, manager, and admin groups',
            'risk' => 'Privileged group drift',
            'evidence' => 'LDAP bind and local fallback tests',
            'next' => 'Review privileged mappings before production enablement',
        ],
        'lifecycle-retirement-approvals' => [
            'area' => 'Lifecycle',
            'setting' => 'Retirement approvals',
            'state' => 'Draft',
            'owner' => 'Operations',
            'scope' => 'Retirement, disposal, data wipe, and audit evidence',
            'risk' => 'Assets retired without required approval',
            'evidence' => 'Draft approval matrix',
            'next' => 'Approve retirement workflow owners',
        ],
        'fields-optic-serial-required' => [
            'area' => 'Fields',
            'setting' => 'Optic serial required',
            'state' => 'Enabled',
            'owner' => 'NetOps',
            'scope' => 'Optics, transceivers, and pluggable modules',
            'risk' => 'Duplicate or missing serial identity',
            'evidence' => 'Required field policy',
            'next' => 'Audit existing optic records',
        ],
        'integrations-cacti-host-sync' => [
            'area' => 'Integrations',
            'setting' => 'Cacti host sync',
            'state' => 'Planned',
            'owner' => 'Plugin',
            'scope' => 'Cacti hosts, graph trees, polling state, and asset links',
            'risk' => 'Monitoring state drift',
            'evidence' => 'Integration design checklist',
            'next' => 'Validate plugin sync contract',
        ],
    ];

    /**
     * @var array<string, array{
     *     device: string,
     *     interface: string,
     *     peer: string,
     *     signal: string,
     *     speed: string,
     *     media: string,
     *     circuit: string,
     *     owner: string,
     *     next: string
     * }>
     */
    private const array NETWORK_SIGNALS = [
        'core-atl-01-et-0-0-3' => [
            'device' => 'core-atl-01',
            'interface' => 'et-0/0/3',
            'peer' => 'agg-atl-04',
            'signal' => 'Peer asset missing',
            'speed' => '100G',
            'media' => 'LR4 optic',
            'circuit' => 'Internal backbone',
            'owner' => 'NetOps',
            'next' => 'Create or link peer asset before topology approval',
        ],
        'agg-den-03-xe-1-1-0' => [
            'device' => 'agg-den-03',
            'interface' => 'xe-1/1/0',
            'peer' => 'carrier handoff',
            'signal' => 'Circuit pending',
            'speed' => '10G',
            'media' => 'Carrier NNI',
            'circuit' => 'Zayo DEN-1842',
            'owner' => 'Regional Ops',
            'next' => 'Confirm carrier turn-up date',
        ],
        'edge-sjc-02-et-0-0-7' => [
            'device' => 'edge-sjc-02',
            'interface' => 'et-0/0/7',
            'peer' => 'core-sjc-01',
            'signal' => 'Optic serial mismatch',
            'speed' => '100G',
            'media' => 'SR4 optic',
            'circuit' => 'SJC fabric',
            'owner' => 'NetOps',
            'next' => 'Validate optic serial against physical audit',
        ],
        'cpe-rno-144-ge-0-0-0' => [
            'device' => 'cpe-rno-144',
            'interface' => 'ge-0/0/0',
            'peer' => 'customer access',
            'signal' => 'No Cacti graph',
            'speed' => '1G',
            'media' => 'Copper',
            'circuit' => 'Customer access',
            'owner' => 'Field Ops',
            'next' => 'Attach Cacti graph template after install validation',
        ],
    ];

    /**
     * @var array<string, array{
     *     priority: string,
     *     item: string,
     *     owner: string,
     *     due: string,
     *     signal: string,
     *     impact: string,
     *     source: string,
     *     next: string
     * }>
     */
    private const array ATTENTION_ITEMS = [
        'core-router-serial-mismatch-sjc1' => [
            'priority' => 'High',
            'item' => 'Core router serial mismatch at SJC1',
            'owner' => 'NetOps',
            'due' => 'Today',
            'signal' => 'Serial mismatch',
            'impact' => 'Support coverage and monitoring identity may diverge',
            'source' => 'Asset audit and Cacti reconciliation',
            'next' => 'Confirm physical serial and update the asset record',
        ],
        'ups-warranty-dal-aggregation' => [
            'priority' => 'High',
            'item' => 'UPS warranty expires for DAL aggregation room',
            'owner' => 'Facilities',
            'due' => '3 days',
            'signal' => 'Expiring coverage',
            'impact' => 'Power support exposure for customer aggregation',
            'source' => 'Contract renewal scan',
            'next' => 'Attach renewal quote and owner approval',
        ],
        'cpe-missing-custody' => [
            'priority' => 'Medium',
            'item' => 'Nine CPE devices missing customer custody',
            'owner' => 'Field Ops',
            'due' => 'This week',
            'signal' => 'Custody gap',
            'impact' => 'Installed assets lack customer accountability',
            'source' => 'Custody assignment audit',
            'next' => 'Assign customer or field owner custody',
        ],
        'po-10482-duplicate-serials' => [
            'priority' => 'Medium',
            'item' => 'Receiving batch PO-10482 has two duplicate serials',
            'owner' => 'Supply',
            'due' => 'This week',
            'signal' => 'Duplicate serials',
            'impact' => 'Received assets cannot be committed safely',
            'source' => 'Procurement receiving validation',
            'next' => 'Resolve duplicate serials before commit',
        ],
    ];

    public function __construct(
        private readonly RegisterAssetHandler $registerAsset,
        private readonly AssetRepository $assetRepository,
        private readonly ?UserDirectory $userDirectory,
        private readonly AccessPolicy $accessPolicy = new AccessPolicy(),
    ) {}

    public static function fromStore(string $path, ?string $basePath, ?string $writeAuth = null): self
    {
        $repository = new JsonAssetRepository($path, $basePath);

        return new self(
            new RegisterAssetHandler($repository),
            $repository,
            LocalUserDirectory::fromLegacyWriteAuth($writeAuth),
        );
    }

    public function withUserDirectory(?UserDirectory $userDirectory): self
    {
        return new self($this->registerAsset, $this->assetRepository, $userDirectory, $this->accessPolicy);
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
            Request::METHOD_GET => $this->renderGet($path, $request),
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

    private function renderGet(string $path, Request $request): Response
    {
        if (
            (
                $path !== '/assets'
                && $path !== '/locations'
                && $path !== '/custody'
                && $path !== '/monitoring'
                && $path !== '/imports'
                && $path !== '/procurement'
                && $path !== '/contracts'
                && $path !== '/reports'
                && $path !== '/maintenance'
                && $path !== '/admin'
                && $path !== '/network'
                && $path !== '/'
            )
            || !$request->query->has('id')
        ) {
            return $this->render($path);
        }

        $id = $this->stringQueryId($request);

        if ($id === null) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }

        if ($path === '/locations') {
            return $this->render($path, locationId: $id);
        }

        if ($path === '/custody') {
            return $this->render($path, custodyTransferId: $id);
        }

        if ($path === '/monitoring') {
            return $this->render($path, monitoringExceptionId: $id);
        }

        if ($path === '/imports') {
            return $this->render($path, importBatchId: $id);
        }

        if ($path === '/procurement') {
            return $this->render($path, receivingBatchId: $id);
        }

        if ($path === '/contracts') {
            return $this->render($path, contractRenewalId: $id);
        }

        if ($path === '/reports') {
            return $this->render($path, savedReportId: $id);
        }

        if ($path === '/maintenance') {
            return $this->render($path, maintenanceWorkId: $id);
        }

        if ($path === '/admin') {
            return $this->render($path, adminConfigurationId: $id);
        }

        if ($path === '/network') {
            return $this->render($path, networkSignalId: $id);
        }

        if ($path === '/') {
            return $this->render($path, attentionItemId: $id);
        }

        try {
            return $this->render($path, detailAssetId: AssetId::fromString($id));
        } catch (InvalidArgumentException) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }
    }

    private function stringQueryId(Request $request): ?string
    {
        try {
            $id = $request->query->get('id');
        } catch (BadRequestException) {
            return null;
        }

        return is_string($id) ? $id : null;
    }

    private function render(
        string $path,
        ?string $success = null,
        ?string $error = null,
        int $status = Response::HTTP_OK,
        ?AssetId $detailAssetId = null,
        ?string $locationId = null,
        ?string $custodyTransferId = null,
        ?string $monitoringExceptionId = null,
        ?string $importBatchId = null,
        ?string $receivingBatchId = null,
        ?string $contractRenewalId = null,
        ?string $savedReportId = null,
        ?string $maintenanceWorkId = null,
        ?string $adminConfigurationId = null,
        ?string $networkSignalId = null,
        ?string $attentionItemId = null,
    ): Response {
        $screen = self::SCREENS[$path];
        $detailAsset = $detailAssetId === null ? null : $this->assetRepository->get($detailAssetId);
        $location = $locationId === null ? null : (self::LOCATIONS[$locationId] ?? null);
        $custodyTransfer = $custodyTransferId === null ? null : (self::CUSTODY_TRANSFERS[$custodyTransferId] ?? null);
        $monitoringException = $monitoringExceptionId === null ? null : (self::MONITORING_EXCEPTIONS[$monitoringExceptionId] ?? null);
        $importBatch = $importBatchId === null ? null : (self::IMPORT_BATCHES[$importBatchId] ?? null);
        $receivingBatch = $receivingBatchId === null ? null : (self::RECEIVING_BATCHES[$receivingBatchId] ?? null);
        $contractRenewal = $contractRenewalId === null ? null : (self::CONTRACT_RENEWALS[$contractRenewalId] ?? null);
        $savedReport = $savedReportId === null ? null : (self::SAVED_REPORTS[$savedReportId] ?? null);
        $maintenanceWork = $maintenanceWorkId === null ? null : (self::MAINTENANCE_WORK[$maintenanceWorkId] ?? null);
        $adminConfiguration = $adminConfigurationId === null ? null : (self::ADMIN_CONFIGURATIONS[$adminConfigurationId] ?? null);
        $networkSignal = $networkSignalId === null ? null : (self::NETWORK_SIGNALS[$networkSignalId] ?? null);
        $attentionItem = $attentionItemId === null ? null : (self::ATTENTION_ITEMS[$attentionItemId] ?? null);

        if ($detailAssetId !== null && $detailAsset === null) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }

        if ($locationId !== null && $location === null) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }

        if ($custodyTransferId !== null && $custodyTransfer === null) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }

        if ($monitoringExceptionId !== null && $monitoringException === null) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }

        if ($importBatchId !== null && $importBatch === null) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }

        if ($receivingBatchId !== null && $receivingBatch === null) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }

        if ($contractRenewalId !== null && $contractRenewal === null) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }

        if ($savedReportId !== null && $savedReport === null) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }

        if ($maintenanceWorkId !== null && $maintenanceWork === null) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }

        if ($adminConfigurationId !== null && $adminConfiguration === null) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }

        if ($networkSignalId !== null && $networkSignal === null) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }

        if ($attentionItemId !== null && $attentionItem === null) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }

        $detailStatus = null;

        if ($detailAsset !== null) {
            $detailStatus = $this->assetStatusLabel($detailAsset->status);
            $screen = [
                'label' => 'Assets',
                'section' => 'Inventory',
                'title' => $detailAsset->name->value,
                'summary' => sprintf('Asset record %s in %s.', $detailAsset->id->value, strtolower($this->assetStatusLabel($detailAsset->status))),
                'items' => [],
            ];
        }

        if ($location !== null) {
            $detailStatus = $location['work'];
            $screen = [
                'label' => 'Locations',
                'section' => 'Facilities',
                'title' => $location['name'],
                'summary' => sprintf('%s location with %s occupancy and open work: %s.', $location['type'], strtolower($location['occupancy']), $location['work']),
                'items' => [],
            ];
        }

        if ($custodyTransfer !== null) {
            $detailStatus = $custodyTransfer['state'];
            $screen = [
                'label' => 'Custody',
                'section' => 'People',
                'title' => $custodyTransfer['number'],
                'summary' => sprintf('Transfer %s from %s to %s is %s.', $custodyTransfer['number'], $custodyTransfer['from'], $custodyTransfer['to'], strtolower($custodyTransfer['state'])),
                'items' => [],
            ];
        }

        if ($monitoringException !== null) {
            $detailStatus = $monitoringException['severity'];
            $screen = [
                'label' => 'Monitoring',
                'section' => 'Cacti',
                'title' => $monitoringException['signal'],
                'summary' => sprintf('%s on Cacti host %s for asset %s.', $monitoringException['signal'], $monitoringException['host'], $monitoringException['asset']),
                'items' => [],
            ];
        }

        if ($importBatch !== null) {
            $detailStatus = $importBatch['state'];
            $screen = [
                'label' => 'Imports',
                'section' => 'Inventory',
                'title' => $importBatch['number'],
                'summary' => sprintf('%s import with %s rows is %s.', $importBatch['source'], $importBatch['rows'], strtolower($importBatch['state'])),
                'items' => [],
            ];
        }

        if ($receivingBatch !== null) {
            $detailStatus = $receivingBatch['exception'];
            $screen = [
                'label' => 'Procurement',
                'section' => 'Supply',
                'title' => $receivingBatch['po'],
                'summary' => sprintf('%s receiving from %s expects %s.', $receivingBatch['po'], $receivingBatch['vendor'], $receivingBatch['expected']),
                'items' => [],
            ];
        }

        if ($contractRenewal !== null) {
            $detailStatus = $contractRenewal['state'];
            $screen = [
                'label' => 'Contracts',
                'section' => 'Commercial',
                'title' => $contractRenewal['number'],
                'summary' => sprintf('%s renewal with %s covers %s and is due in %s.', $contractRenewal['number'], $contractRenewal['vendor'], $contractRenewal['coverage'], $contractRenewal['due']),
                'items' => [],
            ];
        }

        if ($savedReport !== null) {
            $detailStatus = $savedReport['cadence'];
            $screen = [
                'label' => 'Reports',
                'section' => 'Insights',
                'title' => $savedReport['name'],
                'summary' => sprintf('%s report for %s runs %s and last completed at %s.', $savedReport['name'], $savedReport['audience'], strtolower($savedReport['cadence']), $savedReport['lastRun']),
                'items' => [],
            ];
        }

        if ($maintenanceWork !== null) {
            $detailStatus = $maintenanceWork['state'];
            $screen = [
                'label' => 'Maintenance',
                'section' => 'Operations',
                'title' => $maintenanceWork['number'],
                'summary' => sprintf('%s for %s is %s with window %s.', $maintenanceWork['type'], $maintenanceWork['asset'], strtolower($maintenanceWork['state']), $maintenanceWork['window']),
                'items' => [],
            ];
        }

        if ($adminConfiguration !== null) {
            $detailStatus = $adminConfiguration['state'];
            $screen = [
                'label' => 'Admin',
                'section' => 'Configuration',
                'title' => $adminConfiguration['setting'],
                'summary' => sprintf('%s configuration is %s and owned by %s.', $adminConfiguration['area'], strtolower($adminConfiguration['state']), $adminConfiguration['owner']),
                'items' => [],
            ];
        }

        if ($networkSignal !== null) {
            $detailStatus = $networkSignal['signal'];
            $screen = [
                'label' => 'Network',
                'section' => 'Infrastructure',
                'title' => $networkSignal['device'],
                'summary' => sprintf('%s on %s peers with %s and needs %s.', $networkSignal['interface'], $networkSignal['device'], $networkSignal['peer'], strtolower($networkSignal['signal'])),
                'items' => [],
            ];
        }

        if ($attentionItem !== null) {
            $detailStatus = $attentionItem['priority'];
            $screen = [
                'label' => 'Dashboard',
                'section' => 'Operations',
                'title' => $attentionItem['item'],
                'summary' => sprintf('%s priority item owned by %s is due %s.', $attentionItem['priority'], $attentionItem['owner'], strtolower($attentionItem['due'])),
                'items' => [],
            ];
        }

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
        $isDetailScreen = $detailAsset !== null
            || $location !== null
            || $custodyTransfer !== null
            || $monitoringException !== null
            || $importBatch !== null
            || $receivingBatch !== null
            || $contractRenewal !== null
            || $savedReport !== null
            || $maintenanceWork !== null
            || $adminConfiguration !== null
            || $networkSignal !== null
            || $attentionItem !== null;
        $backLinkHtml = $isDetailScreen
            ? sprintf(
                '<a class="back-link" href="%s">Back to %s</a>',
                $this->escape($path),
                $this->escape($screen['label']),
            )
            : '';
        $browserTitleParts = [$screen['title']];

        if ($isDetailScreen) {
            $browserTitleParts[] = $screen['label'];
        }

        $browserTitleParts[] = 'InfraRegister';
        $browserTitle = implode(' - ', $browserTitleParts);
        $metadata = $detailStatus === null
            ? $screen['section']
            : sprintf('%s / %s', $screen['section'], $detailStatus);
        $breadcrumbs = $this->renderBreadcrumbs($path, $screen, $isDetailScreen);
        $content = match (true) {
            $detailAsset !== null => $this->renderAssetDetailContent($detailAsset),
            $location !== null => $this->renderLocationDetailContent($location),
            $custodyTransfer !== null => $this->renderCustodyTransferDetailContent($custodyTransfer),
            $monitoringException !== null => $this->renderMonitoringExceptionDetailContent($monitoringException),
            $importBatch !== null => $this->renderImportBatchDetailContent($importBatch),
            $receivingBatch !== null => $this->renderReceivingBatchDetailContent($receivingBatch),
            $contractRenewal !== null => $this->renderContractRenewalDetailContent($contractRenewal),
            $savedReport !== null => $this->renderSavedReportDetailContent($savedReport),
            $maintenanceWork !== null => $this->renderMaintenanceWorkDetailContent($maintenanceWork),
            $adminConfiguration !== null => $this->renderAdminConfigurationDetailContent($adminConfiguration),
            $networkSignal !== null => $this->renderNetworkSignalDetailContent($networkSignal),
            $attentionItem !== null => $this->renderAttentionItemDetailContent($attentionItem),
            $path === '/assets/register' => $this->renderRegistrationContent($screen, $successHtml, $errorHtml, $inputDescription),
            default => $this->renderScreenContent($path, $screen),
        };

        return new Response(<<<HTML
            <!doctype html>
            <html lang="en">
            <head>
              <meta charset="utf-8">
              <meta name="viewport" content="width=device-width, initial-scale=1">
              <title>{$this->escape($browserTitle)}</title>
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

                .global-search {
                  display: flex;
                  align-items: center;
                  gap: 8px;
                  width: min(460px, 100%);
                  margin-left: auto;
                }

                .global-search input {
                  min-height: 40px;
                }

                .global-search button {
                  min-height: 40px;
                  padding: 0 12px;
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

                .nav-section-list,
                .nav-list {
                  display: grid;
                  gap: 4px;
                  margin: 0;
                  padding: 0;
                  list-style: none;
                }

                .nav-section-list {
                  gap: 14px;
                }

                .nav-section-title {
                  margin: 0 0 5px;
                  padding: 0 10px;
                  color: var(--muted);
                  font-size: 12px;
                  font-weight: 800;
                  text-transform: uppercase;
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

                .breadcrumbs {
                  margin: 0 0 12px;
                  color: var(--muted);
                  font-size: 13px;
                  font-weight: 650;
                }

                .breadcrumbs ol {
                  display: flex;
                  flex-wrap: wrap;
                  gap: 6px;
                  margin: 0;
                  padding: 0;
                  list-style: none;
                }

                .breadcrumbs li:not(:last-child)::after {
                  content: "/";
                  margin-left: 6px;
                  color: var(--line-strong);
                }

                .breadcrumbs a {
                  color: var(--accent-strong);
                  text-decoration: none;
                }

                .breadcrumbs a:hover {
                  text-decoration: underline;
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

                .back-link {
                  display: inline-flex;
                  align-items: center;
                  min-height: 36px;
                  margin: 0 0 18px;
                  color: var(--accent-strong);
                  font-weight: 700;
                  text-decoration: none;
                }

                .back-link:hover {
                  text-decoration: underline;
                }

                .workspace {
                  display: grid;
                  grid-template-columns: minmax(0, 1fr);
                  gap: 18px;
                  align-items: start;
                }

                .workspace-grid {
                  display: grid;
                  grid-template-columns: minmax(0, 1fr) 280px;
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

                .toolbar {
                  display: flex;
                  flex-wrap: wrap;
                  gap: 8px;
                }

                .secondary-action {
                  display: inline-flex;
                  align-items: center;
                  justify-content: center;
                  min-height: 34px;
                  padding: 0 10px;
                  border: 1px solid var(--line-strong);
                  border-radius: 6px;
                  background: #ffffff;
                  color: var(--text);
                  font-size: 14px;
                  font-weight: 700;
                }

                .metric-grid {
                  display: grid;
                  grid-template-columns: repeat(4, minmax(0, 1fr));
                  gap: 12px;
                  margin-bottom: 18px;
                }

                .metric {
                  min-height: 118px;
                  padding: 14px;
                  border: 1px solid var(--line);
                  border-radius: 8px;
                  background: var(--surface);
                }

                .metric-label {
                  margin: 0 0 8px;
                  color: var(--muted);
                  font-size: 13px;
                  font-weight: 750;
                  text-transform: uppercase;
                }

                .metric-value {
                  margin: 0;
                  font-size: 26px;
                  font-weight: 800;
                  line-height: 1.1;
                }

                .metric-detail {
                  margin: 8px 0 0;
                  color: var(--muted);
                  font-size: 13px;
                }

                .data-table-wrap {
                  overflow-x: auto;
                }

                .data-table {
                  width: 100%;
                  min-width: 720px;
                  border-collapse: collapse;
                }

                th,
                td {
                  padding: 11px 14px;
                  border-bottom: 1px solid var(--line);
                  text-align: left;
                  vertical-align: top;
                }

                th {
                  color: var(--muted);
                  font-size: 13px;
                  font-weight: 800;
                  text-transform: uppercase;
                }

                td {
                  font-size: 14px;
                }

                tbody tr:last-child td {
                  border-bottom: 0;
                }

                .side-list {
                  display: grid;
                  gap: 0;
                  margin: 0;
                  padding: 6px 0;
                  list-style: none;
                }

                .side-list li {
                  display: grid;
                  gap: 3px;
                  padding: 12px 16px;
                  border-bottom: 1px solid var(--line);
                }

                .side-list li:last-child {
                  border-bottom: 0;
                }

                .side-list a {
                  display: grid;
                  gap: 3px;
                  margin: -12px -16px;
                  padding: 12px 16px;
                  border-radius: 6px;
                  color: inherit;
                  text-decoration: none;
                }

                .side-list a:hover {
                  background: #f4f8fb;
                }

                .side-list a[aria-current="location"] .side-label {
                  color: var(--accent-strong);
                }

                .side-label {
                  color: var(--muted);
                  font-size: 13px;
                  font-weight: 750;
                }

                .side-value {
                  font-size: 14px;
                  font-weight: 650;
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
                    flex-wrap: wrap;
                    min-height: 58px;
                    padding: 0 16px;
                  }

                  .global-search {
                    order: 3;
                    width: 100%;
                    margin-left: 0;
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

                  .nav-section-list {
                    grid-auto-flow: column;
                    grid-auto-columns: max-content;
                    align-items: start;
                  }

                  .nav-section-title {
                    white-space: nowrap;
                  }

                  .nav-list {
                    grid-auto-flow: row;
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

                  .workspace-grid,
                  .metric-grid {
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
                  .global-search { display: none; }
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
                  <form class="global-search" role="search" method="get" action="/search">
                    <input type="search" name="q" aria-label="Global search" placeholder="Search assets, hosts, IPs">
                    <button type="submit">Search</button>
                  </form>
                  <a class="button-link" href="/assets/register">Register Asset</a>
                </div>
              </header>
              <div class="app-layout">
                <nav class="sidebar" aria-label="Primary navigation">
                  {$navigation}
                </nav>
                <main id="content" tabindex="-1">
                  {$breadcrumbs}
                  <div class="page-title">
                    <h1>{$this->escape($screen['title'])}</h1>
                    <span class="metadata">{$this->escape($metadata)}</span>
                  </div>
                  <p class="summary">{$this->escape($screen['summary'])}</p>
                  {$backLinkHtml}
                  {$content}
                </main>
              </div>
            </body>
            </html>
            HTML, $status);
    }

    /**
     * @param array{priority: string, item: string, owner: string, due: string, signal: string, impact: string, source: string, next: string} $item
     */
    private function renderAttentionItemDetailContent(array $item): string
    {
        $summary = $this->renderTable(
            ['Field', 'Value'],
            [
                ['Priority', $item['priority']],
                ['Item', $item['item']],
                ['Owner', $item['owner']],
                ['Due', $item['due']],
                ['Signal', $item['signal']],
                ['Impact', $item['impact']],
                ['Source', $item['source']],
                ['Next Step', $item['next']],
            ],
        );
        $metrics = $this->renderMetrics([
            ['label' => 'Priority', 'value' => $item['priority'], 'detail' => 'Operational urgency'],
            ['label' => 'Due', 'value' => $item['due'], 'detail' => 'Target resolution'],
            ['label' => 'Owner', 'value' => $item['owner'], 'detail' => 'Responsible team'],
            ['label' => 'Signal', 'value' => $item['signal'], 'detail' => 'Queue source'],
        ]);
        $tabs = $this->renderSideItems([
            ['label' => 'Summary', 'value' => 'Priority, owner, and due date', 'href' => '#attention-summary-title', 'current' => true],
            ['label' => 'Evidence', 'value' => 'Source checks and audit signal'],
            ['label' => 'Links', 'value' => 'Related asset, contract, custody, or import'],
            ['label' => 'Workflow', 'value' => 'Next step and close criteria'],
            ['label' => 'History', 'value' => 'Attention queue changes'],
        ]);
        $actions = $this->renderActions(['Assign Owner', 'Open Related Record', 'Attach Evidence', 'Resolve Item']);

        return <<<HTML
            {$metrics}
            <div class="workspace-grid">
              <div class="workspace">
                <section class="panel" aria-labelledby="attention-summary-title">
                  <div class="panel-header">
                    <h2 id="attention-summary-title">Summary</h2>
                    <div class="toolbar" aria-label="Attention item actions">
                      {$actions}
                    </div>
                  </div>
                  {$summary}
                </section>
                <section class="panel" aria-labelledby="attention-work-title">
                  <div class="panel-header">
                    <h2 id="attention-work-title">Resolution Work</h2>
                  </div>
                  <ul class="side-list">
                    <li><span class="side-label">Triage</span><span class="side-value">Confirm source, owner, impact, and due date before acting.</span></li>
                    <li><span class="side-label">Record</span><span class="side-value">Open the related asset, contract, custody, import, or topology record.</span></li>
                    <li><span class="side-label">Closure</span><span class="side-value">Attach evidence and resolve only after the underlying record is corrected.</span></li>
                  </ul>
                </section>
              </div>
              <aside class="panel" aria-labelledby="attention-tabs-title">
                <div class="panel-header">
                  <h2 id="attention-tabs-title">Attention Tabs</h2>
                </div>
                {$tabs}
              </aside>
            </div>
            HTML;
    }

    /**
     * @param array{device: string, interface: string, peer: string, signal: string, speed: string, media: string, circuit: string, owner: string, next: string} $signal
     */
    private function renderNetworkSignalDetailContent(array $signal): string
    {
        $summary = $this->renderTable(
            ['Field', 'Value'],
            [
                ['Device', $signal['device']],
                ['Interface', $signal['interface']],
                ['Peer', $signal['peer']],
                ['Signal', $signal['signal']],
                ['Speed', $signal['speed']],
                ['Media', $signal['media']],
                ['Circuit', $signal['circuit']],
                ['Owner', $signal['owner']],
                ['Next Step', $signal['next']],
            ],
        );
        $metrics = $this->renderMetrics([
            ['label' => 'Signal', 'value' => $signal['signal'], 'detail' => 'Topology issue'],
            ['label' => 'Speed', 'value' => $signal['speed'], 'detail' => 'Interface speed'],
            ['label' => 'Media', 'value' => $signal['media'], 'detail' => 'Physical layer'],
            ['label' => 'Owner', 'value' => $signal['owner'], 'detail' => 'Resolver'],
        ]);
        $tabs = $this->renderSideItems([
            ['label' => 'Summary', 'value' => 'Device, interface, and peer', 'href' => '#network-summary-title', 'current' => true],
            ['label' => 'Layer 1', 'value' => 'Speed, media, optic, and circuit'],
            ['label' => 'IPAM', 'value' => 'Prefix, VRF, and assignment context'],
            ['label' => 'Monitoring', 'value' => 'Cacti host and graph links'],
            ['label' => 'Audit', 'value' => 'Topology evidence history'],
        ]);
        $actions = $this->renderActions(['Reconcile Peers', 'Link Asset', 'Reserve Prefix', 'Attach Graph']);

        return <<<HTML
            {$metrics}
            <div class="workspace-grid">
              <div class="workspace">
                <section class="panel" aria-labelledby="network-summary-title">
                  <div class="panel-header">
                    <h2 id="network-summary-title">Summary</h2>
                    <div class="toolbar" aria-label="Network actions">
                      {$actions}
                    </div>
                  </div>
                  {$summary}
                </section>
                <section class="panel" aria-labelledby="network-work-title">
                  <div class="panel-header">
                    <h2 id="network-work-title">Topology Work</h2>
                  </div>
                  <ul class="side-list">
                    <li><span class="side-label">Identity</span><span class="side-value">Reconcile device, interface, peer asset, circuit, and physical media.</span></li>
                    <li><span class="side-label">IPAM</span><span class="side-value">Keep prefixes, VRFs, reservations, and assignments attached to the owning asset.</span></li>
                    <li><span class="side-label">Monitoring</span><span class="side-value">Link Cacti host and graph evidence after topology validation.</span></li>
                  </ul>
                </section>
              </div>
              <aside class="panel" aria-labelledby="network-tabs-title">
                <div class="panel-header">
                  <h2 id="network-tabs-title">Network Tabs</h2>
                </div>
                {$tabs}
              </aside>
            </div>
            HTML;
    }

    /**
     * @param array{area: string, setting: string, state: string, owner: string, scope: string, risk: string, evidence: string, next: string} $configuration
     */
    private function renderAdminConfigurationDetailContent(array $configuration): string
    {
        $summary = $this->renderTable(
            ['Field', 'Value'],
            [
                ['Area', $configuration['area']],
                ['Setting', $configuration['setting']],
                ['State', $configuration['state']],
                ['Owner', $configuration['owner']],
                ['Scope', $configuration['scope']],
                ['Risk', $configuration['risk']],
                ['Evidence', $configuration['evidence']],
                ['Next Step', $configuration['next']],
            ],
        );
        $metrics = $this->renderMetrics([
            ['label' => 'Area', 'value' => $configuration['area'], 'detail' => 'Configuration area'],
            ['label' => 'State', 'value' => $configuration['state'], 'detail' => 'Readiness'],
            ['label' => 'Owner', 'value' => $configuration['owner'], 'detail' => 'Accountable team'],
            ['label' => 'Risk', 'value' => $configuration['risk'], 'detail' => 'Review focus'],
        ]);
        $tabs = $this->renderSideItems([
            ['label' => 'Summary', 'value' => 'Setting scope and readiness', 'href' => '#admin-summary-title', 'current' => true],
            ['label' => 'Policy', 'value' => 'Controls, defaults, and exceptions'],
            ['label' => 'Access', 'value' => 'RBAC and LDAP impact'],
            ['label' => 'Evidence', 'value' => 'Validation and audit records'],
            ['label' => 'History', 'value' => 'Configuration change timeline'],
        ]);
        $actions = $this->renderActions(['Review Setting', 'Validate Policy', 'Attach Evidence', 'Open Change']);

        return <<<HTML
            {$metrics}
            <div class="workspace-grid">
              <div class="workspace">
                <section class="panel" aria-labelledby="admin-summary-title">
                  <div class="panel-header">
                    <h2 id="admin-summary-title">Summary</h2>
                    <div class="toolbar" aria-label="Configuration actions">
                      {$actions}
                    </div>
                  </div>
                  {$summary}
                </section>
                <section class="panel" aria-labelledby="admin-work-title">
                  <div class="panel-header">
                    <h2 id="admin-work-title">Configuration Work</h2>
                  </div>
                  <ul class="side-list">
                    <li><span class="side-label">Control</span><span class="side-value">Keep policy, permissions, and required fields explicit before enabling writes.</span></li>
                    <li><span class="side-label">Validation</span><span class="side-value">Record tests, review evidence, and drift checks for production configuration.</span></li>
                    <li><span class="side-label">Change</span><span class="side-value">Track owners, risk, approval, and rollback notes for each setting.</span></li>
                  </ul>
                </section>
              </div>
              <aside class="panel" aria-labelledby="admin-tabs-title">
                <div class="panel-header">
                  <h2 id="admin-tabs-title">Configuration Tabs</h2>
                </div>
                {$tabs}
              </aside>
            </div>
            HTML;
    }

    /**
     * @param array{number: string, asset: string, window: string, owner: string, state: string, type: string, risk: string, spare: string, next: string} $work
     */
    private function renderMaintenanceWorkDetailContent(array $work): string
    {
        $summary = $this->renderTable(
            ['Field', 'Value'],
            [
                ['Work', $work['number']],
                ['Asset', $work['asset']],
                ['Type', $work['type']],
                ['Window', $work['window']],
                ['Owner', $work['owner']],
                ['State', $work['state']],
                ['Risk', $work['risk']],
                ['Spare', $work['spare']],
                ['Next Step', $work['next']],
            ],
        );
        $metrics = $this->renderMetrics([
            ['label' => 'State', 'value' => $work['state'], 'detail' => 'Work status'],
            ['label' => 'Window', 'value' => $work['window'], 'detail' => 'Planned timing'],
            ['label' => 'Owner', 'value' => $work['owner'], 'detail' => 'Responsible team'],
            ['label' => 'Asset', 'value' => $work['asset'], 'detail' => 'Affected asset'],
        ]);
        $tabs = $this->renderSideItems([
            ['label' => 'Summary', 'value' => 'Work state and affected asset', 'href' => '#maintenance-summary-title', 'current' => true],
            ['label' => 'Plan', 'value' => 'Window, risk, and owner'],
            ['label' => 'Spares', 'value' => 'Reserved parts and pool impact'],
            ['label' => 'Evidence', 'value' => 'Photos, approvals, and vendor records'],
            ['label' => 'Audit', 'value' => 'Lifecycle event history'],
        ]);
        $actions = $this->renderActions(['Schedule Window', 'Reserve Spare', 'Update RMA', 'Close Work']);

        return <<<HTML
            {$metrics}
            <div class="workspace-grid">
              <div class="workspace">
                <section class="panel" aria-labelledby="maintenance-summary-title">
                  <div class="panel-header">
                    <h2 id="maintenance-summary-title">Summary</h2>
                    <div class="toolbar" aria-label="Maintenance actions">
                      {$actions}
                    </div>
                  </div>
                  {$summary}
                </section>
                <section class="panel" aria-labelledby="maintenance-work-title">
                  <div class="panel-header">
                    <h2 id="maintenance-work-title">Maintenance Work</h2>
                  </div>
                  <ul class="side-list">
                    <li><span class="side-label">Impact</span><span class="side-value">Confirm customer, redundancy, and service-risk context before work starts.</span></li>
                    <li><span class="side-label">Spares</span><span class="side-value">Reserve parts, track temporary installs, and update spare-pool thresholds.</span></li>
                    <li><span class="side-label">Evidence</span><span class="side-value">Attach approvals, vendor shipment records, photos, and disposition certificates.</span></li>
                  </ul>
                </section>
              </div>
              <aside class="panel" aria-labelledby="maintenance-tabs-title">
                <div class="panel-header">
                  <h2 id="maintenance-tabs-title">Maintenance Tabs</h2>
                </div>
                {$tabs}
              </aside>
            </div>
            HTML;
    }

    /**
     * @param array{name: string, audience: string, cadence: string, lastRun: string, owner: string, format: string, filters: string, schedule: string, next: string} $report
     */
    private function renderSavedReportDetailContent(array $report): string
    {
        $summary = $this->renderTable(
            ['Field', 'Value'],
            [
                ['Report', $report['name']],
                ['Audience', $report['audience']],
                ['Cadence', $report['cadence']],
                ['Last Run', $report['lastRun']],
                ['Owner', $report['owner']],
                ['Format', $report['format']],
                ['Filters', $report['filters']],
                ['Schedule', $report['schedule']],
                ['Next Step', $report['next']],
            ],
        );
        $metrics = $this->renderMetrics([
            ['label' => 'Cadence', 'value' => $report['cadence'], 'detail' => 'Run frequency'],
            ['label' => 'Last Run', 'value' => $report['lastRun'], 'detail' => 'Most recent completion'],
            ['label' => 'Format', 'value' => $report['format'], 'detail' => 'Export output'],
            ['label' => 'Owner', 'value' => $report['owner'], 'detail' => 'Report owner'],
        ]);
        $tabs = $this->renderSideItems([
            ['label' => 'Summary', 'value' => 'Report purpose and audience', 'href' => '#report-summary-title', 'current' => true],
            ['label' => 'Filters', 'value' => 'Saved query constraints'],
            ['label' => 'Schedule', 'value' => 'Cadence and delivery'],
            ['label' => 'Exports', 'value' => 'CSV, PDF, and retention'],
            ['label' => 'Audit', 'value' => 'Run and download history'],
        ]);
        $actions = $this->renderActions(['Run Report', 'Export CSV', 'Schedule Report', 'Edit Filter']);

        return <<<HTML
            {$metrics}
            <div class="workspace-grid">
              <div class="workspace">
                <section class="panel" aria-labelledby="report-summary-title">
                  <div class="panel-header">
                    <h2 id="report-summary-title">Summary</h2>
                    <div class="toolbar" aria-label="Report actions">
                      {$actions}
                    </div>
                  </div>
                  {$summary}
                </section>
                <section class="panel" aria-labelledby="report-work-title">
                  <div class="panel-header">
                    <h2 id="report-work-title">Report Work</h2>
                  </div>
                  <ul class="side-list">
                    <li><span class="side-label">Inputs</span><span class="side-value">Preserve saved filters, audience, cadence, and owner before execution.</span></li>
                    <li><span class="side-label">Outputs</span><span class="side-value">Track CSV and PDF exports with retention, evidence, and approval state.</span></li>
                    <li><span class="side-label">Delivery</span><span class="side-value">Schedule report runs without granting write access to raw asset data.</span></li>
                  </ul>
                </section>
              </div>
              <aside class="panel" aria-labelledby="report-tabs-title">
                <div class="panel-header">
                  <h2 id="report-tabs-title">Report Tabs</h2>
                </div>
                {$tabs}
              </aside>
            </div>
            HTML;
    }

    /**
     * @param array{number: string, vendor: string, coverage: string, owner: string, due: string, value: string, term: string, state: string, gap: string, next: string} $contract
     */
    private function renderContractRenewalDetailContent(array $contract): string
    {
        $summary = $this->renderTable(
            ['Field', 'Value'],
            [
                ['Contract', $contract['number']],
                ['Vendor', $contract['vendor']],
                ['Coverage', $contract['coverage']],
                ['Owner', $contract['owner']],
                ['Due', $contract['due']],
                ['Value', $contract['value']],
                ['Term', $contract['term']],
                ['State', $contract['state']],
                ['Coverage Gap', $contract['gap']],
                ['Next Step', $contract['next']],
            ],
        );
        $metrics = $this->renderMetrics([
            ['label' => 'Due', 'value' => $contract['due'], 'detail' => 'Renewal window'],
            ['label' => 'Value', 'value' => $contract['value'], 'detail' => 'Commercial exposure'],
            ['label' => 'State', 'value' => $contract['state'], 'detail' => 'Renewal status'],
            ['label' => 'Owner', 'value' => $contract['owner'], 'detail' => 'Accountable team'],
        ]);
        $tabs = $this->renderSideItems([
            ['label' => 'Summary', 'value' => 'Commercial state and ownership', 'href' => '#contract-summary-title', 'current' => true],
            ['label' => 'Coverage', 'value' => 'Covered and uncovered assets'],
            ['label' => 'Documents', 'value' => 'Quotes, invoices, and terms'],
            ['label' => 'Approvals', 'value' => 'Budget and renewal decisions'],
            ['label' => 'Audit', 'value' => 'Renewal evidence history'],
        ]);
        $actions = $this->renderActions(['Review Renewal', 'Attach Document', 'Map Coverage', 'Request Approval']);

        return <<<HTML
            {$metrics}
            <div class="workspace-grid">
              <div class="workspace">
                <section class="panel" aria-labelledby="contract-summary-title">
                  <div class="panel-header">
                    <h2 id="contract-summary-title">Summary</h2>
                    <div class="toolbar" aria-label="Contract actions">
                      {$actions}
                    </div>
                  </div>
                  {$summary}
                </section>
                <section class="panel" aria-labelledby="contract-work-title">
                  <div class="panel-header">
                    <h2 id="contract-work-title">Renewal Work</h2>
                  </div>
                  <ul class="side-list">
                    <li><span class="side-label">Coverage</span><span class="side-value">Reconcile covered serials, service levels, and uncovered critical assets.</span></li>
                    <li><span class="side-label">Commercial</span><span class="side-value">Track quote, term, value, lease option, and budget approval state.</span></li>
                    <li><span class="side-label">Evidence</span><span class="side-value">Attach documents and preserve renewal decisions with the contract.</span></li>
                  </ul>
                </section>
              </div>
              <aside class="panel" aria-labelledby="contract-tabs-title">
                <div class="panel-header">
                  <h2 id="contract-tabs-title">Contract Tabs</h2>
                </div>
                {$tabs}
              </aside>
            </div>
            HTML;
    }

    /**
     * @param array{po: string, vendor: string, expected: string, exception: string, owner: string, received: string, labels: string, next: string} $batch
     */
    private function renderReceivingBatchDetailContent(array $batch): string
    {
        $summary = $this->renderTable(
            ['Field', 'Value'],
            [
                ['Purchase Order', $batch['po']],
                ['Vendor', $batch['vendor']],
                ['Expected', $batch['expected']],
                ['Received', $batch['received']],
                ['Exception', $batch['exception']],
                ['Owner', $batch['owner']],
                ['Labels', $batch['labels']],
                ['Next Step', $batch['next']],
            ],
        );
        $metrics = $this->renderMetrics([
            ['label' => 'Expected', 'value' => $batch['expected'], 'detail' => 'PO line summary'],
            ['label' => 'Received', 'value' => $batch['received'], 'detail' => 'Count captured'],
            ['label' => 'Labels', 'value' => $batch['labels'], 'detail' => 'Asset labels'],
            ['label' => 'Owner', 'value' => $batch['owner'], 'detail' => 'Receiving owner'],
        ]);
        $tabs = $this->renderSideItems([
            ['label' => 'Summary', 'value' => 'PO and receiving state', 'href' => '#receiving-summary-title', 'current' => true],
            ['label' => 'Lines', 'value' => 'Expected models and quantities'],
            ['label' => 'Serials', 'value' => 'Captured identities'],
            ['label' => 'Labels', 'value' => 'Print queue'],
            ['label' => 'Audit', 'value' => 'Receiving evidence'],
        ]);
        $actions = $this->renderActions(['Receive Items', 'Resolve Hold', 'Print Labels', 'Create Assets']);

        return <<<HTML
            {$metrics}
            <div class="workspace-grid">
              <div class="workspace">
                <section class="panel" aria-labelledby="receiving-summary-title">
                  <div class="panel-header">
                    <h2 id="receiving-summary-title">Summary</h2>
                    <div class="toolbar" aria-label="Receiving actions">
                      {$actions}
                    </div>
                  </div>
                  {$summary}
                </section>
                <section class="panel" aria-labelledby="receiving-work-title">
                  <div class="panel-header">
                    <h2 id="receiving-work-title">Receiving Work</h2>
                  </div>
                  <ul class="side-list">
                    <li><span class="side-label">Identity</span><span class="side-value">Capture serials, model normalization, and duplicate signals.</span></li>
                    <li><span class="side-label">Placement</span><span class="side-value">Assign storage, site staging, or deployment owner before commit.</span></li>
                    <li><span class="side-label">Audit</span><span class="side-value">Keep PO, label, and exception evidence with the created assets.</span></li>
                  </ul>
                </section>
              </div>
              <aside class="panel" aria-labelledby="receiving-tabs-title">
                <div class="panel-header">
                  <h2 id="receiving-tabs-title">Receiving Tabs</h2>
                </div>
                {$tabs}
              </aside>
            </div>
            HTML;
    }

    /**
     * @param array{number: string, source: string, rows: string, state: string, owner: string, valid: string, blocked: string, next: string} $batch
     */
    private function renderImportBatchDetailContent(array $batch): string
    {
        $summary = $this->renderTable(
            ['Field', 'Value'],
            [
                ['Batch', $batch['number']],
                ['Source', $batch['source']],
                ['Rows', $batch['rows']],
                ['Valid Rows', $batch['valid']],
                ['Blocked Rows', $batch['blocked']],
                ['State', $batch['state']],
                ['Owner', $batch['owner']],
                ['Next Step', $batch['next']],
            ],
        );
        $metrics = $this->renderMetrics([
            ['label' => 'Rows', 'value' => $batch['rows'], 'detail' => 'Uploaded records'],
            ['label' => 'Valid', 'value' => $batch['valid'], 'detail' => 'Ready for commit review'],
            ['label' => 'Blocked', 'value' => $batch['blocked'], 'detail' => 'Require correction'],
            ['label' => 'State', 'value' => $batch['state'], 'detail' => 'Current import stage'],
        ]);
        $tabs = $this->renderSideItems([
            ['label' => 'Summary', 'value' => 'Batch state and ownership', 'href' => '#import-summary-title', 'current' => true],
            ['label' => 'Mapping', 'value' => 'Source to asset fields'],
            ['label' => 'Validation', 'value' => 'Errors and duplicate signals'],
            ['label' => 'Preview', 'value' => 'Create and update plan'],
            ['label' => 'Audit', 'value' => 'Import evidence'],
        ]);
        $actions = $this->renderActions(['Map Fields', 'Validate Batch', 'Commit Import', 'Export Errors']);

        return <<<HTML
            {$metrics}
            <div class="workspace-grid">
              <div class="workspace">
                <section class="panel" aria-labelledby="import-summary-title">
                  <div class="panel-header">
                    <h2 id="import-summary-title">Summary</h2>
                    <div class="toolbar" aria-label="Import actions">
                      {$actions}
                    </div>
                  </div>
                  {$summary}
                </section>
                <section class="panel" aria-labelledby="import-work-title">
                  <div class="panel-header">
                    <h2 id="import-work-title">Import Work</h2>
                  </div>
                  <ul class="side-list">
                    <li><span class="side-label">Mapping</span><span class="side-value">Confirm source columns before validation and preview.</span></li>
                    <li><span class="side-label">Validation</span><span class="side-value">Resolve duplicate identities, missing required fields, and unsafe updates.</span></li>
                    <li><span class="side-label">Commit</span><span class="side-value">Create records only after summary review and audit capture.</span></li>
                  </ul>
                </section>
              </div>
              <aside class="panel" aria-labelledby="import-tabs-title">
                <div class="panel-header">
                  <h2 id="import-tabs-title">Import Tabs</h2>
                </div>
                {$tabs}
              </aside>
            </div>
            HTML;
    }

    /**
     * @param array{signal: string, asset: string, host: string, action: string, severity: string, source: string, owner: string, next: string} $exception
     */
    private function renderMonitoringExceptionDetailContent(array $exception): string
    {
        $summary = $this->renderTable(
            ['Field', 'Value'],
            [
                ['Signal', $exception['signal']],
                ['Severity', $exception['severity']],
                ['Asset', $exception['asset']],
                ['Cacti Host', $exception['host']],
                ['Source', $exception['source']],
                ['Owner', $exception['owner']],
                ['Action', $exception['action']],
                ['Next Step', $exception['next']],
            ],
        );
        $metrics = $this->renderMetrics([
            ['label' => 'Severity', 'value' => $exception['severity'], 'detail' => 'Triage priority'],
            ['label' => 'Asset', 'value' => $exception['asset'], 'detail' => 'InfraRegister reference'],
            ['label' => 'Cacti host', 'value' => $exception['host'], 'detail' => 'Polling source'],
            ['label' => 'Owner', 'value' => $exception['owner'], 'detail' => 'Team accountable'],
        ]);
        $tabs = $this->renderSideItems([
            ['label' => 'Summary', 'value' => 'Signal and ownership', 'href' => '#monitoring-summary-title', 'current' => true],
            ['label' => 'Cacti Host', 'value' => 'Polling and graph context'],
            ['label' => 'Asset Link', 'value' => 'Identity reconciliation'],
            ['label' => 'History', 'value' => 'Prior reconcile runs'],
            ['label' => 'Audit', 'value' => 'Resolution evidence'],
        ]);
        $actions = $this->renderActions(['Link Host', 'Suppress Exception', 'Create Asset', 'Resolve Signal']);

        return <<<HTML
            {$metrics}
            <div class="workspace-grid">
              <div class="workspace">
                <section class="panel" aria-labelledby="monitoring-summary-title">
                  <div class="panel-header">
                    <h2 id="monitoring-summary-title">Summary</h2>
                    <div class="toolbar" aria-label="Monitoring actions">
                      {$actions}
                    </div>
                  </div>
                  {$summary}
                </section>
                <section class="panel" aria-labelledby="monitoring-work-title">
                  <div class="panel-header">
                    <h2 id="monitoring-work-title">Reconcile Work</h2>
                  </div>
                  <ul class="side-list">
                    <li><span class="side-label">Validate</span><span class="side-value">Confirm the Cacti host, asset identity, and polling state.</span></li>
                    <li><span class="side-label">Resolve</span><span class="side-value">Link the host, create the missing asset, or suppress the known exception.</span></li>
                    <li><span class="side-label">Evidence</span><span class="side-value">Keep the reconcile result available for audit history.</span></li>
                  </ul>
                </section>
              </div>
              <aside class="panel" aria-labelledby="monitoring-tabs-title">
                <div class="panel-header">
                  <h2 id="monitoring-tabs-title">Exception Tabs</h2>
                </div>
                {$tabs}
              </aside>
            </div>
            HTML;
    }

    /**
     * @param array{number: string, assets: string, from: string, to: string, state: string, owner: string, due: string, evidence: string} $transfer
     */
    private function renderCustodyTransferDetailContent(array $transfer): string
    {
        $summary = $this->renderTable(
            ['Field', 'Value'],
            [
                ['Transfer', $transfer['number']],
                ['Assets', $transfer['assets']],
                ['From', $transfer['from']],
                ['To', $transfer['to']],
                ['State', $transfer['state']],
                ['Owner', $transfer['owner']],
                ['Due', $transfer['due']],
                ['Evidence', $transfer['evidence']],
            ],
        );
        $metrics = $this->renderMetrics([
            ['label' => 'Assets', 'value' => $transfer['assets'], 'detail' => 'Assets in this handoff'],
            ['label' => 'State', 'value' => $transfer['state'], 'detail' => 'Current transfer state'],
            ['label' => 'Owner', 'value' => $transfer['owner'], 'detail' => 'Team accountable'],
            ['label' => 'Due', 'value' => $transfer['due'], 'detail' => 'Next acceptance checkpoint'],
        ]);
        $tabs = $this->renderSideItems([
            ['label' => 'Summary', 'value' => 'Route and transfer state', 'href' => '#custody-summary-title', 'current' => true],
            ['label' => 'Assets', 'value' => 'Items in handoff'],
            ['label' => 'Evidence', 'value' => 'Photos and acknowledgements'],
            ['label' => 'Comments', 'value' => 'Operational notes'],
            ['label' => 'Audit', 'value' => 'Transfer events'],
        ]);
        $actions = $this->renderActions(['Accept Transfer', 'Reject Transfer', 'Request Return', 'Add Evidence']);

        return <<<HTML
            {$metrics}
            <div class="workspace-grid">
              <div class="workspace">
                <section class="panel" aria-labelledby="custody-summary-title">
                  <div class="panel-header">
                    <h2 id="custody-summary-title">Summary</h2>
                    <div class="toolbar" aria-label="Custody actions">
                      {$actions}
                    </div>
                  </div>
                  {$summary}
                </section>
                <section class="panel" aria-labelledby="custody-work-title">
                  <div class="panel-header">
                    <h2 id="custody-work-title">Transfer Work</h2>
                  </div>
                  <ul class="side-list">
                    <li><span class="side-label">Acceptance</span><span class="side-value">Confirm receiver, timestamp, and asset count.</span></li>
                    <li><span class="side-label">Evidence</span><span class="side-value">Attach required photos, acknowledgements, and exceptions.</span></li>
                    <li><span class="side-label">Audit</span><span class="side-value">Preserve custody events for lifecycle history.</span></li>
                  </ul>
                </section>
              </div>
              <aside class="panel" aria-labelledby="custody-tabs-title">
                <div class="panel-header">
                  <h2 id="custody-tabs-title">Transfer Tabs</h2>
                </div>
                {$tabs}
              </aside>
            </div>
            HTML;
    }

    /**
     * @param array{name: string, type: string, occupancy: string, work: string, address: string, access: string, power: string} $location
     */
    private function renderLocationDetailContent(array $location): string
    {
        $summary = $this->renderTable(
            ['Field', 'Value'],
            [
                ['Name', $location['name']],
                ['Type', $location['type']],
                ['Occupancy', $location['occupancy']],
                ['Open Work', $location['work']],
                ['Address', $location['address']],
                ['Access', $location['access']],
                ['Power', $location['power']],
            ],
        );
        $metrics = $this->renderMetrics([
            ['label' => 'Occupancy', 'value' => $location['occupancy'], 'detail' => 'Current capacity signal'],
            ['label' => 'Open work', 'value' => $location['work'], 'detail' => 'Next facilities action'],
            ['label' => 'Access', 'value' => $location['access'], 'detail' => 'Entry control'],
            ['label' => 'Power', 'value' => $location['power'], 'detail' => 'Power context'],
        ]);
        $tabs = $this->renderSideItems([
            ['label' => 'Summary', 'value' => 'Capacity and access', 'href' => '#location-summary-title', 'current' => true],
            ['label' => 'Assets', 'value' => 'Contained inventory'],
            ['label' => 'Rack Elevation', 'value' => 'Placement plan'],
            ['label' => 'Power', 'value' => 'Feed and circuit map'],
            ['label' => 'Audit', 'value' => 'Counts and exceptions'],
        ]);
        $actions = $this->renderActions(['Edit Location', 'Plan Move', 'Start Audit', 'Print Labels']);

        return <<<HTML
            {$metrics}
            <div class="workspace-grid">
              <div class="workspace">
                <section class="panel" aria-labelledby="location-summary-title">
                  <div class="panel-header">
                    <h2 id="location-summary-title">Summary</h2>
                    <div class="toolbar" aria-label="Location actions">
                      {$actions}
                    </div>
                  </div>
                  {$summary}
                </section>
                <section class="panel" aria-labelledby="location-work-title">
                  <div class="panel-header">
                    <h2 id="location-work-title">Location Work</h2>
                  </div>
                  <ul class="side-list">
                    <li><span class="side-label">Placement</span><span class="side-value">Review contained assets and capacity conflicts.</span></li>
                    <li><span class="side-label">Access</span><span class="side-value">Confirm contacts and access instructions before dispatch.</span></li>
                    <li><span class="side-label">Audit</span><span class="side-value">Capture counts, exceptions, and pending moves.</span></li>
                  </ul>
                </section>
              </div>
              <aside class="panel" aria-labelledby="location-tabs-title">
                <div class="panel-header">
                  <h2 id="location-tabs-title">Location Tabs</h2>
                </div>
                {$tabs}
              </aside>
            </div>
            HTML;
    }

    private function renderAssetDetailContent(Asset $asset): string
    {
        $status = $this->assetStatusLabel($asset->status);
        $identifier = $this->escape($asset->id->value);
        $summary = $this->renderTable(
            ['Field', 'Value'],
            [
                ['Name', $asset->name->value],
                ['Identifier', $asset->id->value],
                ['Status', $status],
                ['Source', 'Registered'],
                ['Custodian', 'Unassigned'],
                ['Monitoring', 'Not linked'],
            ],
        );
        $metrics = $this->renderMetrics([
            ['label' => 'Lifecycle', 'value' => $status, 'detail' => 'Current asset status'],
            ['label' => 'Custody', 'value' => 'Unassigned', 'detail' => 'No custodian assigned'],
            ['label' => 'Monitoring', 'value' => 'Not linked', 'detail' => 'No Cacti host attached'],
            ['label' => 'Audit', 'value' => 'New', 'detail' => 'Metadata review pending'],
        ]);
        $tabs = $this->renderSideItems([
            ['label' => 'Summary', 'value' => 'Identity and lifecycle', 'href' => '#asset-summary-title', 'current' => true],
            ['label' => 'Hardware', 'value' => 'Serial, vendor, model'],
            ['label' => 'Network', 'value' => 'Interfaces, IPs, circuits'],
            ['label' => 'Custody', 'value' => 'Assignments and transfers'],
            ['label' => 'Audit', 'value' => 'Event history'],
        ]);
        $actions = $this->renderActions(['Edit Asset', 'Change Status', 'Transfer Custody', 'Link Monitoring']);

        return <<<HTML
            {$metrics}
            <div class="workspace-grid">
              <div class="workspace">
                <section class="panel" aria-labelledby="asset-summary-title">
                  <div class="panel-header">
                    <h2 id="asset-summary-title">Summary</h2>
                    <div class="toolbar" aria-label="Asset actions">
                      {$actions}
                    </div>
                  </div>
                  {$summary}
                </section>
                <section class="panel" aria-labelledby="asset-followup-title">
                  <div class="panel-header">
                    <h2 id="asset-followup-title">Follow-up Work</h2>
                  </div>
                  <ul class="side-list">
                    <li><span class="side-label">Metadata</span><span class="side-value">Add asset tag, serial, type, vendor, and model.</span></li>
                    <li><span class="side-label">Placement</span><span class="side-value">Assign site, rack, owner, and custodian.</span></li>
                    <li><span class="side-label">Monitoring</span><span class="side-value">Link a Cacti host when polling starts.</span></li>
                  </ul>
                </section>
              </div>
              <aside class="panel" aria-labelledby="asset-tabs-title">
                <div class="panel-header">
                  <h2 id="asset-tabs-title">Asset Tabs</h2>
                </div>
                {$tabs}
              </aside>
            </div>
            <p class="field-note">Record identifier: {$identifier}</p>
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
    private function renderScreenContent(string $path, array $screen): string
    {
        $cards = $this->renderCards($screen['items']);
        $workspace = $this->workspaceFor($path);
        $metrics = $this->renderMetrics($workspace['metrics']);
        $actions = $this->renderActions($workspace['actions']);
        $table = $this->renderTable($workspace['columns'], $workspace['rows']);
        $sideItems = $this->renderSideItems($workspace['sideItems']);
        $tableTitle = $this->escape($workspace['tableTitle']);
        $sideTitle = $this->escape($workspace['sideTitle']);

        return <<<HTML
            {$metrics}
            <div class="workspace-grid">
              <div class="workspace">
                <section class="panel" aria-labelledby="primary-work-title">
                  <div class="panel-header">
                    <h2 id="primary-work-title">{$tableTitle}</h2>
                    <div class="toolbar" aria-label="Screen actions">
                      {$actions}
                    </div>
                  </div>
                  {$table}
                </section>
                <section class="panel" aria-labelledby="screen-capabilities-title">
                  <div class="panel-header">
                    <h2 id="screen-capabilities-title">Capabilities</h2>
                  </div>
                  {$cards}
                </section>
              </div>
              <aside class="panel" aria-labelledby="side-work-title">
                <div class="panel-header">
                  <h2 id="side-work-title">{$sideTitle}</h2>
                </div>
                {$sideItems}
              </aside>
            </div>
            HTML;
    }

    /**
     * @return array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * }
     */
    private function workspaceFor(string $path): array
    {
        $workspace = self::WORKSPACES[$path];

        if ($path === '/locations') {
            return $this->locationIndexWorkspace($workspace);
        }

        if ($path === '/custody') {
            return $this->custodyIndexWorkspace($workspace);
        }

        if ($path === '/monitoring') {
            return $this->monitoringIndexWorkspace($workspace);
        }

        if ($path === '/imports') {
            return $this->importIndexWorkspace($workspace);
        }

        if ($path === '/procurement') {
            return $this->procurementIndexWorkspace($workspace);
        }

        if ($path === '/contracts') {
            return $this->contractIndexWorkspace($workspace);
        }

        if ($path === '/reports') {
            return $this->reportIndexWorkspace($workspace);
        }

        if ($path === '/maintenance') {
            return $this->maintenanceIndexWorkspace($workspace);
        }

        if ($path === '/admin') {
            return $this->adminIndexWorkspace($workspace);
        }

        if ($path === '/network') {
            return $this->networkIndexWorkspace($workspace);
        }

        $assets = $this->assetRepository->all();

        if ($assets === []) {
            return $path === '/' ? $this->attentionIndexWorkspace($workspace) : $workspace;
        }

        $registeredCount = count($assets);
        $inServiceCount = $this->countAssetsByStatus($assets, AssetStatus::InService);
        $inStorageCount = $this->countAssetsByStatus($assets, AssetStatus::InStorage);
        $retiredCount = $this->countAssetsByStatus($assets, AssetStatus::Retired);
        $recentAssets = array_slice(array_reverse($assets), 0, 4);

        if ($path === '/') {
            $workspace['metrics'] = [
                ['label' => 'Tracked assets', 'value' => (string) $registeredCount, 'detail' => sprintf('%d registered locally', $registeredCount)],
                ['label' => 'In service', 'value' => (string) $inServiceCount, 'detail' => 'Active inventory records'],
                ['label' => 'In storage', 'value' => (string) $inStorageCount, 'detail' => 'Available or staged inventory'],
                ['label' => 'Retired', 'value' => (string) $retiredCount, 'detail' => 'Removed from active service'],
            ];
            $workspace['tableTitle'] = 'Recent Registrations';
            $workspace['columns'] = ['Asset', 'Status', 'Identifier', 'Due'];
            $workspace['rows'] = array_map(
                fn(Asset $asset): array => [
                    $asset->name->value,
                    $this->assetStatusLabel($asset->status),
                    substr($asset->id->value, 0, 8),
                    'Review metadata',
                ],
                $recentAssets,
            );
            $workspace['sideTitle'] = 'Recent Activity';
            $workspace['sideItems'] = array_map(
                fn(Asset $asset): array => [
                    'label' => 'Registered',
                    'value' => $asset->name->value,
                ],
                array_slice($recentAssets, 0, 3),
            );
        }

        if ($path === '/assets') {
            $workspace['metrics'] = [
                ['label' => 'Registered assets', 'value' => (string) $registeredCount, 'detail' => 'Stored in the local asset register'],
                ['label' => 'In service', 'value' => (string) $inServiceCount, 'detail' => 'Ready for monitoring and custody links'],
                ['label' => 'In storage', 'value' => (string) $inStorageCount, 'detail' => 'Warehouse, spare pool, or staged records'],
                ['label' => 'Retired', 'value' => (string) $retiredCount, 'detail' => 'Retained for lifecycle history'],
            ];
            $workspace['columns'] = ['Asset', 'Status', 'Identifier', 'Source', 'Custodian'];
            $workspace['rows'] = array_map(
                fn(Asset $asset): array => [
                    $this->assetLinkCell($asset),
                    $this->assetStatusLabel($asset->status),
                    substr($asset->id->value, 0, 8),
                    'Registered',
                    'Unassigned',
                ],
                $assets,
            );
            $workspace['sideTitle'] = 'Recent Registrations';
            $workspace['sideItems'] = array_map(
                fn(Asset $asset): array => [
                    'label' => $this->assetStatusLabel($asset->status),
                    'value' => $asset->name->value,
                ],
                array_slice($recentAssets, 0, 3),
            );
        }

        return $workspace;
    }

    /**
     * @param array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * } $workspace
     *
     * @return array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * }
     */
    private function locationIndexWorkspace(array $workspace): array
    {
        $workspace['rows'] = [];

        foreach (self::LOCATIONS as $id => $location) {
            $workspace['rows'][] = [
                $this->internalLinkCell('/locations?id=' . $id, $location['name']),
                $location['type'],
                $location['occupancy'],
                $location['work'],
            ];
        }

        return $workspace;
    }

    /**
     * @param array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * } $workspace
     *
     * @return array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * }
     */
    private function custodyIndexWorkspace(array $workspace): array
    {
        $workspace['rows'] = [];

        foreach (self::CUSTODY_TRANSFERS as $id => $transfer) {
            $workspace['rows'][] = [
                $this->internalLinkCell('/custody?id=' . $id, $transfer['number']),
                $transfer['assets'],
                $transfer['from'],
                $transfer['to'],
                $transfer['state'],
            ];
        }

        return $workspace;
    }

    /**
     * @param array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * } $workspace
     *
     * @return array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * }
     */
    private function monitoringIndexWorkspace(array $workspace): array
    {
        $workspace['rows'] = [];

        foreach (self::MONITORING_EXCEPTIONS as $id => $exception) {
            $workspace['rows'][] = [
                $this->internalLinkCell('/monitoring?id=' . $id, $exception['signal']),
                $exception['asset'],
                $exception['host'],
                $exception['action'],
            ];
        }

        return $workspace;
    }

    /**
     * @param array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * } $workspace
     *
     * @return array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * }
     */
    private function importIndexWorkspace(array $workspace): array
    {
        $workspace['rows'] = [];

        foreach (self::IMPORT_BATCHES as $id => $batch) {
            $workspace['rows'][] = [
                $this->internalLinkCell('/imports?id=' . $id, $batch['number']),
                $batch['source'],
                $batch['rows'],
                $batch['state'],
                $batch['owner'],
            ];
        }

        return $workspace;
    }

    /**
     * @param array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * } $workspace
     *
     * @return array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * }
     */
    private function procurementIndexWorkspace(array $workspace): array
    {
        $workspace['rows'] = [];

        foreach (self::RECEIVING_BATCHES as $id => $batch) {
            $workspace['rows'][] = [
                $this->internalLinkCell('/procurement?id=' . $id, $batch['po']),
                $batch['vendor'],
                $batch['expected'],
                $batch['exception'],
            ];
        }

        return $workspace;
    }

    /**
     * @param array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * } $workspace
     *
     * @return array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * }
     */
    private function contractIndexWorkspace(array $workspace): array
    {
        $workspace['rows'] = [];

        foreach (self::CONTRACT_RENEWALS as $id => $contract) {
            $workspace['rows'][] = [
                $this->internalLinkCell('/contracts?id=' . $id, $contract['number']),
                $contract['vendor'],
                $contract['coverage'],
                $contract['owner'],
                $contract['due'],
            ];
        }

        return $workspace;
    }

    /**
     * @param array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * } $workspace
     *
     * @return array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * }
     */
    private function reportIndexWorkspace(array $workspace): array
    {
        $workspace['rows'] = [];

        foreach (self::SAVED_REPORTS as $id => $report) {
            $workspace['rows'][] = [
                $this->internalLinkCell('/reports?id=' . $id, $report['name']),
                $report['audience'],
                $report['cadence'],
                $report['lastRun'],
            ];
        }

        return $workspace;
    }

    /**
     * @param array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * } $workspace
     *
     * @return array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * }
     */
    private function maintenanceIndexWorkspace(array $workspace): array
    {
        $workspace['rows'] = [];

        foreach (self::MAINTENANCE_WORK as $id => $work) {
            $workspace['rows'][] = [
                $this->internalLinkCell('/maintenance?id=' . $id, $work['number']),
                $work['asset'],
                $work['window'],
                $work['owner'],
                $work['state'],
            ];
        }

        return $workspace;
    }

    /**
     * @param array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * } $workspace
     *
     * @return array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * }
     */
    private function adminIndexWorkspace(array $workspace): array
    {
        $workspace['rows'] = [];

        foreach (self::ADMIN_CONFIGURATIONS as $id => $configuration) {
            $workspace['rows'][] = [
                $this->internalLinkCell('/admin?id=' . $id, $configuration['area']),
                $configuration['setting'],
                $configuration['state'],
                $configuration['owner'],
            ];
        }

        return $workspace;
    }

    /**
     * @param array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * } $workspace
     *
     * @return array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * }
     */
    private function networkIndexWorkspace(array $workspace): array
    {
        $workspace['rows'] = [];

        foreach (self::NETWORK_SIGNALS as $id => $signal) {
            $workspace['rows'][] = [
                $this->internalLinkCell('/network?id=' . $id, $signal['device']),
                $signal['interface'],
                $signal['peer'],
                $signal['signal'],
            ];
        }

        return $workspace;
    }

    /**
     * @param array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * } $workspace
     *
     * @return array{
     *     actions: list<string>,
     *     metrics: list<array{label: string, value: string, detail: string}>,
     *     tableTitle: string,
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     sideTitle: string,
     *     sideItems: list<array{label: string, value: string}>
     * }
     */
    private function attentionIndexWorkspace(array $workspace): array
    {
        $workspace['rows'] = [];

        foreach (self::ATTENTION_ITEMS as $id => $item) {
            $workspace['rows'][] = [
                $item['priority'],
                $this->internalLinkCell('/?id=' . $id, $item['item']),
                $item['owner'],
                $item['due'],
            ];
        }

        return $workspace;
    }

    /**
     * @param list<Asset> $assets
     */
    private function countAssetsByStatus(array $assets, AssetStatus $status): int
    {
        return count(array_filter($assets, fn(Asset $asset): bool => $asset->status === $status));
    }

    private function assetStatusLabel(AssetStatus $status): string
    {
        return match ($status) {
            AssetStatus::InService => 'In service',
            AssetStatus::InStorage => 'In storage',
            AssetStatus::Retired => 'Retired',
        };
    }

    private function assetLinkCell(Asset $asset): string
    {
        return $this->internalLinkCell('/assets?id=' . $asset->id->value, $asset->name->value);
    }

    private function internalLinkCell(string $href, string $label): string
    {
        return sprintf('internal-link:%s|%s', $href, $label);
    }

    /**
     * @param list<array{label: string, value: string, detail: string}> $metrics
     */
    private function renderMetrics(array $metrics): string
    {
        $items = '';

        foreach ($metrics as $metric) {
            $items .= sprintf(
                '<article class="metric"><p class="metric-label">%s</p><p class="metric-value">%s</p><p class="metric-detail">%s</p></article>',
                $this->escape($metric['label']),
                $this->escape($metric['value']),
                $this->escape($metric['detail']),
            );
        }

        return sprintf('<section class="metric-grid" aria-label="Key metrics">%s</section>', $items);
    }

    /**
     * @param list<string> $actions
     */
    private function renderActions(array $actions): string
    {
        $items = '';

        foreach ($actions as $action) {
            $items .= sprintf('<span class="secondary-action" aria-disabled="true">%s</span>', $this->escape($action));
        }

        return $items;
    }

    /**
     * @param list<string> $columns
     * @param list<list<string>> $rows
     */
    private function renderTable(array $columns, array $rows): string
    {
        $head = '';

        foreach ($columns as $column) {
            $head .= sprintf('<th scope="col">%s</th>', $this->escape($column));
        }

        $body = '';

        foreach ($rows as $row) {
            $cells = '';

            foreach ($row as $cell) {
                $cells .= $this->renderTableCell($cell);
            }

            $body .= sprintf('<tr>%s</tr>', $cells);
        }

        return sprintf(
            '<div class="data-table-wrap"><table class="data-table"><thead><tr>%s</tr></thead><tbody>%s</tbody></table></div>',
            $head,
            $body,
        );
    }

    private function renderTableCell(string $cell): string
    {
        if (str_starts_with($cell, 'internal-link:')) {
            [$href, $label] = explode('|', substr($cell, strlen('internal-link:')), 2);

            return sprintf(
                '<td><a href="%s">%s</a></td>',
                $this->escape($href),
                $this->escape($label),
            );
        }

        return sprintf('<td>%s</td>', $this->escape($cell));
    }

    /**
     * @param list<array{label: string, value: string, href?: string, current?: bool}> $items
     */
    private function renderSideItems(array $items): string
    {
        $html = '';

        foreach ($items as $item) {
            if (isset($item['href'])) {
                $current = ($item['current'] ?? false) ? ' aria-current="location"' : '';
                $html .= sprintf(
                    '<li><a href="%s"%s><span class="side-label">%s</span><span class="side-value">%s</span></a></li>',
                    $this->escape($item['href']),
                    $current,
                    $this->escape($item['label']),
                    $this->escape($item['value']),
                );

                continue;
            }

            $html .= sprintf(
                '<li><span class="side-label">%s</span><span class="side-value">%s</span></li>',
                $this->escape($item['label']),
                $this->escape($item['value']),
            );
        }

        return sprintf('<ul class="side-list">%s</ul>', $html);
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

    /**
     * @param array{
     *     label: string,
     *     section: string,
     *     title: string,
     *     summary: string,
     *     items: list<array{title: string, body: string}>
     * } $screen
     */
    private function renderBreadcrumbs(string $path, array $screen, bool $isDetailScreen): string
    {
        $items = '';

        if ($path !== '/') {
            $items .= sprintf(
                '<li><a href="/">%s</a></li>',
                $this->escape(self::SCREENS['/']['label']),
            );
        }

        $parentPath = $this->parentPath($path);

        if ($parentPath !== null && $parentPath !== '/' && isset(self::SCREENS[$parentPath])) {
            $items .= sprintf(
                '<li><a href="%s">%s</a></li>',
                $this->escape($parentPath),
                $this->escape(self::SCREENS[$parentPath]['label']),
            );
        }

        if ($isDetailScreen) {
            $items .= sprintf(
                '<li><a href="%s">%s</a></li>',
                $this->escape($path),
                $this->escape(self::SCREENS[$path]['label']),
            );
        }

        $items .= sprintf('<li aria-current="page">%s</li>', $this->escape($screen['title']));

        return sprintf('<nav class="breadcrumbs" aria-label="Breadcrumb"><ol>%s</ol></nav>', $items);
    }

    private function parentPath(string $path): ?string
    {
        if ($path === '/') {
            return null;
        }

        $lastSlash = strrpos($path, '/');

        if ($lastSlash === false || $lastSlash === 0) {
            return '/';
        }

        return substr($path, 0, $lastSlash);
    }

    private function renderNavigation(string $currentPath): string
    {
        $sections = [];

        foreach (self::SCREENS as $path => $screen) {
            $current = $path === $currentPath ? ' aria-current="page"' : '';
            $sections[$screen['section']] = ($sections[$screen['section']] ?? '') . sprintf(
                '<li><a class="nav-link" href="%s"%s>%s</a></li>',
                $this->escape($path),
                $current,
                $this->escape($screen['label']),
            );
        }

        $sectionItems = '';

        foreach ($sections as $section => $items) {
            $sectionItems .= sprintf(
                '<li class="nav-section"><p class="nav-section-title">%s</p><ul class="nav-list">%s</ul></li>',
                $this->escape($section),
                $items,
            );
        }

        return sprintf('<ul class="nav-section-list">%s</ul>', $sectionItems);
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
