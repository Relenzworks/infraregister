# InfraRegister Screen Map and Navigation

## Product UI Principle

InfraRegister is an operations product for ISP and enterprise infrastructure teams. The UI should prioritize repeat use, scan speed, auditability, and low-error data entry over marketing composition or decorative layout.

The stable accessibility target is WCAG 2.2 AA. WAI-ARIA 1.2 patterns should be used only where native HTML semantics are insufficient. WCAG 3 and APCA should inform design review, but they are not the compliance target until finalized.

## Application Shell

The full web application uses a persistent shell:

- Top bar: product name, global search, create menu, notifications, help, user menu.
- Primary sidebar: major modules with stable icons and text labels.
- Page header: screen title, key context, primary action, secondary actions.
- Content area: task-first tables, forms, detail panels, timelines, and maps.
- Right drawer: contextual preview, filters, assignment, comments, or audit details.
- Modal layer: destructive confirmations, quick create, import mapping, and conflict resolution.

The Cacti plugin wrapper should preserve this information architecture while adapting the outer chrome to Cacti conventions.

## Primary Navigation

- Dashboard
- Assets
- Network
- Locations
- People and Custody
- Procurement
- Contracts
- Maintenance
- Monitoring Links
- Reports
- Administration

## Dashboard

### Operations Overview

Purpose: Give operators the current state of infrastructure inventory and work requiring attention.

Primary content:

- Asset counts by lifecycle status.
- Recently registered or changed assets.
- Open custody transfers.
- Assets missing required metadata.
- Warranty, contract, and calibration expirations.
- Monitoring-link gaps.
- Import failures and data quality exceptions.

Primary actions:

- Register asset.
- Start import.
- Review exceptions.
- Create transfer.

## Assets

### Asset Index

Purpose: Main searchable inventory surface.

Primary content:

- Dense table with saved views.
- Columns: asset tag, name, type, status, site, rack, owner, custodian, monitoring state, last audit, updated time.
- Faceted filters: status, type, site, ownership, custodian, vendor, model, warranty state, monitoring state.
- Bulk selection for lifecycle transitions, label export, assignment, and audit tasks.

Primary actions:

- Register asset.
- Import assets.
- Export current view.
- Save view.

### Asset Detail

Purpose: One authoritative asset record.

Primary tabs:

- Summary: identity, lifecycle, type, site, rack, owner, custodian, monitoring link.
- Hardware: serial, model, vendor, MACs, optics, modules, power, ports.
- Network: management IPs, interfaces, circuits, VLANs, parent/child relations.
- Custody: assignments, check-in/check-out history, transfer state.
- Financial: purchase order, cost, depreciation, warranty, contract links.
- Maintenance: tickets, scheduled work, calibration, RMA, spares.
- Documents: invoices, photos, labels, config snapshots, attachments.
- Audit: immutable event history and field-level changes.

Primary actions:

- Edit asset.
- Change status.
- Transfer custody.
- Link monitoring object.
- Print label.
- Retire asset.

### Register Asset

Purpose: Fast, low-error creation of a single asset.

Form sections:

- Identity: name, asset tag, serial, type, subtype.
- Classification: vendor, model, role, criticality.
- Placement: organization, site, building, room, rack, rack unit, vehicle, warehouse bin.
- Ownership: owner, custodian, department, cost center.
- Optional links: Cacti device, graph tree, contract, purchase order.

Behavior:

- Save draft for multi-section entries.
- Detect duplicates by serial, asset tag, MAC, hostname, and Cacti device.
- Inline validation with clear recovery.

### Bulk Import

Purpose: Bring existing asset records into the system with validation.

Steps:

- Upload CSV or JSON.
- Map columns.
- Validate records.
- Resolve duplicates and conflicts.
- Preview creates and updates.
- Commit import.
- Review import report.

### Saved Views

Purpose: Let teams preserve operational lenses without custom reports.

Examples:

- Core routers missing contract.
- CPE pending deployment.
- Spares below threshold.
- Assets unlinked from monitoring.
- Retired assets still graphing in Cacti.

## Network

### Network Inventory

Purpose: Network-centric view of assets and relationships.

Primary content:

- Devices, interfaces, optics, circuits, IP addresses, VLANs, and peer relationships.
- Topology-adjacent list views before graph visualization.
- Missing or inconsistent network metadata.

### Interface Detail

Purpose: Manage ports and links as first-class infrastructure records.

Primary content:

- Interface identity, speed, media, optics, peer, circuit, VLAN membership, monitoring graph links.
- History of moves, optics swaps, and status changes.

### IP Address and Prefix Registry

Purpose: Track assigned infrastructure IPs and subnet ownership.

Primary content:

- Prefixes, address assignments, VRFs, sites, device links, reservation state.

## Locations

### Location Index

Purpose: Manage where assets live.

Types:

- Organization.
- Site.
- Building.
- Room.
- Rack.
- Tower.
- Cabinet.
- Warehouse.
- Vehicle.
- Customer premise.

### Location Detail

Purpose: Show assets, capacity, and activity for one location.

Primary content:

- Contained assets.
- Rack elevation or storage positions.
- Open transfers.
- Environmental notes.
- Contact and access notes.

### Rack Elevation

Purpose: Rack-aware placement for ISP and enterprise hardware.

Primary content:

- Front and rear views.
- RU occupancy.
- Power and network adjacency.
- Conflict checks.

## People and Custody

### People

Purpose: Users, custodians, assignees, and external contacts.

Primary content:

- Name, team, role, location, assigned assets, overdue returns.

### Custody Queue

Purpose: Manage transfers and check-in/check-out.

States:

- Draft.
- Pending acceptance.
- In transit.
- Accepted.
- Rejected.
- Overdue.

### Transfer Detail

Purpose: Auditable handoff record.

Primary content:

- Assets, source, destination, sender, receiver, timestamps, evidence, comments.

## Procurement

### Purchase Orders

Purpose: Track incoming inventory and receiving.

Primary content:

- PO number, vendor, expected items, received items, discrepancies, linked assets.

### Receiving

Purpose: Convert received goods into registered assets.

Workflow:

- Scan or enter serials.
- Match PO lines.
- Create assets.
- Assign initial storage location.
- Print labels.

### Vendors and Models

Purpose: Normalize vendor, model, and support data.

Primary content:

- Vendor profile.
- Model catalog.
- Lifecycle support dates.
- Required fields per model type.

## Contracts

### Contract Index

Purpose: Track support, warranty, lease, licensing, and maintenance contracts.

Primary content:

- Vendor, term, renewal date, covered assets, cost, owner, renewal risk.

### Contract Detail

Purpose: One authoritative contract record.

Primary tabs:

- Summary.
- Covered assets.
- Documents.
- Renewals.
- Audit.

## Maintenance

### Maintenance Calendar

Purpose: Planned work and recurring maintenance.

Primary content:

- Work windows, affected assets, owner, status, related tickets.

### RMA and Repair

Purpose: Track failed equipment leaving and returning to inventory.

Primary content:

- Asset, failure reason, vendor case, shipping, replacement, final disposition.

### Spare Pools

Purpose: Track minimum stock and replenishment needs.

Primary content:

- Model, site, quantity on hand, threshold, reserved quantity, reorder signal.

## Monitoring Links

### Cacti Linkage

Purpose: Bridge InfraRegister assets and Cacti monitoring objects.

Primary content:

- Linked devices.
- Unlinked monitored devices.
- Assets without monitoring.
- Retired assets still monitored.
- Graph tree relationships.

### Monitoring Exceptions

Purpose: Resolve mismatches between inventory and monitoring.

Exception types:

- Hostname mismatch.
- Duplicate device link.
- Missing serial.
- Retired asset still polling.
- Active asset missing polling.

## Reports

### Report Library

Purpose: Standard operational, financial, and compliance reports.

Examples:

- Asset register.
- Assets by site.
- Warranty expiration.
- Contract renewal.
- Custody by person.
- Retired assets.
- Missing metadata.
- Monitoring coverage.

### Report Builder

Purpose: Create saved reports from asset fields and relationships.

Capabilities:

- Field selection.
- Filters.
- Grouping.
- Scheduled exports.
- CSV and PDF output.

## Administration

### Settings

Purpose: Product configuration.

Sections:

- Organizations and departments.
- Asset types and custom fields.
- Lifecycle states.
- Numbering and tag policies.
- Duplicate detection rules.
- Import templates.
- Notification rules.

### Roles and Permissions

Purpose: Access control for core app and Cacti plugin.

Permission areas:

- View assets.
- Create assets.
- Edit assets.
- Transfer custody.
- Retire assets.
- Manage contracts.
- Manage settings.
- Export data.

### Audit Log

Purpose: Global activity and compliance history.

Primary content:

- Actor, action, target, timestamp, source adapter, before/after details.

### Integrations

Purpose: Configure external systems.

Initial integrations:

- Cacti.
- CSV import/export.
- Webhook events.

Future integrations:

- LDAP or SSO.
- Ticketing systems.
- Procurement systems.
- IPAM systems.

## Global Search

Search should be available from every screen.

Search targets:

- Asset tag.
- Name.
- Serial.
- MAC.
- IP address.
- Hostname.
- Cacti device.
- Purchase order.
- Contract.
- Person.
- Location.

Results should be grouped by entity type and support keyboard navigation.

## Create Menu

The create menu should expose common creation paths:

- Asset.
- Transfer.
- Location.
- Purchase order.
- Contract.
- Maintenance event.
- Import.

## Responsive Navigation

Desktop:

- Persistent sidebar with labels.
- Top bar search.
- Optional right drawer for contextual detail.

Tablet:

- Collapsible sidebar.
- Search remains in top bar.
- Detail drawers become full-height overlays.

Mobile:

- Bottom navigation for Dashboard, Assets, Search, Transfers, More.
- Tables convert to list rows with the most important fields first.
- Bulk actions remain available after selection, not always visible.

## Empty, Loading, and Error States

Every index screen must define:

- Empty state: action-oriented, with a primary next step.
- Loading state: stable dimensions to avoid layout shift.
- Error state: cause, recovery action, retry if applicable.
- Permission state: state what cannot be accessed without revealing sensitive inventory.

## Accessibility Requirements

- Meet WCAG 2.2 AA for production UI.
- Use semantic HTML before ARIA.
- Provide visible keyboard focus that is never obscured.
- Keep interactive targets at least 24 by 24 CSS pixels, with 44 by 44 preferred for primary controls.
- Do not rely on color alone for status.
- Announce async success and error messages through appropriate live regions.
- Preserve logical heading order.
- Support reduced motion.
- Keep labels persistent for high-risk forms.
- Validate on submit and on field exit only where it reduces errors.
- Avoid destructive actions without confirmation and clear undo strategy where possible.

## Implementation Sequence

1. Core shell and navigation skeleton.
2. Asset index, register asset, and asset detail.
3. Location index and location detail.
4. Custody transfer queue and transfer detail.
5. Cacti linkage and monitoring exceptions.
6. Import workflow.
7. Contracts and procurement.
8. Reports.
9. Administration.

Each implementation PR should include unit, integration, end-to-end, accessibility-oriented assertions, and visual regression coverage appropriate to the changed surface.
