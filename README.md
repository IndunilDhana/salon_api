# Salon Workspace API

Laravel 12 / PHP 8.2 API for a salon SaaS, paired with the adjacent `Haircut_frontend` Next.js project. The runtime database is **MySQL**, with `haircut_master` as the central database. The installed PHP version is 8.2, so this starter uses Laravel 12; upgrade PHP before moving to Laravel 13.

## Run locally

Prerequisites: PHP 8.2+ with PDO MySQL, Composer, and a running MySQL-compatible server.

```powershell
composer install
Copy-Item .env.example .env  # only when .env does not exist
php artisan key:generate
```

Set the private database credentials in the ignored `.env` file. Defaults in `.env.example` are `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=haircut_master`, `DB_USERNAME=root`, and `utf8mb4_unicode_ci` through Laravel's MySQL configuration. Supply the password locally; never commit it.

Create `haircut_master` with a database user that can run migrations (Laravel can create a missing database when that account has permission), then:

```powershell
php artisan migrate --database=central
php artisan db:seed
php artisan serve --host=127.0.0.1 --port=8000
```

The non-destructive demo seeder is restricted to `local` and `testing` environments. It creates two salons (`studio-one` and `the-cutting-room`), team members, today's appointments, services/products, and these local accounts:

| Account | Email | Password |
| --- | --- | --- |
| Business owner | demo@salon.test | password |
| Platform admin | admin@salon.test | password |

The platform admin account has no implicit access to salon operations. Business access always needs a membership. Use real credentials and an explicit administrative provisioning process outside local development; the demo seeder refuses production.

## Structure

```text
app/Access/                 Permission catalog and built-in role defaults
app/Domain/Orders/          Transactional bill totals, snapshots, and payment transitions
app/Http/Controllers/       Separate auth, platform, access, catalog, order, staff, appointment, payroll controllers
app/Http/Middleware/        Tenant resolution, permission and platform-admin enforcement
app/Http/Requests/          Payroll request validation
app/Http/Resources/         Appointment serialization shared by dashboard and booking list
app/Models/                 Explicit central models and tenant-scoped business models
app/Domain/Payroll/         Monthly payroll draft snapshots
app/Tenancy/                Request-scoped tenant context, always cleared after use
database/migrations/        Central and operational schemas with explicit connection boundaries
tests/Feature/              Isolation, role management, payroll, catalog, and billing integration tests
```

`central` and `tenant` are logical database connections. Both initially point at the same MySQL database. Users, salons, roles, memberships, and API tokens remain central. Business tables contain `tenant_id`, use a fail-closed model scope, and have composite foreign keys where they reference another tenant table. There are no business-to-central database foreign keys to prevent a future extraction from depending on cross-database constraints.

Every protected business request validates the authenticated user's membership and current role before resolving operational data. `X-Tenant` selects a salon slug; it is not authorization. Suspended salons are denied. UUID route identifiers and validation are tenant scoped. Dedicated database provisioning/routing is a documented future phase; the starter rejects an unprovisioned storage mode rather than silently reading the shared database.

## API

All routes use `/api/v1`. Send `Accept: application/json`. Business routes also require a Sanctum bearer token and `X-Tenant: studio-one` (or another authorized slug). The Next.js server bridge keeps the token in an HttpOnly cookie instead of browser storage.

| Routes | Purpose |
| --- | --- |
| `POST register`, `POST login`, `GET me`, `POST logout` | Owner/salon registration, sign-in, membership discovery, token revocation |
| `GET admin/dashboard`, `PATCH admin/tenants/{id}` | Platform overview and active/suspended business status |
| `GET dashboard` | Salon-local daily appointment and completed-service metrics |
| `GET/POST staff`, `PATCH staff/{id}` | Staff compensation and active status |
| `GET/POST appointments`, `PATCH appointments/{id}` | Book services and mark complete/cancelled |
| `GET/POST payroll` | Immutable monthly draft snapshots |
| `GET access`, `POST/PATCH/DELETE access/roles/{id?}`, `POST/PATCH/DELETE access/members/{id?}` | Salon-scoped custom roles and membership management |
| `GET/POST catalog`, `PATCH catalog/{id}` | Service/product catalog and prices |
| `GET/POST orders`, `GET/PATCH orders/{id}` | Customer bill line snapshots and manually recorded payment status |

Default Owner has every permission and is protected from role/member changes. Manager can manage staff, appointments, catalog, and orders. Staff can view the dashboard, appointments, catalog, and orders. A custom role can be granted only permissions held by the actor. Management permissions require the corresponding view permission; bill management additionally requires catalog viewing. Owner roles and owner memberships cannot be assigned, altered, or removed through the access endpoints; self-access changes are also blocked. Membership creation attaches an existing identity without resetting its password or platform status.

Amounts are integer **minor currency units**; `450000` means LKR 4,500.00. Commission rates are integer basis points; `1250` means 12.5%. The registration currency choices currently use two decimal places. Appointment timestamps must contain a timezone offset or `Z`; they are stored in UTC and shown in the salon timezone.

## Payroll and billing limits

Payroll is a **draft calculator**, not statutory payroll. One full calendar month is supported. Each currently active staff member receives their full configured monthly base; an inactive member receives only completed appointment commission. There is no employment-date salary proration. Commission uses the rate snapshotted when an appointment was created, rounds each appointment to the nearest minor unit, and includes completed appointments whose start time falls in the salon-local month. Salary and staff name are snapshotted at generation. Duplicate/overlapping monthly drafts are blocked; there is no regeneration, approval, payslip, tax, pension, leave, advance, or disbursement workflow. Later completed bookings do not modify an existing snapshot. Allowance/deduction fields remain zero in this starter.

Customer bills take service/product IDs and quantities. Laravel loads current active catalog prices and snapshots line names/types/prices and currency; clients cannot supply totals. Unpaid bills can be marked paid by cash/card/other. This records a status; it does not charge a card or contact a payment gateway. Paid bills and historical lines are immutable. Tax, discounts, refunds, stock tracking, fiscal receipts, idempotent bill creation, and accounting integrations need subsequent work. Bills and appointments are separate records; creating a bill does not alter commission or payroll. Dashboard completed-service revenue is an appointment metric rather than collected cash.

## Verify

```powershell
php artisan test --compact
php vendor/laravel/pint/builds/pint --test
```

Automated feature tests allocate and delete an isolated temporary SQLite file per test. They never reset `haircut_master`. MySQL migrations and live API behavior should also be checked whenever schema/database behavior changes.

The adjacent frontend's `docs/architecture.md` contains the phased roadmap, including production account onboarding, audit trails, dedicated-database cutover, job/file/cache tenant isolation, and remaining payroll decisions.
