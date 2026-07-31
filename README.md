# InfraRegister

InfraRegister is a standalone enterprise asset management system focused on ISP and IT operations. It manages infrastructure asset records, custody, lifecycle operations, procurement context, maintenance state, and operational relationships for teams that run networks and shared technology estates.

This repository owns the EAM product and its framework-independent DDD/hexagonal domain core. It must remain usable without Cacti.

The Cacti integration is a separate project: `Relenzworks/cacti-plugin-infraregister`. That repository should wrap InfraRegister through stable application interfaces and adapters; it should not own core asset, custody, procurement, or lifecycle business rules.

## Project Boundaries

- `Relenzworks/infraregister`: standalone ISP/IT EAM product, domain model, application services, ports, persistence adapters, CLI, API, web UI, and test harnesses
- `Relenzworks/cacti-plugin-infraregister`: Cacti 1.2.x plugin wrapper, menu hooks, permissions, Cacti host/graph/tree mapping, and adapter glue into InfraRegister
- Shared behavior belongs here first, behind domain/application interfaces
- Cacti-specific behavior belongs only in the plugin wrapper
- Integration between the two projects must be covered by contract and end-to-end tests

## Quality Bar

- PHP 8.3 minimum, aligned to the current Ubuntu/RHEL long-term enterprise overlap
- PSR-4 namespace: `RelenzWorks\InfraRegister`
- Domain/application code isolated from Cacti
- PHPStan level 10
- PHPUnit with coverage reporting
- TDD-first changes

## Commands

```bash
composer install
composer quality
composer test:coverage
```
