# InfraRegister TRD

## Architecture

InfraRegister uses a DDD-oriented hexagonal architecture:

- Domain objects hold business invariants and avoid framework dependencies.
- Application handlers coordinate use cases through ports.
- Ports define contracts required by the application layer.
- Infrastructure adapters implement ports for CLI, persistence, and future integrations.

The core package must remain reusable by the Cacti plugin and should not depend on Cacti runtime globals.

## Runtime Targets

- Minimum PHP: 8.3.
- Compatibility target: PHP 8.3 and PHP 8.4 in CI.
- Primary Linux targets: Ubuntu 24.04, Rocky Linux 9, and Red Hat UBI 9.
- Extended scheduled targets: macOS and FreeBSD.

## Dependency Policy

- Use Symfony components where they provide stable, idiomatic PHP building blocks.
- Keep framework use inside adapters unless the component is a value-level utility acceptable in the core.
- Avoid global state and static service locators.
- Keep persistence behind repository ports.

## Initial Components

- `Domain\Asset\AssetId`: immutable asset identity.
- `Domain\Asset\AssetName`: validated human-readable name.
- `Domain\Asset\AssetStatus`: lifecycle state enum.
- `Application\Asset\RegisterAsset`: command DTO.
- `Application\Asset\RegisterAssetHandler`: registration use case.
- `Port\AssetRepository`: persistence contract.

## Adapter Direction

- CLI adapter: Symfony Console commands.
- Local persistence adapter: JSON file storage with path boundary checks, locking, and atomic replacement.
- Future database adapter: normalized relational schema for production deployments.
- Future Cacti adapter: plugin wrapper that maps Cacti UI, auth, and database integration to the core use cases.

## Testing Requirements

- Unit tests for domain invariants and application handlers.
- Integration tests for persistence adapters.
- End-to-end tests for executable CLI flows.
- Coverage tests with a 100% production line coverage gate.
- Static analysis at PHPStan level 10.
- Coding standards through PHP-CS-Fixer.
- Docker platform tests for primary Linux targets.
- Weekly extended platform tests for macOS and FreeBSD.

## Release Policy

Phase 1 remains pre-release. Work may define SemVer policy and changelog structure, but it must not create tags, GitHub releases, or package releases.
