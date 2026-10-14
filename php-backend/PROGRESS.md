# AksaraNetra PHP Backend Migration Progress

Tracking progress across the 7 phases of the PHP/Laravel backend port.

---

## Phase 0: Read-Only Analysis

- **Status**: Completed
- **Date**: 2026-10-14
- **Branch**: `php-backend`

### What Was Done
1. Initialized local git repository on base branch `Update-UI/UX`, made initial commit of existing repository state, and branched off to work branch `php-backend`.
2. Created the target directory `php-backend/`.
3. Conducted a comprehensive read-only analysis of all reference codebase files:
   - Node backend core: `backend/src/app.mjs`, `security.mjs`, `config.mjs`, `store.mjs`, `worker.mjs`, `auditor.mjs`, `report.mjs`, `server.mjs`.
   - Node backend test specs: `backend/tests/api.test.mjs`, `store.test.mjs`, `worker.test.mjs`.
   - Engine API and policy modules: `engine/src/api/index.mjs`, `engine/src/fetcher/url-policy.mjs`, `engine/src/runner/index.mjs`, `engine/src/snapshot/index.mjs`.
   - Frontend API client & contract: `src/lib/audit-api.ts`, `src/app/result/page.tsx`, `tests/frontend-contract.test.mjs`.
   - Infrastructure & deployment config: `backend/Dockerfile`, `docker-compose.yml`, `render.yaml`, `.env.example`, `backend/.env.example`.
4. Verified baseline test suites:
   - Ran `npm --prefix backend test` (all 10 tests passed).
   - Ran `npm run test:frontend` (all 5 contract tests passed).
5. Authored comprehensive architectural analysis document `php-backend/ANALYSIS.md` covering all 8 requested areas.
6. Authored `php-backend/NOTES.md` documenting specific quirks and preservation rules.

### Files Created / Changed
- `php-backend/ANALYSIS.md` (Created): Full endpoint contract table, SQLite DB schema & queries, worker state machine, env var specs, engine bridge functions, frontend expectations, and documented quirks.
- `php-backend/NOTES.md` (Created): Quirks, edge cases, and behavior preservation rules.
- `php-backend/PROGRESS.md` (Created): Progress tracking and phase handoff log.

### How to Run Tests / Commands for this Phase
- Verify backend tests:
  ```bash
  npm --prefix backend test
  ```
- Verify frontend contract tests:
  ```bash
  npm run test:frontend
  ```

### Decisions Made
1. **Repository Setup**: Initialized Git repository on `Update-UI/UX` with clean tracking, then created branch `php-backend` exclusively for this work.
2. **Table Naming**: The SQLite table will be named `audit_jobs` rather than `jobs` to avoid conflict with Laravel's built-in queue system, while mirroring the exact columns and integer millisecond timestamps.
3. **Quirk Preservation**: All quirks identified in `backend/` (such as `reportPdf` link pointing to `report.pdf` even when only `report.html` was generated, and strict query parameter authentication for artifact downloads) are recorded in `php-backend/NOTES.md` and will be preserved verbatim.
4. **Zero Mutation Constraint**: No files outside `php-backend/` were modified.

### Deviations from Plan
- None.

### Open Problems
- None. All specifications and behaviors are fully accounted for and traceable to reference code.

### Instructions for Next Phase (Phase 1)
- Formulate the detailed implementation plan in `php-backend/PLAN.md` covering the folder structure, class responsibilities, Composer packages, test strategy, SQLite multi-process safety, and risk mitigations.

---

## Phase 1: Plan

- **Status**: Completed (Awaiting User Plan Approval)
- **Date**: 2026-10-14
- **Branch**: `php-backend`

### What Was Done
1. Confirmed git tracking on branch `php-backend` and verified clean working tree.
2. Ran and validated baseline test suites:
   - `npm --prefix backend test` (10/10 passed)
   - `npm run test:frontend` (5/5 passed)
3. Re-read and analyzed all core Node backend files (`app.mjs`, `security.mjs`, `config.mjs`, `store.mjs`, `worker.mjs`, `auditor.mjs`, `report.mjs`, `server.mjs`, test suites).
4. Authored comprehensive implementation plan in `php-backend/PLAN.md` addressing all 6 required areas:
   - Final folder structure of `php-backend/` (Laravel app directories, bridge, commands, tests, Docker config).
   - Detailed class and file responsibility list with one line per responsibility.
   - Pinned Composer packages (`laravel/framework: ^11.0`, `symfony/process: ^7.0`, `phpunit/phpunit: ^11.0`) with explicit justifications.
   - Comprehensive test strategy mapping all Node tests to PHP tests, specifying `FakeAuditor` for Chromium-free test execution, and defining Phase 7 parity checks.
   - Multi-process SQLite safety specification utilizing WAL mode, 5000ms busy timeout, and `BEGIN IMMEDIATE` atomic claiming in `claimNext()`.
   - Identification of risks and open questions (route prefix, millisecond timestamps, HTML report escaping, process signaling, memory/body size enforcement) with concrete solutions.

### Files Created / Changed
- `php-backend/PLAN.md` (Created): Complete implementation plan for the PHP/Laravel backend.
- `php-backend/PROGRESS.md` (Updated): Recorded Phase 1 completion and handoff.

### How to Run Tests / Commands for this Phase
- Verify backend tests:
  ```bash
  npm --prefix backend test
  ```
- Verify frontend contract tests:
  ```bash
  npm run test:frontend
  ```

### Decisions Made
1. **Laravel Version**: Pinned to Laravel 11 (`^11.0`) on PHP 8.3 in API-only mode (no Blade, no Vite, no Sanctum, no standard CORS package).
2. **Empty Route Prefix**: Routes configured without `/api` prefix to ensure drop-in parity with Next.js frontend calls.
3. **Chromium-Free Testing**: Introduced `AuditorInterface` with an in-memory `FakeAuditor` to keep unit/feature test runs fast and independent of Chromium.
4. **SQLite Concurrency**: Enforced `PRAGMA journal_mode=WAL;`, `PRAGMA busy_timeout=5000;`, and `BEGIN IMMEDIATE;` for `claimNext()` to safeguard against race conditions between HTTP API and worker processes.
5. **Byte-Identical HTML**: `ReportRenderer` ports the exact regex replacements from `report.mjs` to ensure byte-level compatibility.

### Deviations from Plan
- None. No application code was written, strictly following Phase 1 constraints.

### Open Problems
- None. Awaiting user review and approval of `php-backend/PLAN.md`.

### Instructions for Next Phase (Phase 2)
- Start Phase 2 in a NEW conversation using its prompt file.
- Phase 2 will scaffold the Laravel 11 application in `php-backend/`, implement `AksaraConfig` and `SecurityService`, create the database migration for `audit_jobs`, and write unit tests for config and security.

---

## Phase 2: Scaffold, Config, Security, Database

- **Status**: Completed
- **Date**: 2026-10-14
- **Branch**: `php-backend`

### What Was Done
1. Initialized clean Laravel 11 application (`v11.57.0`) in `php-backend/` in API-only mode (disabled Blade, Vite, sessions, and cookies).
2. Configured SQLite with WAL mode (`PRAGMA journal_mode = WAL;`), foreign keys (`PRAGMA foreign_keys = ON;`), and busy timeout (`PRAGMA busy_timeout = 5000;`).
3. Implemented `App\Services\AksaraConfig`:
   - Reads environment variables identically to `backend/src/config.mjs` (`PORT`, `HOST`, `FRONTEND_ORIGINS`, `BACKEND_SECRET`, `BACKEND_DATA_DIR`, `TRUST_PROXY`, `AUDIT_TIMEOUT_MS`, `RESULT_RETENTION_MS`, `AUDIT_RATE_LIMIT`, `MAX_BODY_BYTES`, `CLEANUP_INTERVAL_MS`).
   - Validates integer ranges and enforces Indonesian error messages verbatim (using unicode en-dash `–`).
   - Enforces min 32 characters for `BACKEND_SECRET` in production.
   - Created `php-backend/.env.example` and configured `config/aksaranetra.php`.
4. Implemented `App\Services\SecurityService`:
   - Exact byte-for-byte reproduction of `backend/src/security.mjs`.
   - `randomToken()` generating URL-safe unpadded base64url random bytes.
   - `randomJobId()` generating `aud_` prefixed 28-character identifiers (18 random bytes).
   - `digest()` generating HMAC-SHA256 hex string.
   - `deriveShareToken()` generating HMAC-SHA256 base64url string from `share:{jobId}:{accessToken}`.
   - `safeEqualHex()` executing constant-time hex comparison.
   - `clientIp()` resolving direct remote address or first IP from `x-forwarded-for` under `TRUST_PROXY`.
   - `bearerToken()` extracting Bearer header or falling back to `accessToken` query parameter.
5. Created database migration `database/migrations/2026_10_14_000000_create_audit_jobs_table.php` defining the `audit_jobs` table with identical columns, types, CHECK constraints, and indexes as Node's `jobs` table.
6. Implemented `App\Models\AuditJob` DTO/model and `App\Services\JobStore` repository mirroring all 16 methods of `backend/src/store.mjs`:
   - `createJob`, `getJob`, `countRecentByIp`, `queuePosition`, `findReusable`, `claimNext`, `updateProgress`, `requestCancel`, `complete`, `completeQueued`, `fail`, `cancelRunning`, `retry`, `expired`, `deleteExpired`, and `recoverInterrupted`.
   - Atomic job claiming in `claimNext()` using `BEGIN IMMEDIATE`.
   - Checkpoint restoration in `recoverInterrupted()` (detecting `snapshot.json` and setting progress to 90%).
7. Authored comprehensive test suites:
   - `tests/Unit/SecurityTest.php`: Verified cryptographic helpers against fixed-input test vectors generated directly from Node `security.mjs`.
   - `tests/Unit/ConfigTest.php`: Tested defaults, production secret length enforcement, and Indonesian range error messages.
   - `tests/Feature/StoreTest.php`: Ported tests from `backend/tests/store.test.mjs`, verifying crash recovery, expired artifact deletion, monotonic progress clamping, and full job lifecycles.
   - Ran all tests: 16 tests, 107 assertions, 100% passing.

### Files Created / Changed
- `php-backend/composer.json`: Application dependencies (`laravel/framework: ^11.0`, `symfony/process: ^7.0`, `phpunit/phpunit: ^11.0`).
- `php-backend/composer.lock`: Pinned package lockfile.
- `php-backend/.env.example`: Environment configuration template mirroring `config.mjs`.
- `php-backend/.gitignore`: Ignore patterns for vendor, cache, and runtime artifacts.
- `php-backend/artisan`: Laravel CLI entrypoint.
- `php-backend/bootstrap/app.php`: API-only application configuration with empty route prefix.
- `php-backend/bootstrap/providers.php`: Provider registrations.
- `php-backend/app/Providers/AppServiceProvider.php`: Service container singleton bindings.
- `php-backend/config/app.php`: Application configuration.
- `php-backend/config/database.php`: SQLite connection with WAL, busy timeout, and directory/file auto-initialization.
- `php-backend/config/aksaranetra.php`: AksaraNetra configuration bridge.
- `php-backend/routes/api.php`: API routes file.
- `php-backend/routes/console.php`: Console commands file.
- `php-backend/database/migrations/2026_10_14_000000_create_audit_jobs_table.php`: SQLite schema migration.
- `php-backend/app/Models/AuditJob.php`: Audit job model / DTO.
- `php-backend/app/Services/AksaraConfig.php`: Configuration loading and validation service.
- `php-backend/app/Services/SecurityService.php`: Cryptographic and request security helpers.
- `php-backend/app/Services/JobStore.php`: SQLite database repository with atomic transactions.
- `php-backend/phpunit.xml`: PHPUnit testing configuration.
- `php-backend/tests/TestCase.php`: Base test case.
- `php-backend/tests/CreatesApplication.php`: Application bootstrapper for tests.
- `php-backend/tests/Unit/ConfigTest.php`: Configuration unit tests.
- `php-backend/tests/Unit/SecurityTest.php`: Cryptographic helper unit tests with Node parity vectors.
- `php-backend/tests/Feature/StoreTest.php`: Database repository tests ported from Node.
- `php-backend/NOTES.md`: Recorded SQLite type affinity quirk with PDO parameter binding and solution.
- `php-backend/PROGRESS.md`: Recorded Phase 2 completion.

### How to Run Tests / Commands for this Phase
- Run PHP backend tests:
  ```powershell
  $env:PATH = "C:\xampp\php;" + $env:PATH
  php php-backend/vendor/bin/phpunit -c php-backend/phpunit.xml
  ```
  Or from within `php-backend/`:
  ```powershell
  php vendor/bin/phpunit
  ```
- Run Node backend tests (verify baseline unchanged):
  ```bash
  npm --prefix backend test
  ```
- Run frontend contract tests:
  ```bash
  npm run test:frontend
  ```

### Decisions Made
1. **Security Vector Parity**: Ran Node `security.mjs` with fixed secrets to extract reference vectors and verified identical hex digests and base64url share tokens in `SecurityTest`.
2. **SQLite PDO Binding & Type Affinity**: In PDO SQLite, parameter arrays bind values as string by default, causing SQLite scalar `MAX(int, str)` to prioritize string over integer. Resolved by adding `CAST(? AS INTEGER)` in `JobStore.php` queries for type-safe comparisons.
3. **Database File Auto-Creation**: Configured SQLite connector resolver in `database.php` to ensure the target data directory and sqlite file exist prior to executing connection statements.

### Deviations from Plan
- None. All scaffolding, configuration, security services, migrations, repositories, and tests follow `php-backend/PLAN.md`.

### Open Problems
- None. All tests pass with zero errors and zero warnings.

### Instructions for Next Phase (Phase 3)
- Start Phase 3 in a NEW conversation using its prompt file.
- Phase 3 will implement the Node bridge (`php-backend/bridge/audit-cli.mjs`) wrapping the audit engine (`checkUrl`, `auditUrl`, `renderPdf`), and its PHP client (`BridgeClient.php`), plus bridge parity tests.


