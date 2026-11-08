# Gift Shop Management System

A Laravel application for managing gift shop products, customers, orders, suppliers, and reports. Blade, Tailwind CSS, and native JavaScript provide a responsive interface with dark-mode styling.

This document describes the implemented scope for the planned **1.0.0 release**. Release preparation is ongoing; see the release checklist and licensing note below.

> **Local use only:** this is a single-user management system without authentication, registration, or user permissions. Anyone who can reach it can access its data and management operations. Keep it on a trusted local machine with loopback-only access. Do not expose it publicly or to an untrusted network without separately designed access protection and deployment review. This project is not presented as production-ready.

## Contents

- [Features](#features)
- [Technology and requirements](#technology-and-requirements)
- [Fresh installation](#fresh-installation)
- [Local development](#local-development)
- [Data relationships and retention](#data-relationships-and-retention)
- [Order lifecycle and inventory](#order-lifecycle-and-inventory)
- [Reports and CSV exports](#reports-and-csv-exports)
- [Testing and database safety](#testing-and-database-safety)
- [Project structure](#project-structure)
- [Troubleshooting](#troubleshooting)
- [Known limitations](#known-limitations)
- [Version 1.0.0 release checklist](#version-100-release-checklist)
- [License](#license)

## Features

### Product Information

- Create, view, edit, and delete products; search by name or category.
- Listing of 15 products per page, with search retained during pagination.
- Name, optional description, string category, decimal price, available stock, optional image, and optional supplier.
- Description limit of 10,000 characters, server-side validation, and field feedback.
- JPEG, PNG, or WebP uploads up to 2 MB, stored with generated filenames.
- Stock-change checks reject outdated stock edits; products referenced by order items cannot be deleted.

### Customer Profile

- Create, view, edit, and soft-delete profiles; search by name, email, or phone.
- Required name, email, and phone; optional address; 15 customers per page.
- Normalized email addresses remain unique, including soft-deleted profiles.
- Registration/update timestamps and retained relationships for order history.

### Customer Order

- Orders for current customers with multiple distinct products.
- Searchable, paginated history with order-status and payment-status filters.
- Historical customer/product snapshots and server-calculated totals.
- Inventory reservation, controlled transitions, eligible cancellation, duplicate-submission protection, and stale-update detection.
- No physical order-deletion endpoint.

### Supplier Profile

- Create, view, edit, and soft-delete suppliers with Active/Inactive status.
- Search by supplier name, contact person, email, or phone; 15 suppliers per page.
- Normalized unique emails, including deleted suppliers.
- Details include associated product count and a paginated product listing.
- Deleted suppliers retain a read-only detail page; edit, update, and delete routes exclude them.

### Supplier–Product Integration

- One supplier can supply many products; each product has zero or one supplier.
- New assignments accept only Active, non-deleted suppliers, rechecked inside the saving transaction.
- Existing Inactive or Deleted assignments are retained unless explicitly changed.
- Omitted supplier input preserves the association; an explicit blank clears it.
- Product listings/details show supplier names and statuses, or Unassigned.
- Supplier soft deletion preserves assignments and does not change stock. Foreign keys restrict physical deletion of referenced suppliers.

### Dashboard and Reports

- `/` redirects to `/dashboard`, which reuses the report controller, service, and view.
- Summary cards for paid revenue, outstanding value, orders/status counts, products, customers, and suppliers.
- A 30-day revenue trend, top-five products, and navigation to all modules.
- `/reports` remains the report overview, with Sales, Product, Customer, and Supplier reports.
- Pagination and native streamed CSV exports for all four detailed reports.
- Shared Tailwind layout, responsive tables, dark mode, alerts, validation feedback, and confirmation controls.

## Technology and requirements

Versions reflect inspected lockfiles and the previously verified environment, not a guarantee for every version allowed by dependency constraints.

| Component | Verified version / requirement |
| --- | --- |
| PHP | 8.4.27 tested; Composer constraint is `^8.3` |
| Laravel / Eloquent / Blade | 13.40.0 |
| MariaDB | 10.11.16 verified through Laravel's `mysql` connection |
| Tailwind CSS and Vite integration | 4.3.5 |
| Vite | 8.3.6 |
| Laravel Vite plugin | 3.2.1 |
| PHPUnit | 12.5.43 |
| Laravel Boost | 2.10.4, development dependency |
| Laravel Pint | 1.32.1, development dependency |
| Composer | 2.11.1 used in verification |
| Node.js / npm | 24.22.2 / 11.19.1 used in verification |

The locked Vite packages require Node.js `^20.19.0 || >=22.12.0`. PHP 8.4 is the verified PHP target.

Install PHP extensions required by the locked Composer dependencies. Relevant extensions include PDO/MySQL for MariaDB, PDO/SQLite for default tests, XML/DOM, fileinfo, and multibyte string support. Run `composer check-platform-reqs` after dependency installation to check the local platform.

Concurrency verification requires `proc_open`, separate PHP workers, and access to MariaDB InnoDB lock metadata. The project does not use Bootstrap, jQuery, a charting package, or a PDF package.

## Fresh installation

Examples use PowerShell from the project root. Use equivalent syntax on other shells.

### 1. Install dependencies and create the environment

Clone or download the repository, enter its directory, then install the locked dependencies:

```powershell
composer install
npm ci
```

For a fresh installation only:

```powershell
Copy-Item .env.example .env
php artisan key:generate
```

Do not overwrite an existing `.env` or regenerate an established installation's application key. Dependency updates are separate from installation. `.env` and `.env.testing` are ignored by Git; never commit credentials or keys.

### 2. Configure MariaDB

Create an empty development database, for example `gift`, using your database administration tool. Configure `.env` using your own local credentials. These are placeholders, not supplied credentials:

```dotenv
APP_NAME="Gift Shop Management System"
APP_ENV=local
APP_URL=http://localhost:8000
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gift
DB_USERNAME="<your-development-database-user>"
DB_PASSWORD="<your-development-database-password>"
DB_URL=
```

Replace the credential placeholders locally and retain the generated `APP_KEY`. Use `mysql` for the documented MariaDB setup.

**The committed `.env.example` defaults to SQLite.** Explicitly configure MariaDB before initializing the development schema. The example also uses database-backed sessions, cache, and queues; their framework tables are included in the migrations.

### 3. Initialize schema, image storage, and assets

First verify the selected development database. The following commands change the configured database or filesystem and are intended only for an approved fresh setup:

```powershell
php artisan migrate
php artisan storage:link
npm run build
```

No seeding is required. The existing `DatabaseSeeder` creates a framework test user, not shop demonstration records or a login feature.

Do not use `migrate:fresh`, `db:wipe`, or reset commands on an existing shop database. Review later migrations and back up the database and uploaded files before upgrading an established installation.

The existing `composer setup` script generates an application key and runs `migrate --force`, among other actions. Do not use it as an unattended setup shortcut on an established installation.

Product images are stored under `storage/app/public/products`; the storage link exposes them through `public/storage`. Keep uploaded files and database backups together. `APP_URL` should match the local application address used for generated image URLs.

## Local development

Use separate terminals:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

```powershell
npm run dev
```

Alternatively, run `npm run build` and serve compiled assets without a running Vite server. For Apache/XAMPP, set the document root to the project's **`public/` directory**, not the repository root. Keep application and asset servers restricted to the trusted local environment.

| Path | Purpose |
| --- | --- |
| `/` | Redirect to Dashboard |
| `/dashboard` | Dashboard homepage |
| `/products`, `/customers`, `/orders`, `/suppliers` | Module listings and management navigation |
| `/reports` | Reports overview |
| `/reports/sales`, `/reports/products` | Date-filtered reports |
| `/reports/customers`, `/reports/suppliers` | All-time/current-profile reports |
| Each detailed report path plus `/export` | Corresponding CSV export |

## Data relationships and retention

| Relationship | Behavior |
| --- | --- |
| Customer → Orders | One-to-many; order customer relationship includes soft-deleted profiles |
| Order → Order Items | One-to-many; one line per product per order |
| Product → Order Items | One-to-many; referenced products cannot be physically deleted |
| Supplier → Products | One-to-many through nullable `products.supplier_id` |
| Product → Supplier | Optional belongs-to relationship, including deleted suppliers |

Orders retain customer name, email, phone, and address snapshots. Items retain product name, unit price, quantity, and subtotal. Current profile/product edits do not rewrite these historical snapshots.

Customers and suppliers use soft deletion; their email uniqueness constraints include deleted rows. Restrictive foreign keys preserve referenced records. The supplier foreign key was introduced by a separate additive migration, allowing existing products to remain unassigned.

## Order lifecycle and inventory

Order statuses are **Pending, Processing, Completed, and Cancelled**. Payment statuses are **Unpaid and Paid**.

| Operation | Rules |
| --- | --- |
| Create | Current customer, at least one distinct product; initial status Pending or Processing |
| Advance | Pending → Processing → Completed; unchanged non-terminal status is also allowed |
| Complete | Requires Paid payment status |
| Edit date/items | Only Pending and Unpaid; customer cannot be reassigned |
| Mark paid | Paid cannot subsequently become Unpaid |
| Cancel | Only unpaid Pending or Processing orders |
| Completed/Cancelled | Read-only; repeated cancellation does not restock again |

- Creation reserves stock immediately, including for unpaid orders.
- Editable item changes adjust reservation differences and restore removed quantities.
- Existing lines retain historical unit prices; newly added products use current prices.
- Eligible cancellation restores reserved quantities exactly once.
- The server calculates money using integer cents with overflow checks and decimal database columns. Browser totals are provisional.
- Transactions and consistent product locking protect inventory operations. Creation tokens prevent duplicate orders; version checks reject stale updates.
- Product stock editing checks the original displayed stock value before saving.
- No physical order-deletion route exists.

## Reports and CSV exports

### Calculation basis

| Metric | Definition |
| --- | --- |
| Paid revenue | Totals from Paid, non-Cancelled orders |
| Outstanding value | Totals from Unpaid, non-Cancelled orders |
| Product quantity | Non-Cancelled item quantities, including unpaid reservations |
| Product order value | Historical item subtotals from non-Cancelled orders |
| Product paid revenue/outstanding | Historical subtotals split by current order payment status |
| Customer paid spending | Paid, non-Cancelled order totals |
| Customer activity | Order count and latest order date include all statuses, including Cancelled |
| Supplier summary | Current, non-deleted suppliers split into Active and Inactive |

Pending/Processing orders contribute paid revenue when Paid, or outstanding value when Unpaid. Completed orders must be Paid through the application lifecycle. Cancelled orders contribute neither financial values nor product quantities/order value, but may remain visible in report rows and order counts.

**Dates use order dates and current payment statuses, not actual payment receipt dates.** Historical periods can change when orders are edited, paid, or cancelled. Reports are operational summaries, not immutable accounting ledgers.

Sales and Product reports default to month-to-date when neither date is supplied. Filters require both dates, use inclusive boundaries, reject reversed ranges, and reject end dates after today. Sales also supports order/payment status filters. Customer and Supplier reports do not have date filters.

The dashboard uses all-time financial/status totals and current profile counts. Its trend covers the last 30 days. Top products are ranked by all-time paid revenue and require non-cancelled item activity; unpaid/free activity can qualify with zero paid revenue.

Product names, prices, and stock in Product reports are current; monetary performance uses historical subtotals. Customer reports retain deleted profiles and show current/retained details; Sales rows show customer snapshots. Order totals are aggregated separately from item totals to avoid duplicate revenue through joins.

Stock indicators: **Out of Stock = 0**, **Low Stock = 1–5**, **In Stock = 6 or more**. Product quantities include reservations and do not necessarily represent fulfilled units. Application date defaults and validation currently use **UTC**.

### CSV exports

All four detailed reports export CSV. Sales/Product exports use the same validated filters as their screens. Exports include all matching rows, not only the displayed page.

Exports use native Laravel/PHP streaming, batched retrieval, CSV escaping, and spreadsheet formula-prefix protection. Supplier exports contain profile/contact/status details, not supplier purchasing or financial performance. No PDF export is implemented.

## Testing and database safety

### Default isolated SQLite suite

```powershell
php vendor/bin/phpunit --no-progress --do-not-cache-result
```

The default `phpunit.xml` selects Unit/Feature tests and forces `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, and an empty `DB_URL`. Database-backed feature tests verify isolation. This suite does not select `tests/MariaDb`.

Do not point the default configuration at MariaDB. If cached Laravel configuration prevents isolation, stop and review/clear it locally before retrying; never weaken the guards. `composer test` also exists, but clears Laravel configuration before running tests.

Database-free safety coverage can be selected independently:

```powershell
php vendor/bin/phpunit tests/Unit/MariaDbSafetyTest.php --do-not-cache-result
```

### Separate MariaDB environment

> **MariaDB suites write data.** Use only a dedicated local database named exactly `gift_testing`, never `gift`, a shared database, or copied shop data. Obtain explicit approval before schema initialization or data-changing tests. Never bypass a guard or reset a database merely to make tests pass.

Create an empty `gift_testing` database manually. Create the ignored `.env.testing` file locally; it is not distributed. Use locally supplied testing credentials and an independent testing application key:

```dotenv
APP_ENV=testing
APP_DEBUG=false
APP_KEY=
APP_MAINTENANCE_DRIVER=file
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gift_testing
DB_USERNAME="<your-testing-database-user>"
DB_PASSWORD="<your-testing-database-password>"
DB_URL=
CACHE_STORE=array
SESSION_DRIVER=array
QUEUE_CONNECTION=sync
MAIL_MAILER=array
BROADCAST_CONNECTION=null
```

After creating the file and ensuring conflicting process-level environment overrides are absent, generate its key:

```powershell
php artisan key:generate --env=testing
```

This targets `.env.testing`, not a schema migration. Never commit private environment files.

`tests/MariaDb/Safety.php` rejects cached/redirected configuration and conflicting process variables. It restricts connections to local `mysql` and exactly `gift_testing`, rejects URL/read/write/socket/prefix overrides, and verifies server-selected database, MariaDB identity, and foreign-key checks. Other configured connections are removed from the integration application's configuration. Per-test verification precedes transactions/fixtures; workers verify independently.

The PHP guard verifies MariaDB identity but does not hard-code version 10.11.16. The PowerShell helper below additionally requires that previously verified server version for these documented procedures.

Start with SELECT-only preflight:

```powershell
$env:MARIADB_ALLOW_WRITES = '0'
$env:MARIADB_ALLOW_CONCURRENCY = '0'
php tests/MariaDb/preflight.php
```

Define this helper in the same PowerShell session for the following examples:

```powershell
function Assert-GiftTesting {
    $output = php tests/MariaDb/preflight.php
    if ($LASTEXITCODE -ne 0) { throw 'MariaDB preflight failed.' }
    $target = ($output -join "`n") | ConvertFrom-Json
    if ($target.connection -cne 'mysql' -or
        $target.database -cne 'gift_testing' -or
        $target.server_version -cne '10.11.16-MariaDB' -or
        -not $target.foreign_keys) {
        throw 'Unapproved integration database identity.'
    }
    return $target
}
```

#### Initialize the empty test schema once, only after approval

```powershell
$env:MARIADB_ALLOW_WRITES = '0'
$env:MARIADB_ALLOW_CONCURRENCY = '0'
try {
    $target = Assert-GiftTesting
    if ($target.table_count -ne 0) { throw 'Integration database is not empty.' }
    $env:MARIADB_ALLOW_WRITES = '1'
    php tests/MariaDb/migrate.php
    if ($LASTEXITCODE -ne 0) { throw 'Guarded migration failed.' }
} finally {
    $env:MARIADB_ALLOW_WRITES = '0'
    $env:MARIADB_ALLOW_CONCURRENCY = '0'
}
```

The entry point independently rechecks the target and refuses a non-empty database. It performs no resets or seeding. After initialization, do not substitute an unguarded migration command or wipe the schema. Future integration-schema changes require a separately reviewed procedure.

#### Sequential integrity tests, only after approval

```powershell
$env:MARIADB_ALLOW_WRITES = '0'
$env:MARIADB_ALLOW_CONCURRENCY = '0'
$previousPaoDisable = [Environment]::GetEnvironmentVariable('PAO_DISABLE', 'Process')
try {
    $null = Assert-GiftTesting
    $env:MARIADB_ALLOW_WRITES = '1'
    $env:PAO_DISABLE = '1'
    php vendor/bin/phpunit -c phpunit.mariadb.xml --testsuite MariaDB --display-all-issues --fail-on-risky
    if ($LASTEXITCODE -ne 0) { throw 'MariaDB integrity tests failed.' }
} finally {
    $env:MARIADB_ALLOW_WRITES = '0'
    $env:MARIADB_ALLOW_CONCURRENCY = '0'
    [Environment]::SetEnvironmentVariable('PAO_DISABLE', $previousPaoDisable, 'Process')
}
```

Integrity tests use transactions/rollback and do not migrate automatically. Coverage includes InnoDB/foreign keys, unique constraints and soft deletion, supplier associations, inventory, duplicate submissions, stale updates, rollback, snapshots, and decimal reporting.

#### Concurrency tests, only after separate approval

```powershell
$env:MARIADB_ALLOW_WRITES = '0'
$env:MARIADB_ALLOW_CONCURRENCY = '0'
$previousPaoDisable = [Environment]::GetEnvironmentVariable('PAO_DISABLE', 'Process')
try {
    $null = Assert-GiftTesting
    $env:MARIADB_ALLOW_WRITES = '1'
    $env:MARIADB_ALLOW_CONCURRENCY = '1'
    $env:PAO_DISABLE = '1'
    php vendor/bin/phpunit -c phpunit.mariadb.xml --testsuite MariaDBConcurrency --display-all-issues --fail-on-risky
    if ($LASTEXITCODE -ne 0) { throw 'MariaDB concurrency tests failed.' }
} finally {
    $env:MARIADB_ALLOW_WRITES = '0'
    $env:MARIADB_ALLOW_CONCURRENCY = '0'
    [Environment]::SetEnvironmentVariable('PAO_DISABLE', $previousPaoDisable, 'Process')
}
```

Concurrency tests require empty `products`, `customers`, `suppliers`, `orders`, and `order_items` tables. Real separate PHP processes exercise competing orders, duplicate submissions, deadlock/retry, and cancellation races. Cancellation requires both workers' actual row-lock waits to appear in InnoDB metadata before releasing the parent lock. Missing metadata permissions, unverified contention, and worker timeouts fail explicitly.

Concurrency fixtures are committed for worker visibility. Teardown terminates workers, releases parent transactions, and removes only tracked fixtures; it does not truncate/reset tables. After every run, verify zero records in the five fixture tables and both flags at `0`. Preflight's table count counts schema objects, **not fixture records**. If cleanup fails, stop and investigate instead of automatically wiping the database.

Do not run these suites in parallel against the same database. No MariaDB suite automatically refreshes its schema. `migrate:fresh`, `db:wipe`, seeders, development-data imports, and testing against `gift` are outside this procedure.

### Previously verified results

These results come from earlier development/review sessions. **No tests, migrations, or builds were executed while writing this README.** They do not replace validation against the final release candidate.

| Verification | Previously recorded result |
| --- | --- |
| Default SQLite/unit suite | 309 tests, 1,706 assertions |
| MariaDB integrity | 10 tests, 43 assertions, zero risky warnings after bootstrap fix |
| Focused concurrent cancellation | 1 test, 31 assertions |
| Full MariaDB concurrency | 4 tests, 56 assertions, zero risky warnings |

Controlled concurrency scenarios confirmed no overselling, one deduction for duplicate submissions, successful deadlock retry, and one restoration for competing/repeated cancellation. They do not prove correctness under every workload or failure mode.

PHP syntax, Pint, whitespace checks, and Vite builds also passed during earlier development. Future checks include `php vendor/bin/pint --dirty --format agent`, `git diff --check`, and `npm run build`; Pint changes formatting.

## Project structure

```text
app/
  Http/Controllers/          Module, cancellation, dashboard/report endpoints
  Http/Requests/             Validation, normalization, report filters
  Models/                   Products, profiles, orders, items, relationships
  Services/OrderService.php  Order transitions, reservations, cancellation
  Services/ReportService.php Aggregation, dashboard data, stock labels
database/
  migrations/               Framework and application schema history
  factories/                Test model factories
resources/
  css/app.css               Tailwind CSS 4 entry point
  js/orders.js              Dynamic rows and provisional order totals
  views/                    Blade pages, layout, reusable components
routes/web.php              Resource routes, dashboard, reports, exports
tests/
  Feature/                  Default isolated SQLite feature coverage
  Unit/                     Unit and connection-free safety tests
  MariaDb/                  Guarded integrity suite, entry points, workers
    Concurrency/            Separate-process concurrency tests
phpunit.xml                 Default SQLite test configuration
phpunit.mariadb.xml         Explicit MariaDB test suites
vite.config.js              Laravel Vite, Tailwind, font configuration
```

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Missing Vite manifest or styling | Install frontend dependencies; use `npm run dev` or `npm run build` |
| Images do not load | Check `storage:link`, files, filesystem permissions, and local `APP_URL` |
| MariaDB connection failure | Check server, PDO MySQL support, database name, and local credentials without sharing secrets |
| SQLite isolation guard fails | Check PDO SQLite, PHPUnit overrides, and cached configuration; do not weaken the guard |
| MariaDB guard refuses execution | Check `.env.testing`, process conflicts, cached configuration, exact target, and required flags |
| Guarded migration refuses existing schema | Intentional protection; do not reset/wipe to bypass it |
| Cancellation contention cannot be verified | Check access to `information_schema.INNODB_LOCK_WAITS`, `INNODB_TRX`, `INNODB_LOCKS`, and worker timeouts |
| Condensed output hides warning details | Temporarily use `PAO_DISABLE=1` with `--display-all-issues`, then restore its previous value |
| Optional `fontaine` build warning | Existing font optimization can emit this non-blocking warning; package installation requires separate review |
| Font download/build failure | Configured Bunny Instrument Sans fonts may require network access during asset preparation |
| Unexpected date boundaries | Application defaults/validation currently use UTC |

## Known limitations

- No authentication, registration, roles, permissions, or multi-user access model.
- No payment gateway, payment-receipt timestamps, refunds, tax, shipping, or multi-currency workflow.
- No purchase orders, supplier payments, automated replenishment, or multiple suppliers per product.
- No PDF exports, charting package, or public API feature set.
- No user-facing soft-delete restoration or force-deletion management workflow.
- No bundled shop demonstration dataset; factories are intended for tests.
- Automated coverage does not replace manual browser, keyboard, screen-reader, or deployment verification.

## Version 1.0.0 release checklist

- [ ] Review this README against the final candidate and validate fresh installation separately.
- [x] Verify license consistency: LICENSE contains Apache License 2.0 and composer.json declares Apache-2.0.
- [ ] Review and include the MariaDB harness, configuration, and safety tests in the intended release changes.
- [ ] Confirm private environments, credentials, keys, logs, and local artifacts are excluded from Git.
- [ ] Record final SQLite, integrity, and concurrency results against the exact candidate, with zero failures or risky warnings.
- [ ] Confirm fixture cleanup and disabled MariaDB execution flags.
- [ ] Complete final formatting, whitespace, and frontend build verification.
- [ ] Complete manual module, upload, CSV, responsive-layout, dark-mode, and accessibility checks.
- [ ] Confirm local-only access expectations and document remaining limitations.
- [ ] Prepare final release notes describing features, setup, verified behavior, and limitations.
- [ ] Obtain separate approval before committing, merging, publishing, or creating a `v1.0.0` tag.

Unchecked steps are not claimed complete.

## License

The Gift Shop Management System is licensed under **Apache License 2.0**. See [LICENSE](LICENSE) for the full license text. The root [composer.json](composer.json) consistently declares `Apache-2.0`. Dependency licenses are separate from the project's own license.
