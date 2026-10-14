# AksaraNetra Backend Analysis (Phase 0)

> **Document purpose**: Comprehensive architectural and behavioral analysis of the Node.js backend (`backend/`), accessibility engine (`engine/`), and Next.js frontend consumption (`src/lib/audit-api.ts`). This serves as the authoritative blueprint for the PHP/Laravel port in `php-backend/`.
> **Traceability**: Every specification, query, schema definition, and error message in this document is traced directly to the audited source files.

---

## 1. Full Endpoint Contract Table

The backend provides a REST-like API consumed by the Next.js frontend and external clients.

### 1.1 Global HTTP Behaviors & CORS
- **CORS Middleware (`backend/src/app.mjs:84-96`)**:
  - Inspected header: `Origin`.
  - If `Origin` is missing: Allowed (e.g., server-to-server requests, health checks, curl).
  - If `Origin` is present and contained in `FRONTEND_ORIGINS` (comma-separated, default `http://localhost:3000`):
    - `Access-Control-Allow-Origin`: `<Origin>`
    - `Vary`: `Origin`
    - `Access-Control-Allow-Methods`: `GET, POST, PUT, DELETE, OPTIONS`
    - `Access-Control-Allow-Headers`: `Authorization, Content-Type`
    - `Access-Control-Allow-Credentials`: `true`
    - `Access-Control-Max-Age`: `600`
  - If `Origin` is present but **NOT** in `FRONTEND_ORIGINS`:
    - Terminated with HTTP **403 Forbidden**.
    - Response body: `{"error":"origin-not-allowed","message":"Origin frontend tidak diizinkan."}`
- **Preflight `OPTIONS` (`backend/src/app.mjs:133`)**:
  - Responds with HTTP **204 No Content** (empty body).
- **Default Headers (`send()` in `backend/src/app.mjs:22-32`)**:
  - `Content-Type`: `application/json; charset=utf-8`
  - `Content-Length`: `<byteLength>`
  - `Cache-Control`: `no-store`
  - `X-Content-Type-Options`: `nosniff`
- **Default Error Shape (`backend/src/app.mjs:254-258`)**:
  - On body/JSON parsing error: HTTP 400/413/415 with `{"error":"bad-request","message":"..."}`
  - On unexpected crash/exception: HTTP 500 with `{"error":"internal-error","message":"Backend mengalami kesalahan internal."}`

---

### 1.2 Endpoint Specifications

| Method | Path | Auth | Request Headers / Body / Query | Success Status | Response Body Shape | Error Statuses & Conditions |
|---|---|---|---|---|---|---|
| `GET` | `/health` | None | None | **200 OK** | `{"ok":true,"worker":"ready","concurrency":1}` | `403` if untrusted origin. |
| `GET` | `/audits/cache` | None | Query: `?url=<targetUrl>` | **200 OK** | `{"available":boolean,"capturedAt":string\|null,"expiresAt":string\|null}` | `400` if URL invalid (`{"error":code,"message":msg}`). |
| `POST` | `/audits` | None | Headers: `Content-Type: application/json`<br>Body: `{"url":string,"reuseExisting"?:boolean}` (Max: `MAX_BODY_BYTES`, default 8192) | **202 Accepted** (queued) / **200 OK** (cached reuse) | `{"jobId":string,"accessToken":string,"status":"queued"\|"completed","statusPath":"/audits/<jobId>","expiresAt":string}`<br>Header: `Location: /audits/<jobId>` | `415` if non-JSON Content-Type.<br>`413` if body > max bytes.<br>`400` if invalid JSON or URL policy rejected.<br>`429` if rate limited (`Retry-After: 3600`). |
| `GET` | `/audits/{id}` | Bearer token in `Authorization: Bearer <token>` or query `?accessToken=<token>` | None | **200 OK** | `{"job": AuditJobPublic}` | `404` if job not found or token invalid/missing (`{"error":"not-found","message":"Audit tidak ditemukan."}`). |
| `DELETE` | `/audits/{id}` | Bearer token in `Authorization: Bearer <token>` or query `?accessToken=<token>` | None | **202 Accepted** | `{"job": AuditJobPublic}` | `404` if job not found or token invalid/missing (`{"error":"not-found","message":"Audit tidak ditemukan."}`). |
| `GET` | `/audits/{id}/result` | Bearer token in `Authorization: Bearer <token>` or query `?accessToken=<token>` | None | **200 OK** | `{"job": AuditJobPublic,"result":object,"links":{"reader":"...","patched":"...","reportPdf":"...","screenshot":"..."}}` | `404` if job not found or token invalid.<br>`409` if job status is not `completed` (`{"error":"not-ready","job":AuditJobPublic}`). |
| `GET` | `/audits/{id}/artifacts/{artifact}` | Derived share token strictly via query: `?token=<shareToken>` | URL params:<br>`{id}`: Job ID<br>`{artifact}`: `reader` \| `patched` \| `report.pdf` \| `screenshot` | **200 OK** | Raw file stream with specific Content-Type & security headers | `404` if job not found or token invalid (`{"error":"not-found","message":"Artefak tidak ditemukan."}`).<br>`410` if job status != `completed` or expired (`{"error":"expired","message":"Artefak sudah kedaluwarsa."}`).<br>`404` if file missing or path traversal attempt. |
| Any | Unmatched path | None | Any | **404 Not Found** | `{"error":"not-found","message":"Endpoint tidak ditemukan."}` | Always 404 for unmatched routes. |

---

### 1.3 Schema: `AuditJobPublic` Shape
Used across `GET /audits/{id}`, `DELETE /audits/{id}`, `GET /audits/{id}/result`, and 409 conflict payload (`backend/src/app.mjs:60-76`):
```json
{
  "jobId": "aud_kU03...",
  "url": "https://example.com/",
  "status": "queued",
  "stage": "Menunggu antrean",
  "progress": 0,
  "queuePosition": 1,
  "attempts": 0,
  "canCancel": true,
  "createdAt": "2026-10-14T14:00:00.000Z",
  "startedAt": null,
  "finishedAt": null,
  "expiresAt": "2026-10-21T14:00:00.000Z",
  "error": null
}
```
*Note on `canCancel`*: Computed as `job.status === "queued" || job.status === "running"`.
*Note on `queuePosition`*: Count of jobs where `status = 'queued' AND created_at <= job.created_at`. Returns `null` if the job is not in `queued` status.
*Note on `error`*: `null` if `job.error_code` is null; otherwise `{"code": job.error_code, "message": job.error_message}`.

---

### 1.4 Artifact Serving Rules (`backend/src/app.mjs:15-20, 98-123`)
Artifact definitions mapping:
- `reader` -> `file: "reader.html"`, `Content-Type: text/html; charset=utf-8`
- `patched` -> `file: "patched.html"`, `Content-Type: text/html; charset=utf-8`
- `report.pdf` -> `file: "report.pdf"`, `Content-Type: application/pdf`
- `screenshot` -> `file: "after.png"`, `Content-Type: image/png`

Headers returned on HTTP 200:
- `Content-Type`: `<as defined above>`
- `Content-Length`: File byte size
- `Cache-Control`: `private, max-age=300`
- `X-Content-Type-Options`: `nosniff`
- `Content-Security-Policy`: `default-src 'none'; img-src https: http: data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'`

---

## 2. Database Schema & Store Queries

The database is SQLite 3 in WAL mode. In the target PHP architecture, the table is named `audit_jobs` to avoid colliding with Laravel's queue `jobs` table (`backend/src/store.mjs:39-63`).

### 2.1 Table Schema (`audit_jobs`)

```sql
CREATE TABLE audit_jobs (
  id TEXT PRIMARY KEY,
  token_hash TEXT NOT NULL,
  share_token_hash TEXT NOT NULL,
  ip_hash TEXT NOT NULL,
  target_url TEXT NOT NULL,
  status TEXT NOT NULL CHECK(status IN ('queued','running','completed','failed','cancelled')),
  stage TEXT NOT NULL,
  progress INTEGER NOT NULL CHECK(progress BETWEEN 0 AND 100),
  attempts INTEGER NOT NULL DEFAULT 0,
  cancel_requested INTEGER NOT NULL DEFAULT 0,
  result_json TEXT,
  error_code TEXT,
  error_message TEXT,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL,
  started_at INTEGER,
  finished_at INTEGER,
  expires_at INTEGER NOT NULL,
  artifact_dir TEXT NOT NULL
);

CREATE INDEX jobs_queue_idx ON audit_jobs(status, created_at);
CREATE INDEX jobs_ip_idx ON audit_jobs(ip_hash, created_at);
CREATE INDEX jobs_expiry_idx ON audit_jobs(expires_at);
```

PRAGMA statements executed on connection:
- `PRAGMA journal_mode=WAL;`
- `PRAGMA foreign_keys=ON;`
- `PRAGMA busy_timeout=5000;`

### 2.2 Store Operations & Exact Queries (`backend/src/store.mjs`)

| Operation | Query / SQL | Purpose & Behavior |
|---|---|---|
| `recoverInterrupted()` | `SELECT id, artifact_dir, attempts, progress FROM audit_jobs WHERE status = 'running'` | Runs at startup. Checks each running job:<br>1. If `artifact_dir/snapshot.json` exists: sets `status='queued', stage='Melanjutkan finalisasi dari checkpoint tersimpan', progress=90, error_code=NULL, error_message=NULL, updated_at=?, finished_at=NULL`.<br>2. Else if `attempts < 2`: sets `status='queued', stage='Dipulihkan setelah server restart', progress=0, error_code=NULL, error_message=NULL, updated_at=?, finished_at=NULL`.<br>3. Else: sets `status='failed', stage='Gagal setelah server restart', progress=progress, error_code='server-restarted', error_message='Audit terputus dua kali karena server restart.', updated_at=?, finished_at=?`. |
| `createJob(job)` | `INSERT INTO audit_jobs (...) VALUES (?, ?, ?, ?, ?, 'queued', 'Menunggu antrean', 0, 0, 0, NULL, NULL, NULL, ?, ?, NULL, NULL, ?, ?)` | Inserts new job record with initial status `queued`, progress 0. |
| `getJob(id)` | `SELECT ... FROM audit_jobs WHERE id = ?` | Fetches single job record by ID. Returns mapped job object or `null`. |
| `countRecentByIp(ipHash, since)` | `SELECT COUNT(*) AS total FROM audit_jobs WHERE ip_hash = ? AND created_at >= ?` | Computes number of audits requested by this client IP hash in the last sliding rate window (`since = now - rateWindowMs`). |
| `queuePosition(id)` | `SELECT COUNT(*) AS total FROM audit_jobs WHERE status = 'queued' AND created_at <= ?` | If job status is not `queued`, returns `null`. Otherwise counts all jobs queued at or before this job's `created_at`. |
| `findReusable(targetUrl, now)` | `SELECT ... FROM audit_jobs WHERE target_url=? AND status='completed' AND expires_at>? ORDER BY finished_at DESC LIMIT 1` | Checks if a non-expired, successfully completed audit exists for identical target URL. |
| `claimNext()` | `BEGIN IMMEDIATE;`<br>`SELECT id FROM audit_jobs WHERE status = 'queued' AND cancel_requested = 0 ORDER BY created_at LIMIT 1;`<br>`UPDATE audit_jobs SET status='running', stage='Menyiapkan audit', progress=5, attempts=attempts+1, started_at=COALESCE(started_at, ?), updated_at=? WHERE id=? AND status='queued';`<br>`COMMIT;` | Atomically claims the next runnable job. Updates attempts count and records `started_at` (preserving earliest `started_at` on retries). |
| `updateProgress(id, {stage, progress})` | `UPDATE audit_jobs SET stage=?, progress=MAX(progress, ?), updated_at=? WHERE id=? AND status='running'` | Updates progress. Clamps progress: `Math.max(0, Math.min(99, progress))`. Ensures progress value is monotonically non-decreasing. |
| `requestCancel(id)` | `UPDATE audit_jobs SET cancel_requested=1, status=CASE WHEN status='queued' THEN 'cancelled' ELSE status END, stage=CASE WHEN status='queued' THEN 'Dibatalkan' ELSE 'Membatalkan audit' END, finished_at=CASE WHEN status='queued' THEN ? ELSE finished_at END, updated_at=? WHERE id=? AND status IN ('queued','running')` | Sets cancel flag. If still queued, transitions immediately to `cancelled` and sets `finished_at`. If running, leaves status as running but updates stage to "Membatalkan audit" for the worker to finalize. |
| `complete(id, result)` | `UPDATE audit_jobs SET status='completed', stage='Audit selesai', progress=100, result_json=?, error_code=NULL, error_message=NULL, finished_at=?, updated_at=? WHERE id=? AND status='running'` | Marks job as completed, storing the result JSON and setting progress to 100%. |
| `completeQueued(id, result)` | `UPDATE audit_jobs SET status='completed', stage='Menggunakan hasil tersimpan', progress=100, result_json=?, started_at=?, finished_at=?, updated_at=? WHERE id=? AND status='queued'` | Used for instant cached audit reuse. Bypasses the worker queue directly from API. |
| `fail(id, {code, message})` | `UPDATE audit_jobs SET status='failed', stage='Audit gagal', error_code=?, error_message=?, finished_at=?, updated_at=? WHERE id=? AND status='running'` | Marks job as failed with error code and human message. |
| `cancelRunning(id)` | `UPDATE audit_jobs SET status='cancelled', stage='Dibatalkan', error_code=NULL, error_message=NULL, finished_at=?, updated_at=? WHERE id=? AND status='running'` | Finalizes cancellation of an active running job. |
| `retry(id, {code, message})` | `UPDATE audit_jobs SET status='queued', stage='Menunggu percobaan ulang', progress=0, error_code=?, error_message=?, updated_at=? WHERE id=? AND status='running' AND attempts < 2` | Returns job to queue for a second attempt on transient errors. |
| `expired(now)` | `SELECT id, artifact_dir FROM audit_jobs WHERE expires_at <= ?` | Finds all expired jobs whose artifact folders must be deleted. |
| `deleteExpired(now)` | `DELETE FROM audit_jobs WHERE expires_at <= ?` | Deletes expired job rows from database. |

---

## 3. Worker State Machine

```
              ┌───────────────┐
              │  POST /audits │ (reuseExisting=true && cached)
              └───────┬───────┘
                      │
                      ▼
              ┌───────────────┐
         ┌───►│    queued     ├──────────────────────────┐
         │    └───────┬───────┘                          │
         │            │                                  │
         │   claimNext() (attempts+1)                    │ DELETE /audits/{id}
         │            │                                  │ (queued)
         │            ▼                                  │
         │    ┌───────────────┐                          │
(retry)  │    │    running    ├─────────┐                │
attempts │    └─┬─────┬─────┬─┘         │                │
   < 2   │      │     │     │           │ cancelRunning()│
         │      │     │     │           ▼                ▼
         └──────┘     │     │        ┌─────────────────────┐
 transient error      │     │        │      cancelled      │
                      │     │        └─────────────────────┘
                      │     │
                      │     │ non-transient error OR attempts >= 2 OR timeout
                      │     ▼
                      │  ┌─────────────────────┐
                      │  │       failed        │
                      │  └─────────────────────┘
                      │
                      ▼
           ┌─────────────────────┐
           │      completed      │
           └─────────────────────┘
```

### Detailed Transition Rules (`backend/src/worker.mjs` & `backend/src/store.mjs`)

| From State | To State | Trigger / Event | Action Taken | DB Fields Changed |
|---|---|---|---|---|
| `[None]` | `queued` | `POST /audits` (normal flow) | Inserts row into DB, calls `worker.kick()`. | `status='queued'`, `stage='Menunggu antrean'`, `progress=0`, `attempts=0`, `created_at=now`, `expires_at=now+retentionMs`. |
| `[None]` | `completed` | `POST /audits` (`reuseExisting: true` & cache hit) | Copies artifacts folder from cached job, stores cached result JSON. Worker is **NOT** kicked. | `status='completed'`, `stage='Menggunakan hasil tersimpan'`, `progress=100`, `started_at=now`, `finished_at=now`, `result_json=cached.result`. |
| `queued` | `running` | Worker `drain()` -> `claimNext()` | Claimed atomically in immediate transaction. Starts AbortController with timeout (`AUDIT_TIMEOUT_MS`). | `status='running'`, `stage='Menyiapkan audit'`, `progress=5`, `attempts=attempts+1`, `started_at=COALESCE(started_at, now)`, `updated_at=now`. |
| `queued` | `cancelled` | `DELETE /audits/{id}` | User cancels before worker claims the job. | `status='cancelled'`, `stage='Dibatalkan'`, `cancel_requested=1`, `finished_at=now`. |
| `running` | `running` | Progress event from engine | Auditor progress updates (clamped 0..99). | `stage=<event.stage>`, `progress=MAX(progress, <event.progress>)`, `updated_at=now`. |
| `running` | `completed` | Auditor completes successfully | Audit finished without abort/cancel. | `status='completed'`, `stage='Audit selesai'`, `progress=100`, `result_json=<result>`, `finished_at=now`. |
| `running` | `queued` (retry) | Transient error and `attempts < 2` | Transient error occurred (`dns-lookup-failed`, `robots-fetch-failed`, `browser-failed`, `audit-failed`). Artifact dir is **kept** for checkpointing. Worker calls `kick()`. | `status='queued'`, `stage='Menunggu percobaan ulang'`, `progress=0`, `error_code=code`, `error_message=msg`. |
| `running` | `failed` | Non-transient error OR transient error with `attempts >= 2` | Fatal error. Artifact dir is deleted (`fs.rmSync(artifactDir)`). | `status='failed'`, `stage='Audit gagal'`, `error_code=code`, `error_message=msg`, `finished_at=now`. |
| `running` | `failed` (timeout) | Execution time exceeds `AUDIT_TIMEOUT_MS` (180s) | Controller aborts with reason `"timeout"`. Artifact dir is deleted. | `status='failed'`, `stage='Audit gagal'`, `error_code='audit-timeout'`, `error_message='Audit melewati batas 180 detik.'`, `finished_at=now`. |
| `running` | `cancelled` | `DELETE /audits/{id}` while running | `requestCancel()` sets `cancel_requested=1`, `stage='Membatalkan audit'`. Signal aborts with `"cancelled-by-user"`. Worker runs `cancelRunning()`, artifact dir is deleted. | `status='cancelled'`, `stage='Dibatalkan'`, `cancel_requested=1`, `finished_at=now`. |
| `running` | `queued` (resume) | Server restarted with `snapshot.json` present | Crash recovery on startup finds completed checkpoint. | `status='queued'`, `stage='Melanjutkan finalisasi dari checkpoint tersimpan'`, `progress=90`, `error_code=NULL`, `error_message=NULL`. |
| `running` | `queued` (restart) | Server restarted without checkpoint, `attempts < 2` | Crash recovery on startup. | `status='queued'`, `stage='Dipulihkan setelah server restart'`, `progress=0`, `error_code=NULL`, `error_message=NULL`. |
| `running` | `failed` (restart) | Server restarted without checkpoint, `attempts >= 2` | Job already crashed twice. | `status='failed'`, `stage='Gagal setelah server restart'`, `error_code='server-restarted'`, `error_message='Audit terputus dua kali karena server restart.'`, `finished_at=now`. |

---

## 4. Environment Variables Specification

Traced to `backend/src/config.mjs` and Dockerfile:

| Variable Name | Required? | Default Value | Validation / Allowed Range | Exact Error Message on Validation Failure | Description |
|---|---|---|---|---|---|
| `NODE_ENV` | No | `"development"` | Any string (`"production"` triggers strict checks) | N/A | When `"production"`: requires `BACKEND_SECRET` >= 32 chars, sets default rate limit to 5. |
| `HOST` | No | `"127.0.0.1"` (`"0.0.0.0"` in Docker) | Valid IP string | N/A | HTTP listen interface. |
| `PORT` | No | `8787` | Integer `1` to `65535` | `PORT harus berupa bilangan bulat 1–65535.` | HTTP listen port. |
| `BACKEND_SECRET` | Conditional | `"aksaranetra-development-secret-change-me"` | String >= 32 chars in production | `BACKEND_SECRET minimal 32 karakter di production.` | Secret key used for HMAC-SHA256 token hashing and IP masking. |
| `FRONTEND_ORIGINS` | No | `"http://localhost:3000"` | Comma-separated list of origin strings | N/A | Allowed browser origins for CORS. Whitespace trimmed, empty entries filtered. |
| `BACKEND_DATA_DIR` | No | `"backend/data"` (`"/data"` in Docker) | Valid directory path | N/A | Base directory for SQLite database (`jobs.sqlite`) and artifacts (`artifacts/`). |
| `TRUST_PROXY` | No | `""` (falsy) | `"1"` enables proxy trust | N/A | When `"1"`, extracts client IP from the first address in `X-Forwarded-For`. |
| `AUDIT_TIMEOUT_MS` | No | `180000` (180s) | Integer `30000` to `600000` (30s – 10m) | `AUDIT_TIMEOUT_MS harus berupa bilangan bulat 30000–600000.` | Maximum audit execution duration before worker triggers timeout cancellation. |
| `RESULT_RETENTION_MS`| No | `604800000` (7 days) | Integer `3600000` (1h) to `2592000000` (30 days) | `RESULT_RETENTION_MS harus berupa bilangan bulat 3600000–2592000000.` | Duration before completed jobs and artifacts expire. |
| `AUDIT_RATE_LIMIT` | No | `5` (production) / `10` (dev) | Integer `1` to `100` | `AUDIT_RATE_LIMIT harus berupa bilangan bulat 1–100.` | Maximum audits allowed per IP within a 1-hour window (`config.rateWindowMs = 3600000`). |
| `MAX_BODY_BYTES` | No | `8192` (8 KB) | Integer `1024` to `65536` (1 KB – 64 KB) | `MAX_BODY_BYTES harus berupa bilangan bulat 1024–65536.` | Maximum incoming request body size for `POST /audits`. |
| `CLEANUP_INTERVAL_MS`| No | `3600000` (1 hour) | Integer `60000` (1m) to `86400000` (1 day) | `CLEANUP_INTERVAL_MS harus berupa bilangan bulat 60000–86400000.` | Interval between expired job and artifact purges. |
| `CHROMIUM_LOW_RESOURCE_MODE` | No | `""` (auto in prod) | `"1"` enables low-RAM flags | N/A | Read by engine runner. Disables Chromium GPU, shm usage, sets renderer memory limits, caps screenshot height. |
| `PLAYWRIGHT_BROWSERS_PATH` | No | System default (`"/app/.playwright"` in Docker) | Absolute path string | N/A | Directory where Playwright looks for Chromium executable. |

---

## 5. Engine Functions & Node Bridge Interface

The Node bridge CLI (`php-backend/bridge/audit-cli.mjs`) will wrap the three engine functions needed by the PHP backend.

### 5.1 `checkUrl(input: string)` (`engine/src/fetcher/url-policy.mjs:63-142`)
- **Signature**: `checkUrl(input: string): { ok: boolean, code?: string, message?: string, url?: string, hostname?: string, protocol?: string }`
- **Success return**:
  ```json
  {
    "ok": true,
    "code": null,
    "message": null,
    "url": "https://example.com/",
    "hostname": "example.com",
    "protocol": "https:"
  }
  ```
- **Failure return**:
  ```json
  {
    "ok": false,
    "code": "<error-code>",
    "message": "<indonesian-error-message>"
  }
  ```
- **Error Codes & Messages**:
  - `empty-url`: `"URL kosong."`
  - `url-too-long`: `"URL lebih panjang dari 2048 karakter."`
  - `unparseable-url`: `"URL tidak dapat dibaca."`
  - `protocol-not-allowed`: `"Hanya http dan https yang didukung, bukan <protocol>."`
  - `credentials-in-url`: `"URL memuat nama pengguna atau kata sandi."`
  - `port-not-allowed`: `"Hanya port standar yang diizinkan, bukan port <port>."`
  - `empty-host`: `"URL tidak memuat nama domain."`
  - `ip-literal-not-allowed`: `"Alamat IP langsung tidak diterima. Gunakan nama domain."`
  - `local-host-name`: `"Nama <hostname> mengarah ke mesin lokal."` / `"Akhiran <suffix> dipakai untuk jaringan lokal."`
  - `hostname-not-allowed`: `"Nama domain tidak berbentuk wajar."`
- **Bridge Subcommand**:
  - `node php-backend/bridge/audit-cli.mjs check-url <url>`
  - Prints JSON result directly to stdout and exits with code 0.

---

### 5.2 `auditUrl(options)` (`engine/src/api/index.mjs:293-485`)
- **Signature**:
  ```js
  auditUrl({
    url: string,
    origin?: "manual" | "catalog", // default "manual"
    outputDir?: string | null,     // job.artifactDir
    id?: string | null,            // job.id
    signal?: AbortSignal | null,
    onProgress?: async ({ progress: number, stage: string }) => void
  }): Promise<{ ok: boolean, snapshot?: object, files?: object, code?: string, message?: string, stage?: number, stageName?: string }>
  ```
- **Progress Callback Stream**:
  Progress values emitted in sequence:
  1. `progress: 2`, `stage: "Memvalidasi alamat halaman"`
  2. `progress: 10`, `stage: "Alamat DNS terverifikasi aman"`
  3. `progress: 16`, `stage: "Aturan robots.txt mengizinkan audit"`
  4. `progress: 22`, `stage: "Menyiapkan browser audit"`
  5. `progress: 35`, `stage: "Membuka halaman target"`
  6. `progress: 43`, `stage: "Menunggu konten halaman selesai dimuat"`
  7. `progress: 53`, `stage: "Mengaudit aksesibilitas awal"`
  8. `progress: 63`, `stage: "Menerapkan dan memverifikasi patch"`
  9. `progress: 75`, `stage: "Mengumpulkan bukti audit"`
  10. `progress: 80`, `stage: "Membuat reader view dan halaman hasil"`
  11. `progress: 86`, `stage: "Menyusun laporan audit"`
  12. `progress: 91`, `stage: "Menyimpan artefak audit"`
  13. `progress: 94`, `stage: "Artefak utama selesai dibuat"`
  *(Auditor service in backend adds: `95: "Memulihkan hasil audit dari checkpoint tersimpan"`, `96: "Menyiapkan laporan hasil"`, `99: "Memfinalisasi hasil"`)*
- **Engine Failure Codes**:
  - `dns-empty`, `dns-lookup-failed`, `blocked-address`
  - `robots-unavailable`, `robots-disallowed`, `robots-fetch-failed`
  - `browser-failed`, `guard-failed`, `status-not-ok`, `audit-failed`
  - `serialize-failed`, `snapshot-failed`, `snapshot-incomplete`, `write-failed`
- **Bridge Subcommand**:
  - `node php-backend/bridge/audit-cli.mjs audit`
  - Reads `{ "id": "...", "url": "...", "artifactDir": "..." }` from `stdin`.
  - Emits NDJSON events to `stdout`:
    - `{"type":"progress","progress":N,"stage":"..."}`
    - `{"type":"result","ok":true,"snapshot":{...},"files":{...}}`
    - `{"type":"error","ok":false,"code":"...","stage":N,"message":"..."}`
  - Clean abort: On `SIGTERM`, aborts internal `AbortController` and exits cleanly.

---

### 5.3 `renderPdf(options)` (`engine/src/runner/index.mjs:295-308`)
- **Signature**:
  ```js
  renderPdf({
    html: string,
    outputPath: string,
    signal?: AbortSignal | null
  }): Promise<string>
  ```
- **Behavior**: Opens headless Chromium, sets media to `print`, generates A4 PDF with `printBackground: true, preferCSSPageSize: true`, writes file to `outputPath`.
- **Bridge Subcommand**:
  - `node php-backend/bridge/audit-cli.mjs render-pdf <htmlPath> <outPath>`
  - Reads HTML from `<htmlPath>`, renders PDF to `<outPath>`, prints `{"ok":true,"outputPath":"..."}` to stdout or `{"ok":false,"error":"..."}`.

---

## 6. What `src/lib/audit-api.ts` Expects (Frontend Contract)

The Next.js frontend interacts strictly via `src/lib/audit-api.ts`. Any change in field names, nesting, or data types will break the frontend.

### 6.1 Frontend Polling & Lifecycle (`src/app/result/page.tsx:740-815`)
1. **Cache check**: Calls `checkAuditCache(url)`. If `cache.available === true`, presents choice: "Gunakan hasil tersimpan" vs "Periksa ulang".
2. **Audit initiation**: Calls `createAudit(url, reuseExisting)`.
   - Reads: `jobId`, `accessToken`, `status`, `expiresAt`.
   - Stores `accessToken` in `localStorage` under `aksaranetra:job:${jobId}`.
   - Redirects to `/result?job=${jobId}` (does not leak token in URL).
3. **Polling loop**:
   - Polls `getAudit(jobId, token)` every **1500 ms**.
   - Inspects `job.status`:
     - `queued` or `running`: updates UI progress bar (`job.progress`), live stage text (`job.stage`), and queue position (`job.queuePosition`).
     - `completed`: updates progress to 100, waits 800ms, calls `getAuditResult(jobId, token)`.
     - `failed`: calls error handler with `job.error` (`code` and `message`).
     - `cancelled`: stops polling, transitions UI to cancelled state.
   - Fault tolerance: tolerates up to 15 consecutive network/HTTP failures (~45 seconds) before failing, to accommodate server restarts.
4. **Cancellation**: User clicks "Batalkan pemeriksaan" -> calls `cancelAudit(jobId, token)` (`DELETE /audits/{id}`).
5. **Result presentation**:
   - Reads `result.snapshot` (metrics, before/after totals, rules, warnings).
   - Reads `links`:
     - `links.reader` -> links to Reader view
     - `links.patched` -> links to Patched view
     - `links.reportPdf` -> links to PDF report download
     - `links.screenshot` -> links to Screenshot image

### 6.2 Frontend Error Mapping (`src/lib/audit-api.ts:88-197, 233-242`)
The frontend maps backend error codes to user-friendly Indonesian messages:
- `rate-limit` -> `RATE_LIMITED` ("Batas audit tercapai")
- `expired` -> `RESULT_EXPIRED` ("Hasil audit telah kedaluwarsa")
- `not-found` -> `AUDIT_NOT_FOUND` ("Audit tidak ditemukan")
- `internal-error` -> `INTERNAL_ERROR` ("Audit belum berhasil")
- `browser-failed` -> `AUDIT_RUNTIME_UNAVAILABLE` ("Layanan audit belum siap")
- `audit-timeout` -> `TARGET_TIMEOUT` ("Audit melewati batas waktu")
- `robots-disallowed`, `robots-unavailable` -> `TARGET_BLOCKED_BY_ROBOTS` ("Audit tidak diizinkan situs")
- `robots-fetch-failed`, `dns-lookup-failed` -> `TARGET_UNREACHABLE` ("Halaman tidak dapat dijangkau")
- `blocked-address` -> `TARGET_NOT_PUBLIC` ("Halaman tidak dapat diaudit")
- HTTP status fallbacks: 404 -> `AUDIT_NOT_FOUND`, 410 -> `RESULT_EXPIRED`, 429 -> `RATE_LIMITED`.

---

## 7. Quirks, Inconsistencies, & Ambiguities to Preserve

1. **`reportPdf` artifact vs URL route mismatch**:
   - In `backend/src/auditor.mjs:87-88`:
     ```js
     reportPdf: fs.existsSync(pdfPath) ? "report.pdf" : (fs.existsSync(reportHtmlPath) ? "report.html" : null)
     ```
   - If PDF rendering fails (or is skipped due to low memory), `job.result.artifacts.reportPdf` is set to `"report.html"`.
   - But in `backend/src/app.mjs:231`:
     ```js
     reportPdf: `${base}/report.pdf?token=${encodeURIComponent(shareToken)}`
     ```
   - The route is hardcoded to `/audits/{id}/artifacts/report.pdf`, and `ARTIFACTS["report.pdf"]` strictly looks for `report.pdf`. There is no route for `/artifacts/report.html`. If the PDF failed to generate, hitting `reportPdf` returns 404!
   - *Decision*: Preserve this exact behavior in PHP and document in `php-backend/NOTES.md`.
2. **Artifact Authorization Scheme Difference**:
   - `GET /audits/{id}`, `DELETE /audits/{id}`, and `GET /audits/{id}/result` authenticate via `Authorization: Bearer <accessToken>` OR `?accessToken=<accessToken>`.
   - `GET /audits/{id}/artifacts/{artifact}` **ONLY** accepts `?token=<shareToken>`. It does NOT accept `Authorization` headers, and it requires the derived `shareToken`, NOT the `accessToken`.
3. **Response Status for Reused Audits**:
   - `POST /audits` returns HTTP **202 Accepted** when a new job is queued.
   - However, when `reuseExisting: true` results in a cache hit, it copies artifacts and immediately returns HTTP **200 OK** (`backend/src/app.mjs:196`).
4. **`started_at` Immutability on Retry**:
   - In `store.claimNext()`, `started_at = COALESCE(started_at, now)`. When a job fails with a transient error and retries, its original `started_at` timestamp is preserved rather than updated to the retry time.
5. **Monotonic Progress Clamping**:
   - `updateProgress()` uses `MAX(progress, ?)` and `Math.min(99, progress)`. Progress cannot decrease during a running job, and cannot reach 100% until `complete()` is called.
6. **Checkpoint Recovery Threshold**:
   - In `recoverInterrupted()`, if `snapshot.json` exists, the job is restored to `queued` at `progress = 90`. If not, and `attempts < 2`, it resets to `progress = 0`.
7. **Rate Limit Window Scope**:
   - Rate limiting counts *all* jobs created by an IP hash within the last hour, regardless of whether the audits completed, failed, or were cancelled.
8. **Checkpoint Resume in Auditor**:
   - In `auditor.mjs:19`, if `snapshot.json` and `engine-result.json` exist in `artifactDir`, it skips running `auditUrl` entirely and directly proceeds to report rendering and PDF generation.

---

## 8. Questions & Design Confirmations for Phase 1+

1. **Worker Polling vs Cancellation**:
   - In the Node backend, the worker and API run in the same OS process (`backend/src/server.mjs`), so cancellation aborts the in-memory `AbortController` immediately.
   - In PHP, the API runs under PHP-FPM / Laravel Octane / `artisan serve` while the worker runs via `php artisan audit:work`.
   - *Plan*: As specified in the target architecture, `DELETE /audits/{id}` sets `cancel_requested = 1` in SQLite. The worker process polling the child Node bridge process checks `cancel_requested` every ~1 second and sends `SIGTERM` to the bridge process if cancel is requested.
2. **Artisan Command Names**:
   - Worker command: `php artisan audit:work`
   - Cleanup command: `php artisan audit:cleanup`
3. **Bridge Communication**:
   - The bridge at `php-backend/bridge/audit-cli.mjs` will communicate via stdin/stdout using Symfony `Process`, parsing NDJSON progress lines and updating SQLite `audit_jobs` accordingly.
