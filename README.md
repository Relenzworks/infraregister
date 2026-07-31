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
```
