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

---

## Phase 3: HTTP API, CORS, Engine Bridge Client, and Routing

- **Status**: Completed
- **Date**: 2026-10-14
- **Branch**: `php-backend`

### What Was Done
1. **Node Bridge Subcommand (`check-url`)**:
   - Implemented `php-backend/bridge/audit-cli.mjs` with the `check-url <url>` subcommand.
   - Imports engine URL policy directly from relative path `../../../engine/src/fetcher/url-policy.mjs` without copying or rewriting engine logic.
   - Prints verdict as JSON (`{"ok": true, "url": "..."}` or `{"ok": false, "code": "...", "message": "..."}`).
2. **Bridge Service & Interface**:
   - Created `App\Services\BridgeClientInterface` defining `checkUrl(?string $url): array`.
   - Implemented `App\Services\BridgeClient` using `symfony/process` to invoke `audit-cli.mjs`.
   - Handled Windows environment requirements by explicitly inheriting `SYSTEMROOT`, `SystemDrive`, and `PATH` so Node's CSPRNG initializes reliably.
   - Implemented `Tests\Fakes\FakeBridgeClient` for testing without launching external node processes.
   - Implemented `Tests\Feature\BridgeTest` testing real Node execution against public URLs, invalid protocols, localhost, IP literals, and empty URLs (5 tests, 12 assertions, 100% passing).
3. **Audit Worker Interface**:
   - Defined `App\Services\AuditWorkerInterface` with `kick(): void` and `cancel(string $id): ?AuditJob`.
   - Implemented `App\Services\AuditWorker` proxying cancellation to `JobStore`.
   - Implemented `Tests\Fakes\FakeAuditWorker` for mocking worker interaction in API tests.
4. **Model Serialization**:
   - Enhanced `App\Models\AuditJob` with `formatIsoMilliseconds(?int $ms)` and updated `toPublic(?int $queuePosition)` to match Node's `publicJob` schema down to null values, integer millisecond timestamps, and ISO strings with millisecond precision (`YYYY-MM-DDTHH:mm:ss.sssZ`).
5. **Middlewares**:
   - Implemented `App\Http\Middleware\AksaraCorsMiddleware` matching `backend/src/app.mjs:setCors()`:
     - Allows requests without `Origin` header (CLI, curl, server-to-server).
     - Checks `Origin` against `FRONTEND_ORIGINS`.
     - Returns HTTP 403 `{"error": {"code": "origin-not-allowed", "message": "Origin not allowed by CORS"}}` with standard security headers for disallowed origins.
     - Responds HTTP 204 No Content for preflight `OPTIONS` requests with `access-control-allow-origin`, `access-control-allow-methods`, `access-control-allow-headers`, `access-control-allow-credentials: true`, and `access-control-max-age: 600`.
     - Sets CORS headers and `Vary: Origin` on allowed responses.
   - Implemented `App\Http\Middleware\AksaraResponseHeadersMiddleware` ensuring all responses carry `Cache-Control: no-store` and `X-Content-Type-Options: nosniff`.
   - Implemented `App\Http\Middleware\EnforceJsonRequestMiddleware`:
     - Returns HTTP 415 `unsupported-media-type` ("Content-Type harus application/json.") for POST/PUT with missing or non-JSON content-type.
     - Returns HTTP 413 `payload-too-large` ("Payload terlalu besar.") when `Content-Length` or raw body exceeds `MAX_BODY_BYTES`.
     - Returns HTTP 400 `invalid-json` ("JSON tidak valid.") for malformed JSON bodies.
6. **HTTP API Controllers & Routes**:
   - `GET /health` (`HealthController`): returns `{ok: true, worker: "ready", concurrency: 1}`.
   - `GET /audits/cache?url=` (`AuditCacheController`): validates URL, queries completed job, and returns cached metadata or `hasCache: false`.
   - `POST /audits` (`AuditController`): enforces rate limiting (HTTP 429 `rate-limit-exceeded` with `Retry-After: 3600`), validates URL via bridge, checks existing cache for `reuseExisting`, copies cached artifact folder and completes instantly returning HTTP 200 with `Location: /audits/{id}`, or creates queued job returning HTTP 202 with `Location: /audits/{id}` and kicks worker.
   - `GET /audits/{id}` (`AuditController`): checks bearer token (or `?accessToken=`), returns job status with queue position or HTTP 404 `job-not-found`.
   - `DELETE /audits/{id}` (`AuditController`): cancels queued or running audit, returning `{ok: true, canceled: true}` or `{ok: true, canceled: false}` if completed/failed/missing.
   - `GET /audits/{id}/result` (`AuditResultController`): returns HTTP 409 `not-ready` if job is queued or running, HTTP 404 if failed or missing, and completed result payload including reader, reportPdf, and screenshot links with share token.
   - `GET /audits/{id}/artifacts/{artifact}?token=` (`AuditArtifactController`): validates query `token` against `share_token_hash`, protects against path traversal (`..`, slashes), checks retention and file existence returning HTTP 404 or 410, sets appropriate Content-Type (`text/html`, `application/pdf`, `image/png`, `application/json`), CSP headers, `Cache-Control: max-age=300, private`, and streams the file.
   - Fallback route: returns HTTP 404 `{"error": {"code": "not-found", "message": "Endpoint tidak ditemukan."}}`.
7. **Service Provider and Bootstrap**:
   - Registered `BridgeClientInterface`, `AuditWorkerInterface`, `JobStore`, `AksaraConfig`, and `SecurityService` in `AppServiceProvider`.
   - Configured global middleware pipeline in `bootstrap/app.php`.
   - Configured custom HTTP exception renders in `bootstrap/app.php` to guarantee standard JSON error envelopes for NotFoundHttpException, MethodNotAllowedHttpException, and unhandled Throwables.
8. **Testing & Parity Verification**:
   - `tests/Feature/CorsTest.php` (5 tests, 27 assertions): verifies no-origin, allowed origin, disallowed origin 403, preflight 204, and response headers.
   - `tests/Feature/BridgeTest.php` (5 tests, 12 assertions): verifies real Node bridge `check-url` execution.
   - `tests/Feature/ApiTest.php` (7 tests, 62 assertions): tests full API lifecycle, JSON enforcement, 415/413/400 errors, rate limiting 429, cache reuse 200 vs queued 202, job polling, 409 not-ready, cancellation, result retrieval with share tokens, and artifact streaming with CSP headers.
   - Ran full test suite: 33 tests, 213 assertions, 100% passing.
   - Validated side-by-side against running Node backend using `curl` across all endpoints with identical headers, status codes, and JSON response bodies.

### Files Created / Changed
- `php-backend/bridge/audit-cli.mjs` (Created): Node CLI bridge with `check-url` subcommand importing `engine/src/fetcher/url-policy.mjs`.
- `php-backend/app/Services/BridgeClientInterface.php` (Created): Bridge client contract.
- `php-backend/app/Services/BridgeClient.php` (Created): Bridge client calling Node bridge with Windows/POSIX environment preservation.
- `php-backend/tests/Fakes/FakeBridgeClient.php` (Created): Bridge client test double.
- `php-backend/app/Services/AuditWorkerInterface.php` (Created): Worker client contract.
- `php-backend/app/Services/AuditWorker.php` (Created): Audit worker service implementation.
- `php-backend/tests/Fakes/FakeAuditWorker.php` (Created): Worker spy test double.
- `php-backend/app/Models/AuditJob.php` (Modified): Added `formatIsoMilliseconds()` and updated `toPublic()` serialization.
- `php-backend/app/Http/Middleware/AksaraCorsMiddleware.php` (Created): Custom CORS middleware matching `app.mjs`.
- `php-backend/app/Http/Middleware/AksaraResponseHeadersMiddleware.php` (Created): Security and caching headers middleware (`no-store`, `nosniff`).
- `php-backend/app/Http/Middleware/EnforceJsonRequestMiddleware.php` (Created): JSON validation, Content-Type check (415), and payload size check (413).
- `php-backend/app/Http/Controllers/HealthController.php` (Created): `GET /health` endpoint handler.
- `php-backend/app/Http/Controllers/AuditCacheController.php` (Created): `GET /audits/cache` endpoint handler.
- `php-backend/app/Http/Controllers/AuditController.php` (Created): `POST /audits`, `GET /audits/{id}`, `DELETE /audits/{id}` handlers.
- `php-backend/app/Http/Controllers/AuditResultController.php` (Created): `GET /audits/{id}/result` endpoint handler.
- `php-backend/app/Http/Controllers/AuditArtifactController.php` (Created): `GET /audits/{id}/artifacts/{artifact}` artifact streaming handler with CSP.
- `php-backend/routes/api.php` (Modified): Registered all 7 endpoints and 404 fallback.
- `php-backend/bootstrap/app.php` (Modified): Configured middleware stack and custom exception rendering.
- `php-backend/public/index.php` (Created): Laravel public HTTP entry point.
- `php-backend/app/Providers/AppServiceProvider.php` (Modified): Container bindings for bridge, worker, and services.
- `php-backend/tests/Feature/CorsTest.php` (Created): Feature tests for CORS behaviors.
- `php-backend/tests/Feature/BridgeTest.php` (Created): Feature tests for Node bridge `check-url`.
- `php-backend/tests/Feature/ApiTest.php` (Created): Complete API endpoint behavior tests ported from `backend/tests/api.test.mjs`.
- `php-backend/NOTES.md` (Updated): Added notes 7 and 8 on Windows Node environment inheritance and Symfony BinaryFileResponse cache-control.
- `php-backend/PROGRESS.md` (Updated): Recorded Phase 3 completion.

### How to Run Tests / Commands for this Phase
- Run all PHPUnit tests:
  ```powershell
  $env:PATH = "C:\xampp\php;" + $env:PATH
  php php-backend/vendor/bin/phpunit -c php-backend/phpunit.xml
  ```
  Or from inside `php-backend/`:
  ```powershell
  php vendor/bin/phpunit
  ```
- Run Node bridge standalone test:
  ```powershell
  node php-backend/bridge/audit-cli.mjs check-url https://example.com
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
1. **Node Bridge Subcommand Implementation**: Implemented `check-url <url>` in `php-backend/bridge/audit-cli.mjs` importing directly from `engine/src/fetcher/url-policy.mjs`. This maintains a single source of truth for SSRF policy without duplicating logic in PHP.
2. **Environment Inheritance in BridgeClient**: On Windows platforms, Node's `ncrypto::CSPRNG` requires `SYSTEMROOT` to access crypto APIs. Explicitly passed `SYSTEMROOT`, `SystemDrive`, and `PATH` into `Process` environment variables.
3. **Artifact Route Token Authentication**: Preserved strict query token verification against `share_token_hash` in `AuditArtifactController`, matching Node `sendArtifact` behavior.
4. **BinaryFileResponse Cache Headers**: Explicitly called `$response->setPrivate()->setMaxAge(300)` on Symfony's `BinaryFileResponse` so `Cache-Control: max-age=300, private` is emitted verbatim.
5. **Millisecond Precision ISO Strings**: In `AuditJob::formatIsoMilliseconds()`, formatted dates as `Y-m-d\TH:i:s.v\Z` to match JavaScript's `toISOString()` format with 3-digit millisecond resolution.

### Deviations from Plan
- None. All routes, status codes, JSON shapes, and headers strictly match the Node.js implementation as planned.

### Open Problems
- None. All 33 PHPUnit tests, 10 Node backend tests, and 5 frontend contract tests pass cleanly.

### Instructions for Next Phase (Phase 4)
- Start Phase 4 in a NEW conversation using its prompt file.
- Phase 4 will implement:
  1. The remaining subcommands of `php-backend/bridge/audit-cli.mjs`: `audit` (streaming NDJSON progress/result/error) and `render-pdf` (calling `renderPdf`).
  2. The PHP Auditor service (`App\Services\Auditor`) porting `backend/src/auditor.mjs` including checkpoint resume (`snapshot.json`).
  3. The PHP Report Renderer (`App\Services\ReportRenderer`) porting `backend/src/report.mjs` with byte-identical HTML output.
  4. Unit and feature tests verifying auditor execution and report rendering parity.



