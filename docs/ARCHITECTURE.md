# Architecture

InfraRegister is split from its Cacti integration on purpose.

## Standalone EAM

The `Relenzworks/infraregister` repository owns the product domain:

- asset identity, classification, lifecycle, and status
- custody, assignment, chain of responsibility, and audit history
- ISP and IT operational relationships, including sites, racks, circuits, devices, optics, spares, vendors, contracts, and maintenance windows
- import/export, CLI, API, web UI, persistence, reporting, and test infrastructure

This code must not depend on Cacti runtime globals, tables, authentication, page rendering, or plugin hooks.

## Cacti Plugin Wrapper

The `Relenzworks/cacti-plugin-infraregister` repository owns the Cacti adapter:

- Cacti 1.2.x plugin metadata and lifecycle hooks
- Cacti menu, permission, CSRF, session, and page integration
- Cacti host, graph, graph tree, poller, and data source mapping
- adapter calls into InfraRegister application services

The plugin may depend on InfraRegister. InfraRegister must not depend on the plugin.

## Testing Contract

Every cross-project integration must have tests at the right boundary:

- unit tests for domain rules in InfraRegister
- application tests for use cases in InfraRegister
- adapter contract tests for the plugin-to-core boundary
- Cacti plugin integration tests for Cacti hook and permission behavior
- end-to-end tests for complete registration, linking, and reconciliation flows
