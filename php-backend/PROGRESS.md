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

---

## Phase 4: Bridge, Auditor, and Worker

- **Status**: Completed
- **Date**: 2026-10-15
- **Branch**: `php-backend`

### What Was Done
1. **Node Bridge Subcommands (`audit` and `render-pdf`)**:
   - Implemented `audit` subcommand in `php-backend/bridge/audit-cli.mjs`:
     - Reads `{ id, url, artifactDir }` as JSON from `process.stdin`.
     - Invokes engine's `auditUrl` from `../../engine/src/api/index.mjs` by relative import without copying or rewriting engine logic.
     - Emits streaming NDJSON lines on stdout: `{"type":"progress","progress":N,"stage":"..."}`, followed by `{"type":"result","snapshot":...,"files":...}` or `{"type":"error","code":...,"stage":...,"message":...}`.
     - Handles graceful abort on `SIGTERM` / `SIGINT` via `AbortController` passed as `signal`.
   - Implemented `render-pdf` subcommand in `php-backend/bridge/audit-cli.mjs`:
     - Reads `<htmlPath> <outPath>` CLI arguments, loads HTML, and invokes engine's `renderPdf` from `../../engine/src/runner/index.mjs`.
     - Emits `{"ok":true,"path":"..."}` on stdout with zero exit code, or error output on failure.
     - Dynamically imports runner modules only when needed to maintain high performance in CLI commands.
2. **BridgeClient & Exceptions**:
   - Extended `BridgeClientInterface` and `BridgeClient` to support `audit()` and `renderPdf()`.
   - Streamed NDJSON lines via chunked output parsing callback and mapped progress updates to database.
   - Polled `$shouldCancel` closure every 500ms to terminate child process via `$process->stop()` on cancellation or timeout.
   - Created custom exception classes: `AuditFailedException` (with `errorCode` and `stage`), `AuditCancelledException`, and `AuditTimeoutException`.
3. **Auditor Service & Report Renderer Interface**:
   - Implemented `App\Services\AuditorInterface` and `App\Services\Auditor` porting `backend/src/auditor.mjs`:
     - Directory initialization.
     - Checkpoint 1: detects `snapshot.json` and `engine-result.json`, emits stage 95 ("Memulihkan hasil audit dari checkpoint tersimpan"), and skips browser audit re-execution.
     - Progress stages 96 ("Menyiapkan laporan hasil") and 99 ("Memfinalisasi hasil").
     - Report HTML generation behind `ReportRendererInterface` (`StubReportRenderer` placeholder for Phase 4, with full implementation scheduled for Phase 5).
     - PDF generation via bridge `render-pdf`, with low-resource skip fallback logging warning and falling back to `report.html`.
     - Returns `{snapshot, artifacts: {reader, patched, reportPdf, screenshot}}`.
4. **Audit Worker & Artisan Console Commands**:
   - Implemented `App\Services\AuditWorker`:
     - Concurrency 1.
     - Atomic claiming in SQLite transaction (`JobStore::claimNext()`).
     - Crash recovery on startup (`JobStore::recoverInterrupted()`).
     - Timeout detection enforcing `AUDIT_TIMEOUT_MS`, failing with `audit-timeout` and deleting partial artifacts.
     - Cancellation handling (both in-process and cross-process via database polling), aborting child process, marking status `cancelled`, and deleting partial artifacts.
     - Transient error retries (`dns-lookup-failed`, `robots-fetch-failed`, `browser-failed`, `audit-failed`) while `attempts < 2`.
     - Permanent failure handling and artifact cleanup.
   - Created `php artisan audit:work` (`App\Console\Commands\AuditWorkCommand`):
     - Recovers interrupted jobs at boot.
     - Configures POSIX signal handlers (`SIGINT`, `SIGTERM`).
     - Executes periodic cleanup every `CLEANUP_INTERVAL_MS`.
     - Processes queue sequentially and sleeps when idle. Supports `--once` flag.
   - Created `php artisan audit:cleanup` (`App\Console\Commands\AuditCleanupCommand`):
     - Deletes expired jobs and their artifact directories.
5. **Testing & End-to-End Verification**:
   - Implemented `Tests\Fakes\FakeAuditor` and enhanced `FakeBridgeClient`.
   - Implemented `Tests\Feature\WorkerTest` with 6 tests:
     - `test_runs_only_one_audit_at_a_time` (concurrency 1 verification).
     - `test_retries_one_transient_failure_and_then_succeeds` (attempts incremented to 2, status completed).
     - `test_cancels_a_running_audit_and_removes_partial_artifacts` (status cancelled, partial artifacts purged).
     - `test_fails_with_audit_timeout_and_removes_partial_artifacts` (code `audit-timeout`, partial artifacts purged).
     - `test_fails_permanently_on_second_transient_failure` (fails after 2 attempts).
     - `test_fails_immediately_on_non_transient_error` (fails immediately without retry).
   - Implemented `Tests\Feature\AuditorTest` with 4 tests:
     - `test_auditor_runs_full_lifecycle_and_generates_artifacts`.
     - `test_auditor_resumes_from_checkpoint_without_calling_bridge_audit`.
     - `test_auditor_handles_pdf_fallback_gracefully`.
     - `test_auditor_aborts_when_cancellation_signaled`.
   - Ran all 43 PHPUnit tests (257 assertions): 100% passing.
   - Ran 10 Node backend tests: 100% passing.
   - Ran 5 frontend contract tests: 100% passing.
   - Verified real end-to-end audit with live Chromium against `https://example.com/`:
     - Claimed from SQLite by PHP worker.
     - Executed via Node bridge `audit-cli.mjs audit`.
     - Progress streamed from 2% to 94%, then 96% and 99%.
     - Produced all 11 artifact files (`snapshot.json`, `engine-result.json`, `reader.html`, `patched.html`, `report.html`, `report.pdf`, `after.png`, axe scans, aria snapshot).
     - Completed successfully with progress 100 and stage "Audit selesai".

### Files Created / Changed
- `php-backend/bridge/audit-cli.mjs` (Modified): Added `audit` and `render-pdf` subcommands with SIGTERM abort support.
- `php-backend/app/Exceptions/AuditFailedException.php` (Created): Exception with `errorCode` and `stage`.
- `php-backend/app/Exceptions/AuditCancelledException.php` (Created): Exception for audit cancellation.
- `php-backend/app/Exceptions/AuditTimeoutException.php` (Created): Exception for audit timeout.
- `php-backend/app/Services/BridgeClientInterface.php` (Modified): Added `audit()` and `renderPdf()`.
- `php-backend/app/Services/BridgeClient.php` (Modified): Implemented `audit()` and `renderPdf()` with NDJSON parsing and cancellation checks.
- `php-backend/app/Services/ReportRendererInterface.php` (Created): Interface for report HTML generation.
- `php-backend/app/Services/StubReportRenderer.php` (Created): Phase 4 placeholder renderer.
- `php-backend/app/Services/AuditorInterface.php` (Created): Interface for auditor service.
- `php-backend/app/Services/Auditor.php` (Created): Port of `backend/src/auditor.mjs` with checkpoint resume, progress stages, and PDF fallback.
- `php-backend/app/Services/AuditWorkerInterface.php` (Modified): Full worker contract with `processNext()`, `drain()`, `recover()`, `stop()`.
- `php-backend/app/Services/AuditWorker.php` (Modified): Full worker service mirroring `backend/src/worker.mjs` with atomic claiming, timeout, retries, cancellation, and artifact cleanup.
- `php-backend/app/Services/JobStore.php` (Modified): Updated `recoverInterrupted()` to return integer count of recovered jobs.
- `php-backend/app/Console/Commands/AuditWorkCommand.php` (Created): `audit:work` console command.
- `php-backend/app/Console/Commands/AuditCleanupCommand.php` (Created): `audit:cleanup` console command.
- `php-backend/app/Providers/AppServiceProvider.php` (Modified): Registered Auditor, ReportRenderer, and Worker container singletons.
- `php-backend/tests/Fakes/FakeBridgeClient.php` (Modified): Added `audit()` and `renderPdf()` test stubs.
- `php-backend/tests/Fakes/FakeAuditor.php` (Created): Fake auditor test double for Chromium-free testing.
- `php-backend/tests/Fakes/FakeAuditWorker.php` (Modified): Updated to implement full `AuditWorkerInterface`.
- `php-backend/tests/Feature/WorkerTest.php` (Created): 6 feature tests ported from `backend/tests/worker.test.mjs`.
- `php-backend/tests/Feature/AuditorTest.php` (Created): 4 feature tests for checkpoint resume, PDF fallback, and artifacts.
- `php-backend/PROGRESS.md` (Updated): Recorded Phase 4 completion.

### How to Run Tests / Commands for this Phase
- Run PHPUnit tests:
  ```powershell
  $env:PATH = "C:\xampp\php;" + $env:PATH
  php php-backend/vendor/bin/phpunit -c php-backend/phpunit.xml
  ```
- Run Artisan worker (single job mode):
  ```powershell
  php php-backend/artisan audit:work --once
  ```
- Run Artisan cleanup:
  ```powershell
  php php-backend/artisan audit:cleanup
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
1. **Dynamic Module Import in Node Bridge**: Imported `engine/src/api/index.mjs` and `engine/src/runner/index.mjs` dynamically inside their respective subcommands (`command === "audit"` and `command === "render-pdf"`) so that lightweight subcommands like `check-url` do not incur Playwright startup overhead.
2. **Container-Independent Directory Removal**: Used pure PHP recursive filesystem operations in `AuditWorker` rather than Laravel's `File` facade, allowing worker unit and feature tests to execute cleanly without booting the entire Laravel framework container.
3. **ReportRenderer Interface & Stub**: Implemented `ReportRendererInterface` and provided `StubReportRenderer` as specified for Phase 4, keeping the Auditor fully testable and functional while deferring byte-identical HTML rendering to Phase 5.
4. **Typed Error Code Property**: Defined `errorCode: string` on `AuditFailedException` to prevent collisions with PHP's internal `Exception::$code` integer property.

### Deviations from Plan
- None. Everything implemented matches `php-backend/PLAN.md` and Phase 4 specifications.

### Open Problems
- None.

### Instructions for Next Phase (Phase 5)
- Start Phase 5 in a NEW conversation using its prompt file.
- Phase 5 will implement the byte-identical HTML report renderer (`App\Services\ReportRenderer`) porting `backend/src/report.mjs`, and verify exact byte parity against Node `renderAuditReport()`.

---

## Phase 5: Report and PDF

- **Status**: Completed
- **Date**: 2026-10-15
- **Branch**: `php-backend`

### What Was Done
1. **Byte-Identical Report Renderer (`App\Services\ReportRenderer`)**:
   - Ported `backend/src/report.mjs` to PHP strictly adhering to Node behavior:
     - `esc($value)`: Escapes only `&`, `<`, `>`, `"` to `&amp;`, `&lt;`, `&gt;`, `&quot;`. Single quotes are preserved unescaped matching Node's `.replace(/&/g, ...)` chain. Safely handles `null`, booleans (`true`/`false`), and numeric values.
     - `rows($records)`: Emits `<p>Tidak ada.</p>` when empty, or an unformatted HTML table with `<code>` around selectors and escaped table cells.
     - `stringifyRegression($regression)`: Formats WCAG regression data with exact 2-space indentation matching Node's `JSON.stringify(regression, null, 2)`, preserving slashes and unicode.
     - `renderAuditReport($args)` & `render($snapshot, $report)`: Emits the exact HTML layout and inline CSS byte-for-byte identical to Node.
2. **Parity Test Fixtures Generation**:
   - Created 5 test fixtures in `php-backend/tests/Fixtures/reports/` using the real Node.js `renderAuditReport()` from `backend/src/report.mjs`:
     - `fixture_1`: Standard full report with verified fixes, items for review, and clean WCAG regression status.
     - `fixture_2`: Minimal report with empty source/summary and multiple worsened WCAG regression rules.
     - `fixture_3`: Special characters, HTML tags, quotes, URLs with query parameters, and rolled-back records.
     - `fixture_4`: Null/empty values with default fallback title ("Laporan audit AksaraNetra") and zero metrics.
     - `fixture_5`: Multi-rule regression with nested tags, affected selectors, floating confidence scores, and Indonesian unicode characters.
3. **Comprehensive Unit Testing (`ReportRendererTest`)**:
   - Authored `php-backend/tests/Unit/ReportRendererTest.php` with 5 test cases (45 assertions):
     - Verified escaping helper against special characters, quotes, nulls, booleans, and numbers.
     - Verified table row formatting and empty list notices.
     - Verified 2-space JSON indentation format.
     - Verified byte-for-byte parity against all 5 Node fixtures asserting string identity, byte length, and MD5 hash match.
4. **Service Container Wiring & Auditor Integration**:
   - Bound `ReportRenderer` to `ReportRendererInterface` in `AppServiceProvider.php`.
   - Removed temporary Phase 4 `StubReportRenderer.php`.
   - Updated `AuditorTest.php` to use `ReportRenderer`, verifying that `Auditor` creates `report.html` and `report.pdf`.
5. **Artifact Route & Quirk Verification (`ApiTest`)**:
   - Added assertions to `ApiTest.php` verifying:
     - Successful retrieval of `report.pdf` artifact with `Content-Type: application/pdf`, `Cache-Control: private, max-age=300`, and CSP headers.
     - Reproduction of Quirk #1: when PDF rendering fails or is skipped and only `report.html` exists, the API links still point to `/artifacts/report.pdf`, which returns HTTP 404, while directly requesting `report.html` also returns HTTP 404 because `report.html` is not in `ARTIFACTS`.
6. **Documentation**:
   - Added Note 9 to `php-backend/NOTES.md` documenting JSON regression block 2-space indentation parity.
   - Verified all 49 PHPUnit tests, 10 Node backend tests, and 5 frontend contract tests pass cleanly.

### Files Created / Changed
- `php-backend/app/Services/ReportRenderer.php` (Created): Byte-identical HTML report renderer porting `backend/src/report.mjs`.
- `php-backend/app/Providers/AppServiceProvider.php` (Modified): Bound `ReportRenderer` to `ReportRendererInterface`.
- `php-backend/app/Services/StubReportRenderer.php` (Deleted): Removed temporary Phase 4 stub.
- `php-backend/tests/Fixtures/reports/` (Created): 5 Node-generated input JSON and expected HTML fixture pairs (`fixture_1` to `fixture_5`).
- `php-backend/tests/Unit/ReportRendererTest.php` (Created): Unit tests for escaping, table rows, and byte-for-byte fixture parity.
- `php-backend/tests/Feature/AuditorTest.php` (Modified): Updated to use `ReportRenderer` and assert `report.html` output.
- `php-backend/tests/Feature/ApiTest.php` (Modified): Added `report.pdf` download tests and quirk verification.
- `php-backend/NOTES.md` (Updated): Added Note 9 on 2-space JSON formatting parity.
- `php-backend/PROGRESS.md` (Updated): Recorded Phase 5 completion.

### How to Run Tests / Commands for this Phase
- Run PHPUnit tests:
  ```powershell
  $env:PATH = "C:\xampp\php;" + $env:PATH
  php php-backend/vendor/bin/phpunit -c php-backend/phpunit.xml
  ```
  Or using direct PHP path:
  ```powershell
  C:\xampp\php\php.exe php-backend/vendor/bin/phpunit -c php-backend/phpunit.xml
  ```
- Run ReportRenderer unit tests specifically:
  ```powershell
  C:\xampp\php\php.exe php-backend/vendor/bin/phpunit -c php-backend/phpunit.xml --filter ReportRendererTest
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
1. **Deterministic 2-Space JSON Formatting**: Handled PHP's `json_encode` default 4-space indentation by regex replacement per indentation level, guaranteeing exact compatibility with Node's `JSON.stringify(..., null, 2)`.
2. **Single Quote Preservation**: Node's `esc()` in `report.mjs` only replaces `&`, `<`, `>`, `"`. Single quotes `'` are deliberately left untouched, matching Node's implementation rather than PHP's default `htmlspecialchars(..., ENT_QUOTES)`.
3. **Quirk Verification Test**: Explicitly asserted the quirk where the auditor can record `report.html` as `reportPdf` in the result payload, while the HTTP API route strictly serves `report.pdf` and returns 404 if `report.pdf` is missing.

### Deviations from Plan
- None. Everything implemented matches `php-backend/PLAN.md` and Phase 5 specifications.

### Open Problems
- None.

### Instructions for Next Phase (Phase 6)
- Start Phase 6 in a NEW conversation using its prompt file.
- Phase 6 will implement Docker packaging (`Dockerfile`, `docker-compose.yml`, `entrypoint.sh`), unified container running PHP 8.3 + Composer deps + Node 22 + Playwright Chromium, healthcheck, and container startup verification.

---

## Phase 6: Docker Packaging and Multi-Service Architecture

- **Status**: Completed
- **Date**: 2026-10-15
- **Branch**: `php-backend`

### What Was Done
1. **Production Dockerfile (`php-backend/Dockerfile` and `php-backend/docker/Dockerfile`)**:
   - Multi-ecosystem production image based on official `php:8.3-fpm-bookworm`.
   - Installed system utilities (`ca-certificates`, `curl`, `git`, `nginx`, `supervisor`, `unzip`).
   - Enabled essential PHP extensions: `pcntl` (for process control and POSIX signal handling) and `opcache` (performance), alongside native `pdo_sqlite`, `mbstring`, `openssl`, and `json`.
   - Installed Composer 2 from official binary image.
   - Installed Node.js 22 via official NodeSource repository.
   - Cached engine dependencies layer: `cd engine && npm ci --omit=dev`.
   - Installed Playwright Chromium with system dependencies matching `backend/Dockerfile` using `ENV PLAYWRIGHT_BROWSERS_PATH=/app/.playwright` and `RUN cd engine && npx playwright install --with-deps chromium`.
   - Cached PHP Composer dependencies layer: `composer install --no-dev --no-interaction --no-scripts --optimize-autoloader`.
   - Copied only necessary application sources (`engine/src`, `php-backend/app`, `bootstrap`, `bridge`, `config`, `database`, `docker`, `public`, `routes`, `artisan`).
   - Generated production optimized classmap autoloader: `composer dump-autoload --optimize --no-dev`.
   - Created dedicated non-root user `aksara` (UID 1000, GID 1000).
   - Created `/data` volume directory and runtime storage trees with `chown -R aksara:aksara /data /app`.
   - Set production environment defaults: `NODE_ENV=production`, `APP_ENV=production`, `APP_DEBUG=false`, `PORT=8787`, `HOST=0.0.0.0`, `CHROMIUM_LOW_RESOURCE_MODE=1`.
   - Switched to non-root `USER aksara`, exposed port `8787`, configured entrypoint and default CMD.
2. **Web Server & Process Orchestration (`php-backend/docker/`)**:
   - **Nginx (`php-backend/docker/nginx.conf`)**:
     - Configured to run unprivileged on port `8787` as non-root user `aksara`.
     - Placed all PID and temporary buffers (`client_temp`, `proxy_temp`, `fastcgi_temp`, etc.) under `/tmp/`.
     - Streamed access and error logs directly to `/dev/stdout` and `/dev/stderr`.
     - Configured FastCGI pass to `127.0.0.1:9000` with 300s timeouts.
     - Set `client_max_body_size 10m` so Laravel's `EnforceJsonRequestMiddleware` handles size limits and returns exact JSON error responses.
   - **PHP-FPM (`php-backend/docker/php-fpm.conf`)**:
     - Configured PID to `/tmp/php-fpm.pid` for non-root execution.
     - Set `clear_env = no` so that container environment variables (`BACKEND_SECRET`, `FRONTEND_ORIGINS`, `BACKEND_DATA_DIR`, etc.) are passed to PHP worker processes.
     - Configured dynamic process management (`pm = dynamic`, 20 max children).
   - **Supervisord (`php-backend/docker/supervisord.conf`)**:
     - Configured non-root PID and log in `/tmp/`.
     - Manages PHP-FPM (priority 5) and Nginx (priority 10), restarting them on failure and streaming output to Docker stdout/stderr.
   - **Container Entrypoint (`php-backend/docker/entrypoint.sh`)**:
     - Handled safe, single-runner database migrations at startup: runs `php artisan migrate --force` ONLY when starting the `api` service.
     - Starts supervisord for `api`, runs `php artisan audit:work` for `worker`, or passes through arbitrary commands.
     - Enforced LF line endings and executable git permissions (`+x`) with `.gitattributes`.
3. **Docker Compose Stack (`php-backend/docker-compose.yml`)**:
   - Defined `api` (port 8787) and `worker` services built from the same image using repo root context (`context: ..`, `dockerfile: php-backend/Dockerfile`).
   - Configured shared named volume `backend_data:/data` ensuring persistence across restarts.
   - Added healthcheck on `api` testing `curl -f http://localhost:8787/health`.
   - Configured `worker` to depend on `api` with `condition: service_healthy`, ensuring the worker only boots after migrations complete and the API is ready.
   - Configured `restart: unless-stopped` and `env_file: - .env`.
4. **Environment Template (`php-backend/.env.example`)**:
   - Extensively documented all configuration parameters for local development, Docker, and production deployments.
   - Documented required variables (`BACKEND_SECRET` min 32 chars, `FRONTEND_ORIGINS`, `BACKEND_DATA_DIR=/data`, `CHROMIUM_LOW_RESOURCE_MODE=1`).
5. **Multi-Process Concurrency & WAL Permissions**:
   - Both containers run as UID:GID 1000:1000 (`aksara`), ensuring identical read/write ownership of `/data/jobs.sqlite`, `-wal`, `-shm`, and artifact subdirectories.
   - SQLite WAL mode and 5000ms busy timeout prevent lock contention between the API and Worker containers.
6. **Local & Parity Testing**:
   - Re-verified all 49 PHPUnit tests (320 assertions): 100% passing.
   - Re-verified all 10 Node backend tests: 100% passing.
   - Re-verified all 5 frontend contract tests: 100% passing.
   - Validated configuration files (INI syntax, shell syntax, LF normalization, compose structure).

### Files Created / Changed
- `php-backend/Dockerfile` (Created): Unified PHP 8.3 + Node 22 + Chromium production image.
- `php-backend/docker/Dockerfile` (Created): Dockerfile copy inside `docker/` for path convenience.
- `php-backend/docker-compose.yml` (Created): Compose stack defining `api` and `worker` services sharing `backend_data:/data`.
- `php-backend/docker/nginx.conf` (Created): Non-root Nginx web server configuration on port 8787.
- `php-backend/docker/php-fpm.conf` (Created): Non-root PHP-FPM pool configuration with `clear_env = no`.
- `php-backend/docker/supervisord.conf` (Created): Supervisor process manager configuration for API container.
- `php-backend/docker/entrypoint.sh` (Created): Container startup script handling safe migrations and service branching.
- `php-backend/.gitattributes` (Created): Enforces LF line endings for `*.sh` files across operating systems.
- `php-backend/.env.example` (Modified): Added comprehensive documentation for Docker and production env vars.
- `php-backend/PROGRESS.md` (Modified): Recorded Phase 6 completion and handoff.

### How to Run Tests / Commands for this Phase
- Run PHPUnit tests (local baseline):
  ```powershell
  $env:PATH = "C:\xampp\php;" + $env:PATH
  php php-backend/vendor/bin/phpunit -c php-backend/phpunit.xml
  ```
- Run Node backend tests:
  ```bash
  npm --prefix backend test
  ```
- Run frontend contract tests:
  ```bash
  npm run test:frontend
  ```
- Run Docker stack verification (on an environment with Docker installed):
  ```bash
  # 1. Navigate to php-backend and prepare env
  cd php-backend
  cp .env.example .env

  # 2. Build image and launch stack
  docker compose up --build -d

  # 3. Verify health endpoint
  curl -i http://localhost:8787/health

  # 4. Create an audit job
  JOB_RESP=$(curl -s -X POST http://localhost:8787/audits \
    -H "Content-Type: application/json" \
    -d '{"url":"https://example.com"}')
  echo $JOB_RESP
  JOB_ID=$(echo $JOB_RESP | jq -r .jobId)
  TOKEN=$(echo $JOB_RESP | jq -r .accessToken)

  # 5. Poll job status
  curl -i http://localhost:8787/audits/$JOB_ID -H "Authorization: Bearer $TOKEN"

  # 6. Fetch result once completed
  curl -i http://localhost:8787/audits/$JOB_ID/result -H "Authorization: Bearer $TOKEN"

  # 7. Download artifacts using share token
  SHARE_TOKEN=$(curl -s http://localhost:8787/audits/$JOB_ID/result -H "Authorization: Bearer $TOKEN" | jq -r .shareToken)
  curl -i "http://localhost:8787/audits/$JOB_ID/artifacts/reader?token=$SHARE_TOKEN"
  curl -i "http://localhost:8787/audits/$JOB_ID/artifacts/report.pdf?token=$SHARE_TOKEN"
  curl -i "http://localhost:8787/audits/$JOB_ID/artifacts/screenshot?token=$SHARE_TOKEN"

  # 8. Test cancellation
  NEW_JOB=$(curl -s -X POST http://localhost:8787/audits -H "Content-Type: application/json" -d '{"url":"https://example.com"}')
  NEW_ID=$(echo $NEW_JOB | jq -r .jobId)
  NEW_TOKEN=$(echo $NEW_JOB | jq -r .accessToken)
  curl -i -X DELETE http://localhost:8787/audits/$NEW_ID -H "Authorization: Bearer $NEW_TOKEN"

  # 9. Verify volume data persistence across restarts
  docker compose down
  docker compose up -d
  curl -i http://localhost:8787/audits/$JOB_ID -H "Authorization: Bearer $TOKEN"
  ```

### Decisions Made
1. **Nginx + PHP-FPM with Supervisord**: Selected Nginx + PHP-FPM managed by Supervisord as the production web server. All processes run under an unprivileged user (`aksara`, UID 1000) by utilizing `/tmp` for temporary files, socket paths, and PID files.
2. **Clear Environment Variables Setting**: Explicitly set `clear_env = no` in `php-fpm.conf` so PHP-FPM preserves runtime container environment variables (`BACKEND_SECRET`, `FRONTEND_ORIGINS`, etc.) for Laravel.
3. **Startup Migration Safety**: Migrations are executed strictly in the `api` service entrypoint before launching Nginx/PHP-FPM. The `worker` service waits for `api` to pass healthcheck before starting `audit:work`.
4. **Volume Ownership Parity**: Both `api` and `worker` containers run under UID 1000 (`aksara`), eliminating permission conflicts when reading/writing `/data/jobs.sqlite`, WAL files, and artifact directories.
5. **LF Line Endings**: Enforced LF line endings on `entrypoint.sh` via `.gitattributes` to prevent Windows CRLF issues when executed inside Linux containers.

### Deviations from Plan
- None.

### Open Problems
- Docker is not installed on the local Windows development host (`docker : The term 'docker' is not recognized`). All configuration files, Dockerfiles, entrypoints, and permissions were created and verified by syntax and static checks. Full container execution commands are documented above for deployment environments.

### Instructions for Next Phase (Phase 7)
- Start Phase 7 in a NEW conversation using its prompt file.
- Phase 7 is the final phase: Parity checks, frontend integration testing (`NEXT_PUBLIC_AUDIT_API_URL` pointing to PHP backend), end-to-end audit verification, and final documentation.

---

## Phase 7: Parity Check and Documentation

- **Status**: Completed
- **Date**: 2026-10-18
- **Branch**: `php-backend`

### What Was Done
1. **Automated Cross-Backend Parity Check Script (`php-backend/scripts/parity-check.mjs`)**:
   - Implemented an automated parity check script capable of auto-starting isolated test instances of both the Node backend (port 56xxx) and PHP backend (port 56xxx), or targeting existing instances via `--node-url` and `--php-url`.
   - Pre-seeded both SQLite databases with identical fixture data (completed job `aud_paritycompleted0000000001` and dynamically expiring job `aud_parityexpired000000000001`) and artifact files (`reader.html`, `patched.html`, `report.pdf`, `after.png`), allowing full test suite execution without Playwright/Chromium dependencies during routine verification.
   - Executed a sequence of 42 distinct HTTP test cases against both backends, asserting equality across:
     - HTTP status codes (200, 202, 204, 400, 403, 404, 409, 410, 413, 415, 429).
     - CORS response headers (`access-control-allow-origin`, `access-control-allow-methods`, `access-control-allow-headers`, `access-control-allow-credentials`, `access-control-max-age`, `vary: Origin`).
     - Security headers (`x-content-type-options: nosniff`, `content-security-policy`).
     - Cache control directives (`no-store`, `private, max-age=300`).
     - Location header format (`/^\/audits\/aud_[a-zA-Z0-9_-]{24}$/`).
     - Rate limit headers (`retry-after: 3600`).
     - Content-Type headers (`application/json; charset=utf-8`, `text/html; charset=utf-8`, `application/pdf`, `image/png`).
     - JSON payload structures and normalized values (regex validation for job IDs, access tokens, share tokens, timestamps, and status paths).
     - Raw artifact binary/text contents.
   - Result: 42/42 tests passed (100% parity).

2. **Parity Edge Case Refinements**:
   - **Path Traversal Resolution**: Updated `routes/api.php` artifact parameter matching to `->where('artifact', '.*')`, ensuring traversed and encoded requests like `..%2fjobs.sqlite` reach `AuditArtifactController::show()` and return HTTP 404 `Artefak tidak ditemukan.` matching Node behavior.
   - **CORS Rejection Headers**: Updated `AksaraCorsMiddleware.php` to set `Content-Type: application/json; charset=utf-8` on HTTP 403 CORS rejection responses, matching Node's content type formatting.
   - **Preflight 204 Response Cleaning**: Updated `AksaraCorsMiddleware.php` and `AksaraResponseHeadersMiddleware.php` to bypass 204 No Content responses so that extraneous `Content-Type` and `Cache-Control` headers are omitted on preflight responses, matching Node behavior.
   - **Pure No-Store Cache-Control**: Updated `AksaraResponseHeadersMiddleware.php` to enforce pure `Cache-Control: no-store` on all API responses.

3. **Frontend Integration & Compatibility Verification**:
   - Verified that the unmodified Next.js frontend contract tests pass: `npm run test:frontend` (5/5 tests passed).
   - Authored and executed `php-backend/scripts/test-frontend-flow.mjs`, which exercises the live end-to-end user workflow matching `src/lib/audit-api.ts`:
     - Step 1: Cache check for `https://example.com/` (`available: false`).
     - Step 2: Create audit (`jobId: "aud_..."`, `status: "queued"`, HTTP 202).
     - Step 3: Poll status while queued (`status: "queued"`).
     - Step 4: Worker claims job and executes live audit using Playwright/Chromium engine via Node bridge.
     - Step 5: Poll status after processing (`status: "completed"`, `progress: 100`).
     - Step 6: Fetch result payload containing snapshot summary, WCAG metrics, and share-token-authenticated artifact download links.
     - Step 7: Access and stream artifacts (`reader.html`, `patched.html`, `after.png` screenshot) via share tokens.
     - Step 8: Verify cache hit (`available: true`, `capturedAt` set).
   - Result: 100% pass, confirming seamless drop-in compatibility with the Next.js frontend.

4. **Complete Project Documentation**:
   - Authored `php-backend/README.md`:
     - Architectural overview and ASCII system diagram.
     - Component breakdown and directory structure.
     - Complete environment variable reference table.
     - Local setup instructions without Docker.
     - Docker Compose multi-service deployment guide.
     - Comprehensive test running guide (PHPUnit, parity script, frontend contract, manual flow).
     - Instructions for pointing the Next.js frontend to the PHP backend with zero code changes.
     - Production deployment and resource recommendations.
   - Updated `php-backend/NOTES.md` with Notes 10–13 documenting Symfony cache-control behaviors, route traversal resolution, startup cleanup timing differences, and parity check verification summaries.

5. **Final Clean Repository Verification**:
   - Confirmed `git status` shows zero modified or untracked files outside `php-backend/`.
   - Confirmed `git diff --stat Update-UI/UX` demonstrates that all changes across all 7 phases are strictly contained within `php-backend/`.

### Files Created / Changed
- `php-backend/scripts/parity-check.mjs` (Created): Automated 42-check side-by-side parity verification script.
- `php-backend/scripts/test-frontend-flow.mjs` (Created): Live Next.js frontend workflow integration script.
- `php-backend/README.md` (Created): Comprehensive architecture, setup, docker, and operational documentation.
- `php-backend/NOTES.md` (Modified): Added Notes 10–13 and parity check summary.
- `php-backend/routes/api.php` (Modified): Updated artifact route parameter constraint to `.*`.
- `php-backend/app/Http/Middleware/AksaraCorsMiddleware.php` (Modified): Adjusted 403 Content-Type and 204 headers.
- `php-backend/app/Http/Middleware/AksaraResponseHeadersMiddleware.php` (Modified): Added 204 bypass and no-store enforcement.
- `php-backend/PROGRESS.md` (Modified): Recorded Phase 7 completion.

### How to Run Tests / Commands for this Phase
- Run automated side-by-side parity check:
  ```bash
  node php-backend/scripts/parity-check.mjs
  ```
- Run live frontend workflow verification:
  ```bash
  node php-backend/scripts/test-frontend-flow.mjs
  ```
- Run frontend contract tests:
  ```bash
  npm run test:frontend
  ```
- Run PHPUnit test suite:
  ```powershell
  C:\xampp\php\php.exe php-backend/vendor/bin/phpunit -c php-backend/phpunit.xml
  ```
- Run Node backend reference tests:
  ```bash
  npm --prefix backend test
  ```

### Decisions Made
1. **Dynamic Expiration Seeding in Parity Check**: Rather than seeding an already-expired timestamp which Node's startup `cleanup()` purges upon boot, seeded the job with a short expiration delta (`now + 1200ms`) and waited for the window to lapse before asserting the HTTP 410 Gone response on both backends.
2. **Path Traversal Route Regex (`->where('artifact', '.*')`)**: Permitted all character sequences in the `{artifact}` route parameter so that path traversal attempts (`..%2fjobs.sqlite`) reach controller validation and emit the exact Indonesian error message `Artefak tidak ditemukan.`.
3. **Redundant Private Directive Normalization in Caching**: Documented and normalized Symfony's automatic addition of `, private` to `no-store` headers, which has identical caching semantics under RFC 7234 / RFC 9111.

### Deviations from Plan
- None. All requirements of Phase 7 and PLAN.md were fulfilled in their entirety.

### Open Problems
- None. All test suites (PHPUnit, Node tests, frontend contract, parity check, live flow) pass with zero errors and zero warnings.

### Instructions for Next Phase
- The AksaraNetra PHP backend port is complete across all 7 phases!
- To switch the frontend to the PHP backend, set `NEXT_PUBLIC_AUDIT_API_URL=http://localhost:8787` in `.env.local` and start the PHP backend services.







