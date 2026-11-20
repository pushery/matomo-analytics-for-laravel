# Contributing

Thanks for considering a contribution. Issues and pull requests are both welcome, and the
section on pull requests says how a change sent here reaches a release.

## Reporting an issue

Use the GitHub issue templates (bug report / feature request). Include the package
version and a minimal reproduction, and never paste secrets or credentials.

## Pull requests

This repository is a mirror of the released tree, and every release replaces its contents
with the tree it releases. A pull request is therefore not merged here: a maintainer carries
an accepted change into the development repository, and it reaches this mirror with the
next release.

- Keep the public API stable, or call out the break explicitly.
- Add tests for any behavior change.
- Update `README.md` where your change alters what it describes. Leave `CHANGELOG.md` as it
  is: it lists released versions, and the entry for your change is written when the change is
  carried into the development repository.
- Keep each commit focused.

## Local requirements

**PHP 8.4.1 or newer** to work on the package, even though the package itself installs
on 8.4.0. The test toolchain is what raises the floor — it pulls `symfony/process`,
which needs 8.4.1 — so on exactly 8.4.0 `composer install` fails with a message about
`symfony/process` rather than about the test runner. Upgrade the patch version; nothing
else is wrong.

## Quality bar

This package holds itself to a strict quality bar — Laravel Pint, Larastan at level 10,
Rector, and Pest with 100% line and type coverage, plus mutation testing, a
real-browser end-to-end suite, and cross-engine tests against real PostgreSQL and
MySQL 8.4 (the engines it runs on in production).

**That bar runs in the development repository, not here.** This repository is a
read-only mirror of the released tree: it carries the package itself, which is what
Composer installs, and deliberately not the test suite, the task runner or the CI
configuration. So the bar cannot run in a clone of this repository: there is nothing
here for it to run against.

What that means in practice: send the change with a clear description and a minimal
reproduction. It is rerun against the full bar on the way in, and the tests for it are
written there. Saying plainly what you could not verify is more useful than a claim that
sounds checked.
