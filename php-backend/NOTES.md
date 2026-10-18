# AksaraNetra Backend Quirks & Preserved Behaviors

This file records specific quirks, edge cases, and design peculiarities identified in the original Node.js backend (`backend/`) that must be preserved verbatim in the PHP/Laravel implementation to maintain drop-in compatibility.

---

## 1. `reportPdf` Link Points to `report.pdf` Even When Only `report.html` Exists
- **Location**: `backend/src/auditor.mjs:87` vs `backend/src/app.mjs:231`
- **Quirk**:
  In `auditor.mjs`, if PDF rendering fails or is skipped (e.g. in low memory environments), the result artifact mapping records:
  ```js
  reportPdf: fs.existsSync(pdfPath) ? "report.pdf" : (fs.existsSync(reportHtmlPath) ? "report.html" : null)
  ```
  However, `app.mjs` generates the API response links as:
  ```js
  reportPdf: `${base}/report.pdf?token=${encodeURIComponent(shareToken)}`
  ```
  And `ARTIFACTS["report.pdf"]` strictly resolves to `report.pdf`. No route exists for `report.html`.
- **Effect**: If PDF generation fails, clicking the report download link returns HTTP 404.
- **Preservation rule**: Do NOT "fix" this by changing the artifact link or route. Preserve this exact behavior in PHP and return 404 if `report.pdf` is missing.

---

## 2. Artifact Route Token Authentication
- **Location**: `backend/src/app.mjs:98-103, 206-212` vs `backend/src/app.mjs:214-250`
- **Quirk**:
  All standard audit endpoints (`GET /audits/{id}`, `DELETE /audits/{id}`, `GET /audits/{id}/result`) accept either an `Authorization: Bearer <accessToken>` header or `?accessToken=<accessToken>` query param.
  However, the artifact route (`GET /audits/{id}/artifacts/{artifact}`) **only** reads `?token=<shareToken>`. It does NOT inspect the `Authorization` header, and it checks against `share_token_hash`, not `token_hash`.
- **Effect**: Passing `Authorization: Bearer <accessToken>` to artifact routes results in HTTP 404 `Artefak tidak ditemukan.`.
- **Preservation rule**: The Laravel artifact route must strictly validate the query parameter `token` against `share_token_hash`.

---

## 3. Instant Cache Hit Returns HTTP 200 instead of HTTP 202
- **Location**: `backend/src/app.mjs:196`
- **Quirk**:
  Normally, `POST /audits` returns HTTP 202 Accepted with a queued job. However, when `reuseExisting: true` is supplied and an existing completed job is found for the same URL, the backend copies the artifact folder, marks the job completed immediately via `completeQueued()`, and responds with HTTP 200 OK.
- **Effect**: The client can detect instant completion on initial post.
- **Preservation rule**: Preserve HTTP 200 for instant cache hits and HTTP 202 for newly queued jobs.

---

## 4. `started_at` Immutability on Retry
- **Location**: `backend/src/store.mjs:143`
- **Quirk**:
  In `claimNext()`, the update query uses `started_at=COALESCE(started_at, ?)`. When a job fails with a transient error and is retried, `started_at` is preserved from attempt 1 rather than reset to the attempt 2 start time.
- **Preservation rule**: Preserve `COALESCE(started_at, ?)` behavior in Laravel's job claiming query.

---

## 5. Monotonic Progress Clamping
- **Location**: `backend/src/store.mjs:155`
- **Quirk**:
  `updateProgress()` uses `MAX(progress, ?)` and clamps to 99: `Math.max(0, Math.min(99, progress))`. A job cannot decrease progress during a run, and cannot reach 100 until `complete()` is called.
- **Preservation rule**: Reproduce the same SQL `MAX(progress, ?)` and PHP min/max clamping.

---

## 6. SQLite Type Affinity and PDO Parameter Binding
- **Location**: `php-backend/app/Services/JobStore.php` vs `backend/src/store.mjs`
- **Quirk**:
  Node's `DatabaseSync` passes JavaScript numbers directly to SQLite as native integers. In contrast, PHP PDO's `$stmt->execute([$param])` binds parameters by default as `PDO::PARAM_STR`. In SQLite type affinity comparisons, TEXT is evaluated as greater than INTEGER (e.g. `MAX(50, '30')` in SQLite returns `'30'`).
- **Preservation rule**: In `JobStore.php`, queries using numeric comparisons (`MAX(progress, CAST(? AS INTEGER))`, `created_at <= CAST(? AS INTEGER)`, `expires_at <= CAST(? AS INTEGER)`) explicitly cast placeholder values to `INTEGER` to ensure type-safe numeric evaluation matching Node's native integer bindings.

---

## 7. Windows Node Subprocess Environment Inheritance
- **Location**: `php-backend/app/Services/BridgeClient.php`
- **Quirk**:
  When spawning Node.js subprocesses from PHP via `Symfony\Component\Process\Process` on Windows, Node 22 cryptographic initialization (`ncrypto::CSPRNG`) requires access to the Windows CryptoAPI, which depends on `SYSTEMROOT` (and `PATH`) existing in the environment. Stripping these environment variables causes Node to fail immediately with an assertion error.
- **Preservation rule**: `BridgeClient.php` explicitly preserves `SYSTEMROOT`, `SystemDrive`, and `PATH` in the process environment when spawning `audit-cli.mjs`.

---

## 8. Symfony BinaryFileResponse Default Cache-Control
- **Location**: `php-backend/app/Http/Controllers/AuditArtifactController.php` vs `backend/src/app.mjs:240`
- **Quirk**:
  In Node, `sendArtifact()` sends `cache-control: max-age=300, private`. In Laravel / Symfony, `BinaryFileResponse` defaults to `public` unless `$response->setPrivate()` is explicitly called.
- **Preservation rule**: `AuditArtifactController` invokes `$response->setPrivate()->setMaxAge(300)` to ensure byte-for-byte identical `Cache-Control: max-age=300, private` headers.

---

## 9. HTML Report JSON Regression Block Indentation
- **Location**: `backend/src/report.mjs:21` vs `php-backend/app/Services/ReportRenderer.php`
- **Quirk**:
  Node's `renderAuditReport()` formats the WCAG regression block using `JSON.stringify(regression, null, 2)`, which produces JSON indented by exactly 2 spaces per level. PHP's built-in `json_encode(..., JSON_PRETTY_PRINT)` indents by 4 spaces per level by default without an indentation option.
- **Preservation rule**: `ReportRenderer::stringifyRegression()` converts each 4-space indentation level to 2 spaces via regex callback and utilizes `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, guaranteeing byte-for-byte identity with Node output.

---

## 10. Symfony ResponseHeaderBag Automatic `private` Directive on `no-store`
- **Location**: `php-backend/app/Http/Middleware/AksaraResponseHeadersMiddleware.php` vs `backend/src/app.mjs:27`
- **Quirk**:
  Node sets raw `cache-control: no-store`. In Symfony / Laravel, `ResponseHeaderBag::computeCacheControlValue()` automatically appends `, private` to any cache-control header that does not explicitly include `public` or `private` (resulting in `Cache-Control: no-store, private`).
- **Effect**: Under HTTP RFC 7234 / RFC 9111, `no-store` prohibits storing by both shared and private caches; adding `private` is semantically redundant but does not affect caching behavior.
- **Preservation rule**: The parity check test normalizes directives by removing `private` when `no-store` is present, verifying identical semantic behavior.

---

## 11. Artifact Route Traversal Resolution
- **Location**: `php-backend/routes/api.php` vs `backend/src/app.mjs:206`
- **Quirk**:
  Node matches `/^\/audits\/([^/]+)\/artifacts\/([^/]+)$/`. When receiving path traversal requests (e.g. `/audits/{id}/artifacts/..%2fjobs.sqlite`), Node's regex matches the literal string `..%2fjobs.sqlite` as the artifact name, passes it to `serveArtifact()`, and fails with HTTP 404 `Artefak tidak ditemukan.`.
- **Effect**: If Laravel uses default parameter matching `[^/]+`, decoded `%2f` characters would prevent the route from matching and trigger the general fallback `Endpoint tidak ditemukan.`.
- **Preservation rule**: `routes/api.php` defines `->where('artifact', '.*')`, ensuring traversed and encoded paths reach `AuditArtifactController::show()` and return the identical Indonesian error message `Artefak tidak ditemukan.`.

---

## 12. Startup Cleanup of Expired Jobs
- **Location**: `backend/src/server.mjs:26-30` vs `php-backend/app/Console/Commands/AuditWorkCommand.php`
- **Quirk**:
  Node calls `cleanup()` synchronously in `createService()` prior to listening, immediately deleting any job whose `expires_at <= Date.now()`. In PHP, cleanup is scheduled periodically in the worker (`php artisan audit:work`) and via `php artisan audit:cleanup`.
- **Effect**: Pre-seeding already-expired jobs prior to server startup causes Node to purge them before requests arrive.
- **Preservation rule**: Parity tests seed jobs that expire dynamically during test execution to verify the HTTP 410 `Artefak sudah kedaluwarsa.` response on both backends.

---

## 13. Parity Check Verification Results
- Automated parity check script `php-backend/scripts/parity-check.mjs` executed:
  - Total test cases: 42
  - Passed: 42 (100%)
  - Failed: 0
  - Status codes, CORS preflight/headers, CSP, `x-content-type-options`, `location`, `retry-after`, and normalized JSON payloads match verbatim.
- End-to-end frontend workflow script `php-backend/scripts/test-frontend-flow.mjs` executed:
  - Cache lookup -> Job creation -> Queue status polling -> Worker execution with live Chromium -> Completion -> Result retrieval -> Reader/Patched/Screenshot artifact download -> Cache hit verification: 100% passed.
- Frontend contract tests: `npm run test:frontend` passed 5/5.
- PHP unit and feature tests: 49 tests, 320 assertions passed 100%.
- Node reference tests: 10 tests passed 100%.




