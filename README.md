# Devon — backend (Auth & Roles slice)

Laravel 11 + Sanctum + `spatie/laravel-permission`. Scope: authentication,
the 7 Devon roles, and admin user management **only** — see
`../PLAN_AUTH_ROLES.md` for the full spec and non-goals. Every other module
(Employee, Unit, ERI, Document, Task, Letter) stays on the frontend's
localStorage mock backend for this slice.

Endpoint contract + sample requests/responses: **[`AUTH_API.md`](./AUTH_API.md)**.
Dev-DB-vs-prod-DB rationale: **[`docs/adr/0001-sqlite-dev-postgresql-prod.md`](./docs/adr/0001-sqlite-dev-postgresql-prod.md)**.

## Requirements

- PHP ^8.2 (developed/tested against 8.3)
- Composer 2.x
- SQLite (bundled with PHP — no server to install) for dev/test
- Docker, only if you want to try the Postgres path (optional, see ADR)

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # composer create-project already does this on first install
php artisan migrate --seed
```

`migrate --seed` runs, in order: `RolesSeeder` (7 roles) →
`PermissionsSeeder` (5 permissions, assigned to `ROLE_SUPER_ADMIN` /
`ROLE_HR_ADMIN`) → `AdminUserSeeder` (bootstrap admin).

Seeded login (matches the frontend `LoginPage` demo hint exactly):

```
admin@devon.uz / Demo2026!
```

## Run

```bash
php artisan serve   # http://localhost:8000
```

CORS is open to `http://localhost:5173` (the Vite dev server) — see
`config/cors.php` / `FRONTEND_URL` in `.env`.

Auth is **token-based** (`Authorization: Bearer <token>`), not
cookie-based SPA auth — the frontend is a separate origin.

## Tests

```bash
php artisan test
# or
./vendor/bin/pest
```

Pest, `RefreshDatabase`, SQLite `:memory:` (configured in `phpunit.xml`) —
no setup needed beyond `composer install`. 20 feature tests cover login
(success/401/suspended), `/auth/me` (token required, flat response shape),
logout (token revocation), change-password (422 on wrong current password,
weak new password, and the happy path), `/roles` (all 7 codes), and user
management RBAC (`ROLE_EMPLOYEE` blocked with 403, `ROLE_SUPER_ADMIN` /
`ROLE_HR_ADMIN` allowed with 201, duplicate email 422, `tempPassword`
generation, PATCH, reset-password).

## Switching SQLite → PostgreSQL

See the ADR linked above for the reasoning. To try Postgres locally:

```bash
docker compose up -d
```

Then in `.env`:

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=devon
DB_USERNAME=devon
DB_PASSWORD=devon
```

```bash
php artisan migrate --seed
```

No migration changes are needed — the schema was written to be portable
(no Postgres- or SQLite-only SQL).

## Project structure (this slice)

```
app/Http/Controllers/Api/   AuthController, RoleController, UserController
app/Http/Requests/          LoginRequest, ChangePasswordRequest,
                             StoreUserRequest, UpdateUserRequest
app/Http/Resources/         UserResource (the SessionUser-shaped envelope)
app/Models/User.php         HasApiTokens, HasRoles, HasUuids
app/Support/PasswordGenerator.php   secure temp-password generation
config/devon_roles.php      role code → nameUz catalogue (single source
                             of truth for RolesSeeder + GET /roles)
database/migrations/        users table (uuid, full_name, must_change_password,
                             password_changed_at, status, employee_uuid) +
                             spatie permission tables + sanctum tokens table
database/seeders/           RolesSeeder, PermissionsSeeder, AdminUserSeeder
routes/api.php               /api/v1/* — all 9 endpoints
tests/Feature/               Pest feature tests (see above)
docs/adr/                    architecture decision records
```
