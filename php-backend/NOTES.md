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
