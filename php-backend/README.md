# AksaraNetra PHP Backend

A high-performance, drop-in replacement for the AksaraNetra Node.js audit backend, built with **Laravel 11 on PHP 8.3** and **SQLite**, packaged for **Docker** and production workloads.

The PHP backend is designed to replace `backend/` with **zero code changes** to the existing Next.js frontend (`src/`). The frontend interacts with this backend purely by pointing `NEXT_PUBLIC_AUDIT_API_URL` to its endpoint.

---

## 1. Architecture Overview

The AksaraNetra audit system consists of three decoupled components:
1. **Frontend (Next.js 16)**: Web UI that initiates audit jobs, polls progress, and renders accessibility reports.
2. **PHP Backend (Laravel 11, API-only)**: Serves public HTTP endpoints, handles rate limiting, authenticates token signatures, persists audit jobs in SQLite (WAL mode), and generates byte-identical HTML reports.
3. **Audit Worker & Node Bridge**: Long-running background worker process that claims jobs sequentially and invokes the Playwright/Chromium engine (`engine/`) via a lightweight Node ESM bridge (`php-backend/bridge/audit-cli.mjs`).

```
 +-----------------------------------------------------------------------+
 |                     Next.js Frontend (Port 3000)                     |
 +-----------------------------------------------------------------------+
                     |                                ^
       POST /audits  |                                | Poll status &
      GET /cache     |                                | Fetch result
                     v                                |
 +-----------------------------------------------------------------------+
 |                    PHP HTTP API (Laravel / Port 8787)                 |
 |  - AksaraCorsMiddleware (strict origin check, credentials, preflight) |
 |  - EnforceJsonRequestMiddleware (415 / 413 / 400 enforcement)        |
 |  - SecurityService (constant-time token & HMAC digest checks)         |
 |  - ReportRenderer (byte-identical HTML report generation)             |
 +-----------------------------------------------------------------------+
                     |                                ^
       Atomic Claim  |                                | Status & Progress
     BEGIN IMMEDIATE |                                | Updates
                     v                                |
 +-----------------------------------------------------------------------+
 |                     Shared SQLite Database & Disk                     |
 |        - /data/jobs.sqlite (WAL mode, busy_timeout=5000ms)            |
 |        - /data/artifacts/{jobId}/ (HTML, PDF, PNG, JSON snapshots)    |
 +-----------------------------------------------------------------------+
                     ^                                |
       Poll Queue    |                                | Read Checkpoint
                     |                                v
 +-----------------------------------------------------------------------+
 |                   PHP Audit Worker (php artisan audit:work)           |
 |      - Concurrency 1, timeout enforcement, transient retry (2x)       |
 |      - Periodic artifact cleanup, process cancellation polling        |
 +-----------------------------------------------------------------------+
                                     |
                       Spawns child  | Streams NDJSON progress
                       process       v & audit results
 +-----------------------------------------------------------------------+
 |                 Node Bridge (bridge/audit-cli.mjs)                    |
 |                     Subcommands: check-url, audit, render-pdf         |
 +-----------------------------------------------------------------------+
                                     |
                       Direct ESM    |
                       import        v
 +-----------------------------------------------------------------------+
 |              Audit Engine (engine/src: Playwright + Chromium)         |
 +-----------------------------------------------------------------------+
```

---

## 2. Directory Structure

```
php-backend/
├── app/
│   ├── Console/Commands/
│   │   ├── AuditCleanupCommand.php    # Artisan command: php artisan audit:cleanup
│   │   └── AuditWorkCommand.php       # Artisan command: php artisan audit:work
│   ├── Exceptions/                    # Custom typed audit exceptions
│   ├── Http/
│   │   ├── Controllers/               # API controllers (Health, Cache, Audit, Result, Artifact)
│   │   └── Middleware/                # CORS, Response Headers, JSON enforcement
│   ├── Models/AuditJob.php            # Audit job DTO / Active Record model
│   └── Services/
│       ├── AksaraConfig.php           # Strict environment config & validation
│       ├── Auditor.php                # Auditor lifecycle & checkpoint resume
│       ├── AuditWorker.php            # Queue worker state machine
│       ├── BridgeClient.php           # Symfony Process wrapper for audit-cli.mjs
│       ├── JobStore.php               # SQLite repository with atomic transactions
│       ├── ReportRenderer.php         # Byte-identical HTML report generator
│       └── SecurityService.php        # HMAC-SHA256, base64url, timing-safe checks
├── bootstrap/app.php                  # API-only framework bootstrap (no Blade/Vite)
├── bridge/
│   └── audit-cli.mjs                  # Node CLI bridge (check-url, audit, render-pdf)
├── config/
│   ├── aksaranetra.php                # AksaraNetra configuration wrapper
│   └── database.php                   # SQLite WAL mode & busy timeout connection
├── database/migrations/               # Migration creating audit_jobs table
├── docker/
│   ├── Dockerfile                     # Multi-ecosystem production image
│   ├── entrypoint.sh                  # Container startup script (migrations + supervisor/worker)
│   ├── nginx.conf                     # Unprivileged Nginx configuration on port 8787
│   ├── php-fpm.conf                   # PHP-FPM pool configuration with clear_env = no
│   └── supervisord.conf               # Process manager for API container
├── docker-compose.yml                 # Multi-service stack (api + worker + shared volume)
├── Dockerfile                         # Root symlink/alias to docker/Dockerfile
├── public/index.php                   # HTTP front controller
├── routes/api.php                     # Pure API routes without /api prefix
├── scripts/
│   ├── parity-check.mjs               # 42-check side-by-side Node vs PHP parity verification
│   └── test-frontend-flow.mjs         # Live Next.js frontend workflow integration check
├── tests/
│   ├── Feature/                       # ApiTest, AuditorTest, BridgeTest, CorsTest, StoreTest, WorkerTest
│   ├── Fixtures/reports/              # Byte-for-byte report HTML test fixtures from Node
│   └── Unit/                          # ConfigTest, ReportRendererTest, SecurityTest
├── ANALYSIS.md                        # Architectural analysis of the migration
├── NOTES.md                           # Documented quirks, edge cases, and behavior preservation rules
├── PLAN.md                            # Comprehensive 7-phase implementation plan
└── PROGRESS.md                        # Phase completion logs and execution tracking
```

---

## 3. Environment Variables Reference

All configuration keys match the Node.js backend environment variables and default behaviors:

| Variable | Type | Default | Description |
|---|---|---|---|
| `PORT` | Integer | `8787` | HTTP listening port (1–65535). |
| `HOST` | String | `127.0.0.1` | Network interface to bind HTTP server to (`0.0.0.0` in Docker). |
| `APP_ENV` / `NODE_ENV` | String | `development` | Environment mode (`development`, `testing`, `production`). |
| `BACKEND_SECRET` | String | *dev fallback* | HMAC secret key. Minimum 32 characters in production. |
| `BACKEND_DATA_DIR` | String | `backend/data` | Path to persistent storage (`jobs.sqlite` and `artifacts/`). |
| `FRONTEND_ORIGINS` | Comma list | `http://localhost:3000` | Allowed CORS origins for browser requests. |
| `AUDIT_RATE_LIMIT` | Integer | `10` (dev) / `5` (prod) | Max audits per IP per hour (1–100). |
| `AUDIT_TIMEOUT_MS` | Integer | `180000` (3m) | Audit execution deadline in ms (30,000–600,000). |
| `RESULT_RETENTION_MS` | Integer | `604800000` (7d) | Completed job retention in ms before expiration (1h–30d). |
| `MAX_BODY_BYTES` | Integer | `8192` (8KB) | Max allowed POST request body size in bytes (1024–65536). |
| `CLEANUP_INTERVAL_MS` | Integer | `3600000` (1h) | Interval between automated expired job cleanups in ms. |
| `TRUST_PROXY` | Boolean (`0`/`1`) | `0` | If `1`, extracts client IP from `X-Forwarded-For`. |
| `CHROMIUM_LOW_RESOURCE_MODE` | Boolean (`0`/`1`) | `1` | Disables GPU and sandbox overhead in memory-constrained environments. |
| `PLAYWRIGHT_BROWSERS_PATH` | String | `/app/.playwright` | Directory where Playwright Chromium binaries are installed. |

---

## 4. Local Setup (Without Docker)

### Prerequisites
- **PHP 8.3+** with extensions: `pdo_sqlite`, `mbstring`, `openssl`, `pcntl`, `json`.
- **Composer 2+**.
- **Node.js 22+** with npm.
- **Playwright Chromium**: installed in `engine/`.

### Setup Steps

1. **Install Engine Dependencies & Playwright**:
   ```bash
   cd engine
   npm ci
   npx playwright install --with-deps chromium
   cd ..
   ```

2. **Install PHP Dependencies**:
   ```bash
   cd php-backend
   composer install
   ```

3. **Configure Environment**:
   ```bash
   cp .env.example .env
   ```
   *(Ensure `BACKEND_SECRET` is set and `FRONTEND_ORIGINS` includes your frontend URL).*

4. **Run Database Migrations**:
   ```bash
   php artisan migrate
   ```

5. **Start HTTP API Server**:
   ```bash
   php artisan serve --host=127.0.0.1 --port=8787
   ```

6. **Start Audit Worker (in a separate terminal)**:
   ```bash
   php artisan audit:work
   ```

---

## 5. Docker Deployment

The Docker setup uses a single unified production image (`php:8.3-fpm-bookworm` + Node 22 + Playwright Chromium) run as an unprivileged user (`aksara`, UID 1000) with two containers sharing a named volume:
- `api`: Runs Nginx + PHP-FPM managed by Supervisord on port 8787.
- `worker`: Runs `php artisan audit:work`.

### Running with Docker Compose

```bash
cd php-backend

# 1. Prepare environment
cp .env.example .env

# 2. Build and start services in background
docker compose up --build -d

# 3. Verify healthcheck
curl -i http://localhost:8787/health

# 4. View logs
docker compose logs -f
```

### Stopping Services
```bash
docker compose down
```

---

## 6. Running Tests & Verifications

### 1. Run Tests via Composer Scripts
The test suite contains 178 tests with 933 assertions covering real user scenarios (`UC-USR`), failure & abnormal cases (`UC-FAIL`), and security/attack prevention (`UC-SEC`).

- **Full test suite**:
  ```bash
  composer test
  ```
- **Real user and failure use cases (`UC-USR`, `UC-FAIL`)**:
  ```bash
  composer test:usecases
  ```
- **Security & attack prevention tests (`UC-SEC`)**:
  ```bash
  composer test:security
  ```
- **Line coverage report (requires Xdebug)**:
  ```bash
  composer test:coverage
  ```

Detailed use cases and security guarantees are documented in [`tests/USE_CASES.md`](./tests/USE_CASES.md) and [`tests/TEST_REPORT.md`](./tests/TEST_REPORT.md).

### 2. Run Automated Parity Check Script
The automated parity script starts isolated Node and PHP instances with identical seeds and tests 42 distinct HTTP scenarios side-by-side, comparing status codes, CORS headers, CSP headers, cache directives, location headers, retry-after headers, and JSON body structures:
```bash
node php-backend/scripts/parity-check.mjs
```
*Expected: 42 passed, 0 failed.*

Options:
- `--node-url <url>`: Target an existing Node backend instead of spawning one.
- `--php-url <url>`: Target an existing PHP backend instead of spawning one.
- `--php-bin <path>`: Specify path to PHP executable.
- `--keep-alive`: Keep spawned test servers running for manual inspection.

### 3. Run Live Frontend Workflow Check
Simulates the exact 8-step user audit lifecycle using `src/lib/audit-api.ts` semantics against a live PHP API server and worker:
```bash
node php-backend/scripts/test-frontend-flow.mjs
```
*Expected: Cache check -> Job creation (202) -> Polling (queued) -> Worker completion (100) -> Result retrieval -> Reader/Patched/Screenshot downloads -> Cache hit verification: 100% passing.*

### 4. Run Frontend Contract Tests
Validates that frontend contracts and expectations remain completely satisfied:
```bash
npm run test:frontend
```
*Expected: 5 tests, 5 passing.*

---

## 7. Switching Frontend to PHP Backend

Switching the Next.js frontend from the Node backend to the PHP backend requires **zero code changes**:

1. In `.env.local` (or your environment variables for Next.js):
   ```env
   NEXT_PUBLIC_AUDIT_API_URL=http://localhost:8787
   ```

2. Start the PHP backend (either via `docker compose up` or local `php artisan serve` on port 8787).
3. Start the Next.js frontend:
   ```bash
   npm run dev
   ```
4. Access `http://localhost:3000/periksa` in your browser. All audit checks, progress polling, cache lookups, report downloads, and reader views will transparently communicate with the PHP backend.

---

## 8. Preserved Behaviors & Quirks

All quirks and behavioral idiosyncrasies from the Node backend have been preserved verbatim in the PHP implementation to guarantee drop-in compatibility. See [`php-backend/NOTES.md`](./NOTES.md) for full details:

1. **`reportPdf` Link Resolution**: Artifact download route strictly matches `/report.pdf`. If PDF generation is skipped in low-memory environments, the API link still points to `/report.pdf` (returning 404).
2. **Strict Query Token on Artifacts**: Artifact routes (`/artifacts/{artifact}?token=...`) strictly check the query parameter against `share_token_hash`. Bearer authorization headers are ignored.
3. **Instant Cache Reuse (HTTP 200 vs 202)**: When `reuseExisting: true` matches a completed audit, the backend completes immediately and responds with HTTP 200 OK instead of HTTP 202 Accepted.
4. **Monotonic Progress Clamping**: Progress updates are clamped between 0 and 99 in SQL (`MAX(progress, ?)`), only reaching 100 upon final completion.
5. **Report Indentation Parity**: WCAG regression JSON blocks in HTML reports match Node's exact 2-space indentation format.
6. **Path Traversal Protection**: Route pattern `->where('artifact', '.*')` ensures traversed requests like `..%2fjobs.sqlite` reach the controller and return the standard Indonesian error message `Artefak tidak ditemukan.`.
