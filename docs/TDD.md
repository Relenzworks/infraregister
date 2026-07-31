# TDD Strategy

## Rule

Every production behavior change must be introduced with a failing test first or in the same PR. A PR that changes production code without appropriate tests is incomplete.

## Test Types

- Unit tests: value objects, entities, domain rules, and application handlers.
- Integration tests: repository adapters, filesystem behavior, locking, and serialization.
- End-to-end tests: executable CLI and future UI/API workflows.
- Static tests: PHPStan level 10 and coding standards.
- Platform tests: target operating systems and PHP versions.

## Coverage Gate

Phase 1 requires 100% production line coverage. Defensive branches that cannot be deterministically triggered across supported runtimes may be excluded only when:

- The branch is a native runtime or OS failure guard.
- The surrounding behavior has direct tests.
- The exclusion is narrowly scoped.
- A broader adapter-based test seam would add more complexity than value.

## PR Expectations

- Keep PRs focused on one change class.
- Include the smallest useful tests at the correct layer.
- Prefer domain and application tests before adapter tests.
- Add end-to-end coverage for user-visible commands or workflows.
- Run local quality checks before opening or updating a PR.

## Required Local Commands

```bash
composer quality
composer test:coverage
```

For platform-sensitive work after the platform tooling PR lands:

```bash
tools/test-docker-platforms.sh ubuntu-24.04 rocky-9 ubi-9
```
