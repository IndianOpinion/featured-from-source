# Contributing

Thank you for helping. Bug reports, fixes and small, focused features are
welcome.

## Setup

You need PHP 8.1 or later and Composer.

```sh
git clone https://github.com/IndianOpinion/featured-from-source.git
cd featured-from-source
composer install
```

No WordPress install is needed for the tests: they run against a small stub
layer in `tests/stubs/` with a fake HTTP transport.

## Tests and checks

```sh
composer test   # PHPUnit
composer stan   # PHPStan, level 8
composer cs     # phpcs, PSR-12
composer cbf    # fix what phpcs can fix
```

All three must pass before a pull request is merged. CI runs them on PHP 8.1,
8.2 and 8.3.

Behaviour is specified case by case in [docs/PRD.md](docs/PRD.md) section 4,
and each case has a test with the same number in `tests/Behaviour/`
(`testCase01` to `testCase77`). A change in behaviour needs a change in its
test, and usually a line in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Style

- PSR-12, `declare(strict_types=1);` in every file, PHP 8.1 syntax only (no
  readonly classes, no DNF types, no `true` type, no constants in traits).
- Typed exceptions; no silent failure; never the `@` operator.
- No `eval`, no shell. Escape every output in admin screens; nonces and
  capability checks on everything admin-facing.
- No runtime dependencies.
- Plain, short sentences in docs and messages. British spelling.

## Pull requests

- One topic per pull request. Describe what changes and why.
- Add or update tests and the CHANGELOG `Unreleased` section.
- Reason codes, hooks and the PHP functions are public: changing or removing
  one needs a major version.
- A DCO-style sign-off is not required.

## Reporting security issues

Please do not open a public issue. See [SECURITY.md](SECURITY.md).
