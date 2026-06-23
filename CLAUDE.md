# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Kimai is a Symfony 6.4 time-tracking application (PHP 8.2+, Doctrine ORM, MariaDB/MySQL). Frontend assets are built with Symfony Webpack Encore (Yarn 4). The `kimai/kimai` Composer project autoloads `App\` from `src/` and `KimaiPlugin\` from `var/plugins/` (see `composer.json`).

## Common commands

All PHP commands assume PHP 8.2+ on `$PATH`. Use the user-facing Composer scripts — they wrap the underlying tools with the right flags.

### Tests
- `composer tests` — full suite (`vendor/bin/phpunit tests/`).
- `composer tests-unit` — fast, excludes `@group integration` (no DB writes).
- `composer tests-integration` — only `@group integration`.
- Run a single test file: `vendor/bin/phpunit tests/Path/To/FooTest.php`
- Run a single test method: `vendor/bin/phpunit --filter testFoo tests/Path/To/FooTest.php`
- The test DB is wiped & reinstalled by `tests/bootstrap.php` whenever `BOOTSTRAP_RESET_DATABASE=true` (default in `phpunit.xml.dist`). To iterate quickly without re-installing, run with `BOOTSTRAP_RESET_DATABASE=false vendor/bin/phpunit ...`. The bootstrap shells out to `bin/console kimai:reset:test`.
- Integration tests require MySQL/MariaDB reachable at the URL in `phpunit.xml.dist` (`kimai2_test`@127.0.0.1, user/pass `kimai2_test`). See the SQL block in that file for the grant statement.

### Static analysis & code style
- `composer phpstan` — runs both `phpstan.neon` (src, level 9) and `tests/phpstan.neon`.
- `composer codestyle` — PHP-CS-Fixer dry run.
- `composer codestyle-fix` — apply fixes.
- `composer linting` — `bin/console lint:container`, `lint:yaml config`, `lint:twig templates`, `doctrine:schema:validate --skip-sync`, `lint:xliff translations`.
- `composer code-check` — everything (pre-commit + integration tests). Same as the `pre-commit` script plus integration tests.
- `./phpstan.sh [pluginName|core|tests]` and `./php-cs-fixer.sh [pluginName|core]` — run the same tools against an installed plugin under `var/plugins/<Name>Bundle/` using that plugin's local `phpstan.neon` / `.php-cs-fixer.dist.php`. With no argument they run against the core, then iterate over every plugin.

### Frontend
- `yarn dev` / `yarn watch` / `yarn build` — Webpack Encore (dev / dev+watch / production). Entries are declared in `webpack.config.js` (`app`, `app-rtl`, `invoice`, `invoice-pdf`, `export-pdf`, `chart`, `calendar`, `dashboard`, `highlight`).
- `yarn lint` — ESLint over `assets/js/`.

### Symfony console
- `bin/console kimai:install` — initial install / DB migrate after pulling.
- `bin/console kimai:reload` — clear caches and warm up; used by CI.
- `bin/console kimai:reset:dev` / `kimai:reset:test` — drop DB, re-migrate, load fixtures. The test reset is what `tests/bootstrap.php` invokes.
- `bin/console doctrine:migrations:migrate` — apply schema migrations from `migrations/`.

## Architecture

### Kernel & bundles
`src/Kernel.php` is a custom `MicroKernelTrait` kernel. Two non-obvious behaviors live here:

1. **Plugin auto-discovery.** Bundles in `var/plugins/*Bundle/` are discovered at boot in `getBundleClasses()`. The directory name (without trailing `Bundle`) must match the bundle class under `KimaiPlugin\<Name>Bundle\<Name>Bundle`. A `.disabled` marker file skips a plugin; a `PluginMetadata` `kimaiVersion` greater than `Constants::VERSION_ID` throws. In the `test` environment plugins are NOT loaded — tests run against the core only. If `config/bundles-local.php` exists it overrides dynamic discovery.

2. **Config loading order.** `configureContainer()` loads everything under `config/packages/*` but explicitly skips `local.yaml`, then loads `local.yaml` last (only outside `test`). `config/packages/kimai.yaml` is the shipped defaults; **users override via `config/packages/local.yaml`**. Never edit `kimai.yaml` to change defaults for an installation.

3. **Routes.** App routes load first, then each plugin bundle's `Resources/config/routes.{php,yaml}` (or `config/routes.{php,yaml}`), then `config/routes.yaml` last so application routes win over plugin routes.

4. **Compiler passes.** `TwigContextCompilerPass`, `InvoiceServiceCompilerPass`, `ExportServiceCompilerPass`, `WidgetCompilerPass` (all `src/DependencyInjection/Compiler/`) collect tagged services. When adding a new Twig context provider, invoice renderer, export renderer, or dashboard widget, register via the existing tag — the compiler pass wires it up.

### Service registration
`config/services.yaml` autoconfigures `App\` from `src/*` with several explicit excludes (`Entity/`, `Model/`, `Repository/{Loader,Paginator,Query,Result}/`, etc.). Controllers get the `controller.service_arguments` tag. Doctrine repositories (`App\Repository\*Repository`) are registered explicitly via `doctrine.orm.entity_manager` factory — when adding a new repository class, add the matching factory entry there.

### Permissions
Role permissions are defined in `config/packages/kimai.yaml` under `kimai.permissions`. `sets:` groups permission names into named sets; `roles:` composes sets per role (ROLE_USER, ROLE_TEAMLEAD, ROLE_ADMIN, ROLE_SUPER_ADMIN). `App\Security\RolePermissionManager` consumes these as compile-time parameters. Permission checks throughout the app go through `Voter` classes in `src/Voter/`.

### Authentication
Multiple stacked providers via `KimaiUserProvider` (chain): database, LDAP, SAML. LDAP is wired via a custom `FormLoginLdapFactory` registered in `Kernel::build()`. 2FA (TOTP + backup codes) via `scheb/2fa-bundle`. `App\Saml\SamlProvider` bridges onelogin/php-saml to the user provider.

### API
REST API lives under `src/API/` using FOSRestBundle + JMS Serializer + Nelmio API Doc Bundle. Controllers extend `BaseApiController`. `App\API\ViewHandler` wraps `fos_rest.view_handler.default`. API models for serialization live in `src/API/Model/` (excluded from autowiring).

### Domain layout
- **Entities** (`src/Entity/`): Doctrine ORM mapped via attributes. Core domain is `User`/`Team`, `Customer` → `Project` → `Activity` → `Timesheet`, plus `Invoice`, `Tag`, `WorkingTime`, `AccessToken`. Many entities use `*Meta` siblings (`ActivityMeta`, `ProjectMeta`, ...) for user-defined custom fields.
- **Top-level domain folders** mirror these aggregates: `src/Activity/`, `src/Customer/`, `src/Project/`, `src/Timesheet/`, `src/Invoice/`, `src/Export/`, `src/Reporting/`, `src/WorkingTime/`, etc. Each folder typically owns its service/calculator/event logic.
- **Repositories** (`src/Repository/`) own queries; complex filtering uses `Repository/Query/*Query.php` value objects and `Repository/Loader/` for hydration. Do not add raw DQL in controllers.
- **Migrations** (`migrations/`): Doctrine migrations. New schema changes go here, not as edits to old migration files. `MigrationTemplate.txt` shows the project's preferred format.

### Configuration: file vs database
There are two parallel configuration systems:
- **File-based** (`config/packages/local.yaml`): server-side overrides under the `kimai:` extension. Loaded last, can override anything in shipped `kimai.yaml`.
- **Database-backed** (`App\Configuration\SystemConfiguration` over the `Configuration` entity): admin-editable through the UI. The system config falls back to the file config when a key is not set in the DB.

Many `kimai.yaml` comments say "this setting can be changed through the Administration screen" — those are the DB-backed ones.

## Tooling notes

- **PHPStan runs at level 9** with strict rules (`phpstan.neon`). `tests/phpstan.neon` is a separate config for the test suite.
- **PHP-CS-Fixer** uses `.php-cs-fixer.dist.php` and enforces a file header on every PHP file (`This file is part of the Kimai time-tracking app...`) — `composer codestyle-fix` will add it automatically.
- **CI matrix**: PHP 8.2 / 8.3 / 8.4 / 8.5 against MySQL latest. The PHP 8.5 job also produces coverage. See `.github/workflows/testing.yaml` for the full sequence (lint → phpstan → linting → unit tests → full tests → migrations check → security check).
- Plugins are not part of the core repo; when a task involves a plugin, look under `var/plugins/<Name>Bundle/` (typically not present in a fresh checkout).
