# ADR 0001: SQLite for dev, PostgreSQL for prod

## Status
Accepted (Auth & Roles slice, PLAN_AUTH_ROLES.md §0).

## Context
The Devon product requirement is PostgreSQL (see `frontend/CLAUDE.md` / `docs/`
for the on-premise deployment model). This backend slice, however, is scoped
to building and iterating on the Auth & Roles module quickly, locally, with
zero infrastructure setup (no Postgres server/container required to run
`php artisan test` or `php artisan serve`).

## Decision
- **Local development and the Pest test suite** run against **SQLite**
  (`database/database.sqlite` for the app, `:memory:` for tests — see
  `phpunit.xml`). This needs no external services and keeps the feedback
  loop fast.
- **Production** targets **PostgreSQL**. A `docker-compose.yml` is provided
  at the repo root of `backend/` to run a local Postgres instance that
  mirrors the intended prod engine.

## Consequences
- Migrations avoid engine-specific SQL so they run unchanged on both
  SQLite and PostgreSQL (plain Eloquent schema builder calls only — no raw
  `DB::statement` with Postgres-only syntax).
- `uuid` columns use Laravel's portable `$table->uuid()` column type, which
  maps to `TEXT`/`CHAR(36)` equivalents that behave consistently across
  both engines for our purposes (equality lookups, no engine-specific uuid
  functions relied upon).
- The `status` enum column uses Laravel's `$table->enum()` helper, which is
  portable across SQLite and PostgreSQL.
- Before a real production deploy, run `php artisan migrate` against a
  Postgres database (e.g. via `docker-compose up -d postgres` +
  `DB_CONNECTION=pgsql`) and re-run the full test suite against it once to
  confirm no SQLite-only behavior slipped in (e.g. loose type affinity).
  This wasn't required for the Auth & Roles slice itself, but should happen
  before the next module ships against Postgres for real.

## Alternatives considered
- **Running Postgres via Docker for local dev too** — rejected for this
  slice: adds a hard Docker dependency to `php artisan test`, which the
  plan explicitly asked to avoid (§0: "SQLite, nol-infratuzilma, tez").
  `docker-compose.yml` is still provided so this is a one-command opt-in,
  not a rewrite, whenever the team wants to validate against Postgres.
