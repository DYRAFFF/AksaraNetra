# AksaraNetra PHP Backend Implementation Plan (Phase 1)

> **Document Status**: Complete Plan awaiting user approval before Phase 2 scaffolding.  
> **Target Architecture**: Laravel 11 (API-only, no Blade, no Vite) on PHP 8.3 with SQLite 3 (WAL mode) and Node.js bridge for Playwright/Chromium engine execution.

---

## 1. Final Folder Structure of `php-backend/`

The `php-backend/` directory will house a clean, minimal, API-only Laravel 11 application with dedicated bridge scripts, Artisan commands, configuration, database migrations, tests, and Docker setup.

```text
php-backend/
├── app/
│   ├── Console/
│   │   └── Commands/
│   │       ├── AuditCleanupCommand.php       # artisan audit:cleanup
│   │       └── AuditWorkCommand.php          # artisan audit:work
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── AuditArtifactController.php   # GET /audits/{id}/artifacts/{artifact}
│   │   │   ├── AuditCacheController.php      # GET /audits/cache
│   │   │   ├── AuditController.php           # POST /audits, GET /audits/{id}, DELETE /audits/{id}
│   │   │   ├── AuditResultController.php     # GET /audits/{id}/result
│   │   │   └── HealthController.php          # GET /health
│   │   └── Middleware/
│   │       ├── AksaraCorsMiddleware.php      # Drop-in replacement for setCors()
│   │       └── EnforceJsonRequestMiddleware.php # Validates Content-Type and MAX_BODY_BYTES
│   ├── Models/
│   │   └── AuditJob.php                      # Model / DTO for audit_jobs table
│   └── Services/
│       ├── AksaraConfig.php                  # Env configuration loading & range validation
│       ├── AuditRateLimiter.php              # Sliding window per-IP rate limit checker
│       ├── AuditorInterface.php              # Contract for running audits (swappable in tests)
│       ├── AuditorService.php                # Production auditor (bridge, checkpoint resume, PDF fallback)
│       ├── BridgeClient.php                  # Symfony Process wrapper for audit-cli.mjs
│       ├── JobStore.php                      # SQLite database operations mirroring store.mjs
│       ├── ReportRenderer.php                # HTML report generation byte-identical to report.mjs
│       └── SecurityService.php               # Token generation, HMAC digests, and IP resolver
├── bootstrap/
│   ├── app.php                               # Application bootstrap, root routing, middleware registration
│   └── providers.php                         # Service providers registration
├── bridge/
│   ├── audit-cli.mjs                         # Node ESM CLI bridging to engine/src/...
│   └── package.json                          # Marks bridge directory as Node ES module
├── config/
│   ├── aksaranetra.php                       # AksaraNetra specific config mapping
│   ├── app.php                               # Minimal app configuration
│   └── database.php                          # SQLite connection with WAL, busy_timeout=5000
├── database/
│   └── migrations/
│       └── 2026_10_14_000000_create_audit_jobs_table.php # audit_jobs table migration
├── docker/
│   ├── Dockerfile                            # Multi-stage/unified PHP 8.3 + Node 22 + Chromium image
│   └── entrypoint.sh                         # Container entrypoint script
├── docker-compose.yml                        # api & worker service definitions
├── routes/
│   └── api.php                               # Route definitions (mounted without /api prefix)
├── tests/
│   ├── Feature/
│   │   ├── ApiTest.php                       # Mirrors backend/tests/api.test.mjs
│   │   ├── CorsTest.php                      # CORS origin validation & preflight tests
│   │   ├── StoreTest.php                     # Mirrors backend/tests/store.test.mjs
│   │   └── WorkerTest.php                    # Mirrors backend/tests/worker.test.mjs
│   ├── Fakes/
│   │   └── FakeAuditor.php                   # In-memory fake auditor for test isolation
│   ├── Unit/
│   │   ├── ConfigTest.php                    # Environment variable validation tests
│   │   ├── ReportRendererTest.php            # Byte-identical HTML output tests against fixtures
│   │   └── SecurityTest.php                  # Cryptographic helper validation tests
│   ├── CreatesApplication.php                # Laravel test application bootstrapper
│   └── TestCase.php                          # Base test case setting up isolated temp DB
├── artisan                                   # Laravel CLI executable
├── composer.json                             # Pinned dependencies & autoload mappings
├── composer.lock                             # Locked package versions
├── .env.example                              # Environment template mirroring backend/.env.example
├── ANALYSIS.md                               # Phase 0 analysis document
├── NOTES.md                                  # Quirks & preserved behaviors documentation
├── PLAN.md                                   # This plan document
└── PROGRESS.md                               # Phase transition & handoff tracking
```

---

## 2. Class and File Responsibility List

Each class and file has exactly one clear responsibility:

| File / Class | Single Responsibility |
|---|---|
| `app/Services/AksaraConfig.php` | Validates environment variables against allowed numeric ranges/types and provides typed configuration defaults with Indonesian error messages. |
| `app/Services/SecurityService.php` | Generates cryptographically secure job IDs and tokens, computes HMAC-SHA256 digests and share tokens, performs constant-time comparisons, and extracts client IPs. |
| `app/Services/JobStore.php` | Encapsulates all SQLite database operations on `audit_jobs`, including atomic transaction claiming (`claimNext`), checkpoint restoration, progress clamping, and expiry deletion. |
| `app/Models/AuditJob.php` | Represents an `audit_jobs` record with integer millisecond timestamps, providing serialization to the exact `AuditJobPublic` JSON shape. |
| `app/Http/Middleware/AksaraCorsMiddleware.php` | Reproduces `setCors()` logic: allows trusted origins and originless requests, handles HTTP 204 preflights, and returns HTTP 403 `origin-not-allowed` for untrusted origins. |
| `app/Http/Middleware/EnforceJsonRequestMiddleware.php` | Rejects non-JSON Content-Type with HTTP 415 and requests exceeding `MAX_BODY_BYTES` with HTTP 413 before payload processing. |
| `app/Services/AuditRateLimiter.php` | Enforces the per-IP sliding 1-hour window audit limit by querying recent job creations from `JobStore`. |
| `app/Http/Controllers/HealthController.php` | Handles `GET /health`, returning HTTP 200 with worker readiness status and concurrency count. |
| `app/Http/Controllers/AuditCacheController.php` | Handles `GET /audits/cache?url=`, verifying the URL via the bridge and returning cache availability timestamps. |
| `app/Http/Controllers/AuditController.php` | Handles `POST /audits` (job queuing or instant cache hit), `GET /audits/{id}` (polling job status), and `DELETE /audits/{id}` (job cancellation). |
| `app/Http/Controllers/AuditResultController.php` | Handles `GET /audits/{id}/result`, verifying Bearer authorization, returning the snapshot result, or HTTP 409 if not completed. |
| `app/Http/Controllers/AuditArtifactController.php` | Handles `GET /audits/{id}/artifacts/{artifact}`, validating query `token` against `share_token_hash` and streaming artifact files with strict CSP and cache headers. |
| `app/Services/AuditorInterface.php` | Defines the contract for executing an accessibility audit and reporting progress, enabling seamless test substitution. |
| `app/Services/AuditorService.php` | Implements `AuditorInterface` in production by invoking `BridgeClient`, managing checkpoint restoration, triggering HTML report generation, and handling PDF fallback. |
| `app/Services/BridgeClient.php` | Manages execution of the Node bridge CLI (`audit-cli.mjs`) via Symfony Process, parsing streaming NDJSON progress lines, and terminating child processes cleanly. |
| `app/Services/ReportRenderer.php` | Renders the standalone audit HTML report byte-identically to `backend/src/report.mjs` using deterministic string escaping. |
| `app/Console/Commands/AuditWorkCommand.php` | Executes the long-running single-concurrency worker process (`audit:work`), polling for jobs, monitoring DB cancellation requests, and running periodic cleanups. |
| `app/Console/Commands/AuditCleanupCommand.php` | Executes standalone or scheduled purging (`audit:cleanup`) of expired audit jobs and their filesystem artifact directories. |
| `bridge/audit-cli.mjs` | Node.js CLI script wrapping `engine` modules (`checkUrl`, `auditUrl`, `renderPdf`), communicating with PHP via stdin/stdout NDJSON. |
| `database/migrations/2026_10_14_000000_create_audit_jobs_table.php` | Defines the SQLite schema for `audit_jobs` matching `backend/src/store.mjs` with exact column types, CHECK constraints, and indexes. |

---

## 3. Pinned Composer Packages & Laravel Version

To ensure maximum performance, minimal dependency footprint, and zero unnecessary bloat, we pin **Laravel 11** on **PHP 8.3** with strictly justified packages.

### Pinned Framework Version
- `laravel/framework`: `^11.0` (pinned to current stable release)
  - *Justification*: Modern, lean bootstrap (`bootstrap/app.php` with no heavy Kernel boilerplate), full PHP 8.3 support, native route prefix customization, and built-in testing harness.

### Required Production Dependencies
1. `php`: `^8.3`
   - *Justification*: Hard project constraint; required for typed properties, read-only classes, and optimal performance.
2. `symfony/process`: `^7.0`
   - *Justification*: Required by `BridgeClient` to asynchronously run `node bridge/audit-cli.mjs`, stream output line-by-line via NDJSON, and send OS signals (`SIGTERM`) for cancellation.
3. `ext-pdo_sqlite`: `*`
   - *Justification*: Native SQLite driver for database operations.
4. `ext-json`: `*`
   - *Justification*: Required for parsing bridge NDJSON and serializing API payloads.
5. `ext-mbstring`: `*`
   - *Justification*: Multi-byte string manipulation for URL parsing and Indonesian character encoding.
6. `ext-openssl`: `*`
   - *Justification*: `hash_hmac('sha256')` and secure random byte generation in `SecurityService`.

### Development & Testing Dependencies
1. `phpunit/phpunit`: `^11.0`
   - *Justification*: Primary test execution framework.
2. `mockery/mockery`: `^1.6`
   - *Justification*: Allows mocking low-level OS process streams or bridge responses when testing edge conditions.
3. `orchestra/testbench`: `^9.0`
   - *Justification*: Standard Laravel testing package allowing isolated app instantiation during unit/feature testing.

*Note on omitted packages*:
- **No Blade / Vite**: The backend is API-only. HTML reports are rendered via `ReportRenderer` string concatenation matching `report.mjs`.
- **No Sanctum / Passport**: Authentication uses lightweight HMAC-SHA256 tokens matching `security.mjs`.
- **No Fruitcake / Laravel CORS package**: CORS is handled exclusively by custom `AksaraCorsMiddleware` to guarantee exact reproduction of `setCors()`.

---

## 4. Test Strategy

The test suite ensures 100% parity with the existing Node test suite without requiring Chromium during routine unit and feature test execution.

### 4.1 Node to PHP Test Mapping Matrix

| Original Node Test (`backend/tests/`) | Target PHP Test (`php-backend/tests/`) | Assertions & Behavior Verified |
|---|---|---|
| `api.test.mjs: "creates an anonymous job but hides it without its token"` | `Feature/ApiTest.php: test_creates_anonymous_job_and_hides_without_token` | `POST /audits` returns HTTP 202, `jobId` prefixed with `aud_`, `accessToken` length > 30. Unauthenticated `GET /audits/{id}` returns 404; with `Authorization: Bearer <token>` returns 200 with `status: "queued"`. |
| `api.test.mjs: "returns expiring reader and PDF links only after completion"` | `Feature/ApiTest.php: test_returns_expiring_reader_and_pdf_links_only_after_completion` | `GET /audits/{id}/result` returns 200 after completion with links containing `?token=`. `GET /audits/{id}/artifacts/reader?token=...` returns 200 with `text/html`. |
| `api.test.mjs: "lets the user explicitly reuse a stored result"` | `Feature/ApiTest.php: test_lets_user_explicitly_reuse_stored_result` | `GET /audits/cache?url=...` returns `available: true`. `POST /audits` with `reuseExisting: true` returns HTTP 200 immediately with `status: "completed"` and copies artifacts. |
| `api.test.mjs: "enforces per-IP rate limit and supports queued cancellation"` | `Feature/ApiTest.php: test_enforces_rate_limit_and_queued_cancellation` | Exceeding `rateLimit` returns HTTP 429 with `Retry-After: 3600`. `DELETE /audits/{id}` returns HTTP 202 with `status: "cancelled"`. |
| `api.test.mjs: "rejects untrusted browser origins"` | `Feature/CorsTest.php: test_rejects_untrusted_browser_origins` | Request with `Origin: https://evil.test` returns HTTP 403 `{"error":"origin-not-allowed","message":"Origin frontend tidak diizinkan."}`. Trusted origin returns CORS headers. Missing origin is allowed. Preflight `OPTIONS` returns 204. |
| `store.test.mjs: "persists queue state and recovers interrupted jobs once"` | `Feature/StoreTest.php: test_persists_queue_state_and_recovers_interrupted_jobs` | Simulates server restart with running job. Attempt 1 recovers to `queued` (progress 0 or 90 with snapshot). Attempt 2 recovery fails with `server-restarted`. |
| `store.test.mjs: "returns expired artifact folders before deleting rows"` | `Feature/StoreTest.php: test_returns_expired_artifact_folders_before_deleting_rows` | `deleteExpired()` identifies expired artifact dirs before removing database rows. |
| `worker.test.mjs: "runs only one audit at a time"` | `Feature/WorkerTest.php: test_runs_only_one_audit_at_a_time` | Concurrency is strictly 1: two queued jobs are processed sequentially, maximum concurrent active runs is 1. |
| `worker.test.mjs: "retries one transient failure and then succeeds"` | `Feature/WorkerTest.php: test_retries_transient_failure_and_succeeds` | Transient error code (`browser-failed`) on attempt 1 increments attempts, resets to `queued`, and succeeds on attempt 2. |
| `worker.test.mjs: "cancels a running audit and removes partial artifacts"` | `Feature/WorkerTest.php: test_cancels_running_audit_and_removes_partial_artifacts` | Running job receives cancellation flag, aborts auditor, transitions to `cancelled`, and purges partial artifact directory. |

### 4.2 Fake Auditor Implementation (Chromium-Free Testing)
- `AuditorInterface` is bound in Laravel's service container.
- In unit and feature tests, `FakeAuditor` is bound as a singleton.
- `FakeAuditor` enables tests to:
  - Return predefined snapshot JSON fixtures instantly without opening a browser.
  - Emit synthetic progress events (`onProgress(['progress' => 50, 'stage' => '...'])`).
  - Throw transient errors (`browser-failed`, `audit-failed`) on designated attempt counts.
  - Block until a cancellation flag is detected to verify graceful abort and artifact directory cleanup.
- This guarantees tests run in milliseconds, can run in CI without Chromium installed, and are 100% deterministic.

### 4.3 Phase 7 Parity Check Strategy
Phase 7 will execute automated cross-system verification:
1. **Automated Endpoint Contract Test**:
   - A Node test script using `fetch()` will run against the booted PHP backend (`http://127.0.0.1:8787`).
   - Verifies exact HTTP statuses, headers, and JSON keys against expected snapshots.
2. **Byte-Identical Report Parity**:
   - Compares the HTML output of PHP `ReportRenderer::render()` against Node `renderAuditReport()` using the exact same snapshot and report JSON fixture, asserting byte-for-byte identity (`md5_file` and string diff).
3. **Frontend Contract Validation**:
   - Execute the existing test suite:
     ```bash
     npm run test:frontend
     ```
   - Confirms that Next.js frontend requirements (`src/lib/audit-api.ts`) remain 100% satisfied.

---

## 5. SQLite Process Safety & Multi-Process Concurrency

The API and worker run in separate processes:
- **API**: Serves incoming HTTP requests (`php artisan serve` or PHP-FPM / Nginx).
- **Worker**: Long-running background CLI process (`php artisan audit:work`).

To guarantee data integrity and eliminate concurrency issues, SQLite is configured and accessed as follows:

### 5.1 WAL Mode & Connection Pragmas
Every database connection established by Laravel executes the following PRAGMA statements immediately upon opening:
```sql
PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;
PRAGMA busy_timeout = 5000;
```
- **Write-Ahead Logging (WAL)**: Allows multiple readers to query the database concurrently without being blocked by an active writer, and allows writers to commit without blocking readers.
- **Busy Timeout (5000 ms)**: If a write lock is temporarily held, SQLite automatically waits up to 5 seconds with exponential backoff before throwing a `database is locked` error.

### 5.2 Atomic Job Claiming (`BEGIN IMMEDIATE`)
In `JobStore::claimNext()`, claiming the next queued job is protected by an immediate write transaction:
```sql
BEGIN IMMEDIATE;

SELECT id FROM audit_jobs 
WHERE status = 'queued' AND cancel_requested = 0 
ORDER BY created_at ASC 
LIMIT 1;

UPDATE audit_jobs 
SET status = 'running',
    stage = 'Menyiapkan audit',
    progress = 5,
    attempts = attempts + 1,
    started_at = COALESCE(started_at, :now),
    updated_at = :now
WHERE id = :id AND status = 'queued';

COMMIT;
```
- `BEGIN IMMEDIATE` reserves the write lock at the start of the transaction before reading the queued rows.
- This completely prevents race conditions where two concurrent worker invocations could read the same job before updating it.

### 5.3 Cross-Process Cancellation Communication
- When a user calls `DELETE /audits/{id}`, the API process executes `JobStore::requestCancel($id)`:
  - If the job is still `queued`, it transitions immediately to `status = 'cancelled'`.
  - If the job is `running`, it sets `cancel_requested = 1` and `stage = 'Membatalkan audit'`.
- The long-running worker process running `php artisan audit:work` polls `audit_jobs` every ~1 second while waiting for the Node bridge process.
- Upon detecting `cancel_requested = 1`, the worker sends `SIGTERM` to the child Node process, waits for process exit, updates status to `cancelled`, and deletes the artifact directory.

---

## 6. Risks, Quirks, and Open Questions

| Item | Description & Risk | Proposed Solution / Answer |
|---|---|---|
| **1. Route Prefix** | Laravel 11 routes in `routes/api.php` default to an `/api` prefix, but Next.js calls `/health`, `/audits`, etc., at the root. | Configure `bootstrap/app.php` with `apiPrefix: ''` (or register the routes without prefix) so endpoints match `/health`, `/audits`, and `/audits/{id}` verbatim. |
| **2. Timestamp Format** | Node SQLite stores timestamps as integer milliseconds (`Date.now()`), serialized as ISO strings in `publicJob`. Laravel models often use strings or seconds. | Store timestamps strictly as `INTEGER` (milliseconds). Disable automatic Eloquent timestamps (`$timestamps = false`), handle milliseconds explicitly with `(int)(microtime(true) * 1000)`, and format ISO strings via `gmdate('Y-m-d\TH:i:s.v\Z', $ms / 1000)`. |
| **3. Report HTML Parity** | String escaping differences between JS `.replace()` and PHP `htmlspecialchars()` could produce differing HTML. | Implement a dedicated `ReportRenderer` using exact string replacements matching `backend/src/report.mjs:1-5`: `&` -> `&amp;`, `<` -> `&lt;`, `>` -> `&gt;`, `"` -> `&quot;`. Verify with PHPUnit fixture diff. |
| **4. Artifact Token Authentication Quirk** | `app.mjs` quirk: artifact routes strictly check query parameter `token` against `share_token_hash`. `Authorization: Bearer` headers are ignored and return 404. | Strictly follow this rule in `AuditArtifactController`. Documented in `NOTES.md`. |
| **5. `reportPdf` Artifact 404 Quirk** | If PDF generation fails, `app.mjs` still links to `report.pdf`, which then returns 404 because no route exists for `report.html`. | Preserve this behavior verbatim in PHP: return 404 if `report.pdf` does not exist on disk, matching Node behavior. Documented in `NOTES.md`. |
| **6. Cache Hit Instant 200 OK** | `POST /audits` returns HTTP 202 for new jobs, but returns HTTP 200 when `reuseExisting: true` results in a cache hit. | In `AuditController`, check `reuseExisting` and if a valid cache hit exists, copy artifacts, call `JobStore::completeQueued()`, and return HTTP 200. |
| **7. Process Signaling Across OSes** | `SIGTERM` process termination behaves differently on Windows vs Linux during local development. | Use `Symfony\Component\Process\Process::stop()` which handles platform-specific termination (process tree termination on Windows, `SIGTERM` on POSIX). |
| **8. Memory & Body Size Enforcement** | PHP web servers buffer POST bodies in memory or temporary files, which could bypass `MAX_BODY_BYTES` checks if done naively. | In `EnforceJsonRequestMiddleware`, inspect `Content-Length` header first and stream `php://input` up to `MAX_BODY_BYTES`, immediately aborting with HTTP 413 and `"Body permintaan terlalu besar."` if exceeded. |

---

## 7. Next Steps (Phase 2 Preview)

Upon approval of this plan:
1. Scaffold Laravel 11 in `php-backend/` with API-only configuration.
2. Implement `AksaraConfig` with exact environment variables, range validations, and Indonesian error messages.
3. Implement `SecurityService` with cryptographic token generation, HMAC digests, and IP resolver.
4. Implement the SQLite database migration for `audit_jobs` matching the schema and CHECK constraints.
5. Add unit tests for Config and Security services.
