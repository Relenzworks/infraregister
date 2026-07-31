# InfraRegister Core

InfraRegister manages infrastructure asset records, custody, and lifecycle operations for ISP and enterprise environments.

This repository contains the framework-independent DDD/hexagonal core. Cacti integration belongs in `Relenzworks/cacti-plugin-infraregister`.

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
