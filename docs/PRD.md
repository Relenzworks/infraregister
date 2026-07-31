# InfraRegister PRD

## Purpose

InfraRegister manages infrastructure asset records, custody, assignment, and lifecycle operations for ISP and enterprise environments. The product should cover the practical asset workflows expected from IT asset management tools while extending them for network infrastructure, facilities, field operations, and regulated change control.

## Phase 1 Goals

- Establish the domain model for asset identity, naming, status, and registration.
- Provide a framework-independent PHP core that can be reused by a Cacti plugin and future delivery adapters.
- Provide an initial CLI workflow for registering assets.
- Persist asset records through an adapter that is safe for local development and deterministic tests.
- Enforce PHPStan level 10, coding standards, and TDD-first changes.
- Require 100% line coverage for production code in Phase 1.
- Verify every PR on PHP 8.3 and PHP 8.4.
- Verify primary Linux targets on every PR and extended platforms weekly.

## Users

- Network engineers tracking routers, switches, optics, CPE, servers, and spares.
- Field operations teams moving equipment between warehouses, vehicles, towers, cabinets, and customer sites.
- Enterprise infrastructure teams managing ownership, lifecycle state, and audit history.
- Cacti administrators who need asset records close to monitoring workflows.

## Initial Scope

- Register an asset with a generated immutable identifier.
- Validate human-readable asset names.
- Store and retrieve assets through a repository port.
- Provide a CLI command for asset registration.
- Provide JSON persistence for local and test use.
- Provide unit, integration, end-to-end, coverage, and platform tests.

## Deferred Scope

- Multi-user web UI.
- Authentication and authorization.
- Cacti plugin UI integration.
- Asset tags, serial numbers, contracts, depreciation, procurement, check-in/check-out, reservations, and audit trail.
- Database-backed persistence.
- Import/export workflows.
- Release packaging.

## Success Criteria

- New production code is introduced with tests first or in the same PR.
- Production code coverage remains at 100%.
- CI is green on PHP 8.3 and PHP 8.4.
- Docker platform checks pass for Ubuntu 24.04, Rocky Linux 9, and Red Hat UBI 9.
- Weekly extended platform checks exist for macOS and FreeBSD.
- No releases or tags are created during Phase 1.
