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
- GPL-3.0-or-later licensing

## Commands

```bash
composer install
composer quality
composer test:coverage
php bin/infraregister asset:register "Core Router 01"
tools/test-docker-platforms.sh ubuntu-24.04 rocky-9
docker build -f docker/app.Dockerfile -t infraregister-app .
docker run --rm -e INFRAREGISTER_WRITE_AUTH=infraregister:local-dev -p 127.0.0.1:8080:8080 infraregister-app
```

The web entrypoint is a Phase 1 local development surface. Registration writes require `INFRAREGISTER_WRITE_AUTH` Basic Auth credentials and the documented Docker command binds the published port to loopback.

## Authentication and Authorization

InfraRegister uses Symfony Security components for role checks and Symfony LDAP for directory-backed authentication.

Local development can use either compatibility credentials:

```bash
INFRAREGISTER_WRITE_AUTH=infraregister:local-dev
```

or explicit local RBAC users:

```bash
INFRAREGISTER_LOCAL_USERS='alice:secret=viewer;ops:secret=operator;admin:secret=admin'
```

LDAP can be enabled with:

```bash
INFRAREGISTER_LDAP_URI='ldap://directory.example'
INFRAREGISTER_LDAP_BASE_DN='ou=people,dc=example,dc=com'
INFRAREGISTER_LDAP_USER_FILTER='(uid={username})'
INFRAREGISTER_LDAP_BIND_DN='cn=infraregister,ou=services,dc=example,dc=com'
INFRAREGISTER_LDAP_BIND_PASSWORD='service-password'
INFRAREGISTER_LDAP_GROUP_ROLE_MAP='InfraRegister Admins=admin;InfraRegister Operators=operator'
```

Supported roles are `viewer`, `operator`, `asset-manager`, and `admin`. Asset registration requires `operator`, `asset-manager`, or `admin`.
