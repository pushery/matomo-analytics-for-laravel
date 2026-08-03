# Contributing

Thanks for considering a contribution — issues and pull requests are both welcome.

## Reporting an issue

Use the GitHub issue templates (bug report / feature request). Include the package
version and a minimal reproduction, and never paste secrets or credentials.

## Pull requests

- Keep the public API stable, or call out the break explicitly.
- Add tests for any behavior change.
- Update `README.md` and the `CHANGELOG.md` `## [Unreleased]` section.
- Keep each commit focused.

## Local requirements

**PHP 8.4.1 or newer** to work on the package, even though the package itself installs
on 8.4.0. The test toolchain is what raises the floor — it pulls `symfony/process`,
which needs 8.4.1 — so on exactly 8.4.0 `composer install` fails with a message about
`symfony/process` rather than about the test runner. Upgrade the patch version; nothing
else is wrong.

## Quality bar

This package holds itself to a strict quality bar — Laravel Pint, Larastan at `max`,
Rector, and Pest with 100% line and type coverage, plus mutation testing, a
real-browser end-to-end suite, and cross-engine tests against real PostgreSQL and
MySQL 8.4 (the engines it runs on in production). The maintainers run the full gate
locally before every release, so a pull request that keeps the public API stable and
ships tests for its change is easy to accept.
