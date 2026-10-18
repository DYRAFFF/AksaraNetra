/**
 * AksaraNetra PHP Backend Port - Phase 7 Parity Check Script
 *
 * Starts (or targets) Node and PHP backends with identical fixture setups
 * and executes a comprehensive sequence of HTTP requests against both,
 * diffing status codes, headers (CORS, CSP, cache-control, x-content-type-options,
 * location, retry-after), and JSON shapes/values.
 */

import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import crypto from "node:crypto";
import fs from "node:fs";
import net from "node:net";
import os from "node:os";
import path from "node:path";
import { DatabaseSync } from "node:sqlite";
import { fileURLToPath } from "node:url";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const REPO_ROOT = path.resolve(__dirname, "../..");
const PHP_BACKEND_DIR = path.resolve(REPO_ROOT, "php-backend");
const NODE_BACKEND_DIR = path.resolve(REPO_ROOT, "backend");

// Test configuration
const TEST_SECRET = "parity-secret-that-is-at-least-32-chars-long-123456";
const TEST_ORIGIN = "http://localhost:3000";
const DISALLOWED_ORIGIN = "https://untrusted-attacker.test";

// Fixture identifiers
const COMPLETED_JOB_ID = "aud_paritycompleted0000000001";
const COMPLETED_ACCESS_TOKEN = "parity_access_token_completed_00000000000";
const EXPIRED_JOB_ID = "aud_parityexpired000000000001";
const EXPIRED_ACCESS_TOKEN = "parity_access_token_expired_0000000000000";

function digest(token, secret = TEST_SECRET) {
  return crypto.createHmac("sha256", secret).update(token).digest("hex");
}

function deriveShareToken(jobId, accessToken, secret = TEST_SECRET) {
  return crypto.createHmac("sha256", secret).update(`share:${jobId}:${accessToken}`).digest("base64url");
}

const COMPLETED_SHARE_TOKEN = deriveShareToken(COMPLETED_JOB_ID, COMPLETED_ACCESS_TOKEN);
const EXPIRED_SHARE_TOKEN = deriveShareToken(EXPIRED_JOB_ID, EXPIRED_ACCESS_TOKEN);

// Standard 1x1 transparent PNG
const SAMPLE_PNG = Buffer.from([
  137, 80, 78, 71, 13, 10, 26, 10, 0, 0, 0, 13, 73, 72, 68, 82,
  0, 0, 0, 1, 0, 0, 0, 1, 8, 6, 0, 0, 0, 31, 21, 196, 137,
  0, 0, 0, 10, 73, 68, 65, 84, 120, 156, 99, 0, 1, 0, 0, 5,
  0, 1, 13, 10, 45, 180, 0, 0, 0, 0, 73, 69, 78, 68, 174, 66, 96, 130
]);
const SAMPLE_HTML_READER = "<!doctype html><html lang=\"id\"><head><meta charset=\"utf-8\"><title>Reader Mode</title></head><body><h1>AksaraNetra Reader</h1><p>Halaman ramah pembaca layar.</p></body></html>";
const SAMPLE_HTML_PATCHED = "<!doctype html><html lang=\"id\"><head><meta charset=\"utf-8\"><title>Patched Mode</title></head><body><h1>AksaraNetra Patched</h1><p>Halaman dengan perbaikan aksesibilitas.</p></body></html>";
const SAMPLE_PDF = Buffer.from("%PDF-1.4\n%AksaraNetra Parity Check PDF\n%%EOF\n");

function findOpenPort() {
  return new Promise((resolve, reject) => {
    const srv = net.createServer();
    srv.listen(0, "127.0.0.1", () => {
      const port = srv.address().port;
      srv.close(() => resolve(port));
    });
    srv.on("error", reject);
  });
}

function seedDatabase(dbPath, tableName, artifactsDir) {
  fs.mkdirSync(path.dirname(dbPath), { recursive: true });
  fs.mkdirSync(artifactsDir, { recursive: true });

  const db = new DatabaseSync(dbPath);
  db.exec("PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;");
  db.exec(`CREATE TABLE IF NOT EXISTS ${tableName} (
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
  CREATE INDEX IF NOT EXISTS ${tableName}_queue_idx ON ${tableName}(status, created_at);
  CREATE INDEX IF NOT EXISTS ${tableName}_ip_idx ON ${tableName}(ip_hash, created_at);
  CREATE INDEX IF NOT EXISTS ${tableName}_expiry_idx ON ${tableName}(expires_at);
  `);

  const now = Date.now();
  const completedArtifactDir = path.join(artifactsDir, COMPLETED_JOB_ID);
  fs.mkdirSync(completedArtifactDir, { recursive: true });
  fs.writeFileSync(path.join(completedArtifactDir, "reader.html"), SAMPLE_HTML_READER, "utf8");
  fs.writeFileSync(path.join(completedArtifactDir, "patched.html"), SAMPLE_HTML_PATCHED, "utf8");
  fs.writeFileSync(path.join(completedArtifactDir, "report.pdf"), SAMPLE_PDF);
  fs.writeFileSync(path.join(completedArtifactDir, "after.png"), SAMPLE_PNG);

  const expiredArtifactDir = path.join(artifactsDir, EXPIRED_JOB_ID);
  fs.mkdirSync(expiredArtifactDir, { recursive: true });
  fs.writeFileSync(path.join(expiredArtifactDir, "reader.html"), SAMPLE_HTML_READER, "utf8");

  const snapshot = {
    engineVersion: "0.1.0",
    capturedAt: new Date(now - 10000).toISOString(),
    disclaimer: "Hasil audit otomatis.",
    source: {
      requestedUrl: "https://cached.example.com/",
      finalUrl: "https://cached.example.com/",
      title: "Example Domain",
      status: 200,
    },
    summary: {
      beforeTotal: 10,
      afterTotal: 2,
      reduction: 8,
      reductionPercent: 80,
      strictImprovement: true,
      noRegression: true,
    },
    rules: { "color-contrast": { before: 8, after: 0, reduction: 8 } },
    counts: { fixed: 8, review: 2, skipped: 0, rolledBack: 0, verifiedOverrides: 0 },
    warnings: {
      rolledBack: false,
      wcagRegressionClean: true,
      worsenedRules: [],
      duplicateLabels: [],
      staleOverrides: [],
      rolledBackRecords: [],
    },
  };

  const artifactsMapping = {
    reader: "reader.html",
    patched: "patched.html",
    reportPdf: "report.pdf",
    screenshot: "after.png",
  };

  const resultJson = JSON.stringify({ snapshot, artifacts: artifactsMapping });

  const insertStmt = db.prepare(`INSERT OR REPLACE INTO ${tableName} (
    id, token_hash, share_token_hash, ip_hash, target_url, status,
    stage, progress, attempts, cancel_requested, result_json,
    error_code, error_message, created_at, updated_at, started_at,
    finished_at, expires_at, artifact_dir
  ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`);

  // Insert completed job
  insertStmt.run(
    COMPLETED_JOB_ID,
    digest(COMPLETED_ACCESS_TOKEN),
    digest(COMPLETED_SHARE_TOKEN),
    digest("127.0.0.1"),
    "https://cached.example.com/",
    "completed",
    "Audit selesai",
    100,
    1,
    0,
    resultJson,
    null,
    null,
    now - 60000,
    now - 10000,
    now - 50000,
    now - 10000,
    now + 7 * 86400 * 1000,
    completedArtifactDir
  );

  // Insert expired job (expires in 1200ms so startup cleanup does not delete it)
  insertStmt.run(
    EXPIRED_JOB_ID,
    digest(EXPIRED_ACCESS_TOKEN),
    digest(EXPIRED_SHARE_TOKEN),
    digest("127.0.0.1"),
    "https://expired.example.com/",
    "completed",
    "Audit selesai",
    100,
    1,
    0,
    resultJson,
    null,
    null,
    now - 800000,
    now - 700000,
    now - 750000,
    now - 700000,
    now + 1200, // expires shortly after startup
    expiredArtifactDir
  );

  db.close();
}

async function startNodeServer({ port, dataDir }) {
  const { createService } = await import(pathToFileURL(path.join(NODE_BACKEND_DIR, "src/server.mjs")).href);
  const databasePath = path.join(dataDir, "jobs.sqlite");
  const artifactsDir = path.join(dataDir, "artifacts");

  seedDatabase(databasePath, "jobs", artifactsDir);

  const { JobStore } = await import(pathToFileURL(path.join(NODE_BACKEND_DIR, "src/store.mjs")).href);
  const { loadConfig } = await import(pathToFileURL(path.join(NODE_BACKEND_DIR, "src/config.mjs")).href);

  const config = loadConfig({
    port,
    host: "127.0.0.1",
    dataDir,
    databasePath,
    artifactsDir,
    secret: TEST_SECRET,
    frontendOrigins: [TEST_ORIGIN],
    rateLimit: 5,
    rateWindowMs: 60_000,
    retentionMs: 7 * 86400 * 1000,
    maxBodyBytes: 8192,
  });

  const store = new JobStore({ databasePath });
  const worker = {
    kicks: 0,
    start() {},
    async stop() {},
    kick() { this.kicks += 1; },
    cancel(id) { return store.requestCancel(id); },
  };

  const service = createService({ config, store, worker });
  await service.start();

  return {
    base: `http://127.0.0.1:${port}`,
    stop: async () => {
      await service.stop();
      try { fs.rmSync(dataDir, { recursive: true, force: true }); } catch {}
    },
  };
}

async function startPhpServer({ port, dataDir, phpBin }) {
  const databasePath = path.join(dataDir, "jobs.sqlite");
  const artifactsDir = path.join(dataDir, "artifacts");

  seedDatabase(databasePath, "audit_jobs", artifactsDir);

  const env = {
    ...process.env,
    APP_ENV: "testing",
    APP_DEBUG: "false",
    BACKEND_DATA_DIR: dataDir,
    BACKEND_SECRET: TEST_SECRET,
    FRONTEND_ORIGINS: TEST_ORIGIN,
    AUDIT_RATE_LIMIT: "5",
    MAX_BODY_BYTES: "8192",
    PORT: String(port),
    HOST: "127.0.0.1",
  };

  const phpProcess = spawn(
    phpBin,
    ["-S", `127.0.0.1:${port}`, "../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"],
    {
      cwd: path.join(PHP_BACKEND_DIR, "public"),
      env,
      stdio: "pipe",
    }
  );

  phpProcess.stderr.on("data", () => {});
  phpProcess.stdout.on("data", () => {});

  // Wait for server to be responsive
  const start = Date.now();
  let ready = false;
  while (Date.now() - start < 10000) {
    try {
      const res = await fetch(`http://127.0.0.1:${port}/health`);
      if (res.ok) {
        ready = true;
        break;
      }
    } catch {
      await new Promise((r) => setTimeout(r, 100));
    }
  }

  if (!ready) {
    phpProcess.kill();
    throw new Error(`PHP server failed to start on port ${port} within 10 seconds.`);
  }

  return {
    base: `http://127.0.0.1:${port}`,
    stop: async () => {
      phpProcess.kill();
      await new Promise((r) => setTimeout(r, 200));
      try { fs.rmSync(dataDir, { recursive: true, force: true }); } catch {}
    },
  };
}

function normalizeDirectives(str) {
  if (!str) return new Set();
  return new Set(
    str
      .toLowerCase()
      .split(",")
      .map((s) => s.trim())
      .filter(Boolean)
  );
}

function normalizeJson(obj) {
  if (obj === null || typeof obj !== "object") return obj;

  if (Array.isArray(obj)) {
    return obj.map(normalizeJson);
  }

  const out = {};
  for (const [key, value] of Object.entries(obj)) {
    if (typeof value === "string") {
      // Job ID pattern
      if (/^aud_[a-zA-Z0-9_-]{24}$/.test(value)) {
        out[key] = "<JOB_ID>";
        continue;
      }
      // Access token pattern
      if (/^[a-zA-Z0-9_-]{43}$/.test(value)) {
        out[key] = "<TOKEN>";
        continue;
      }
      // Status path
      if (/^\/audits\/aud_[a-zA-Z0-9_-]{24}$/.test(value)) {
        out[key] = "/audits/<JOB_ID>";
        continue;
      }
      // Timestamp ISO
      if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{3})?Z$/.test(value)) {
        out[key] = "<TIMESTAMP>";
        continue;
      }
      // Artifact link with token
      if (/^\/audits\/aud_[a-zA-Z0-9_-]{24}\/artifacts\/([^?]+)\?token=[a-zA-Z0-9_-]{43}$/.test(value)) {
        const m = value.match(/^\/audits\/aud_[a-zA-Z0-9_-]{24}\/artifacts\/([^?]+)\?token=[a-zA-Z0-9_-]{43}$/);
        out[key] = `/audits/<JOB_ID>/artifacts/${m[1]}?token=<TOKEN>`;
        continue;
      }
    }
    out[key] = normalizeJson(value);
  }
  return out;
}

async function request(baseUrl, path, init = {}) {
  const url = `${baseUrl}${path}`;
  const res = await fetch(url, { ...init, cache: "no-store" });
  const rawBody = await res.arrayBuffer();
  const buffer = Buffer.from(rawBody);
  const text = buffer.toString("utf8");
  let json = null;
  try {
    json = JSON.parse(text);
  } catch {}

  return {
    status: res.status,
    headers: res.headers,
    buffer,
    text,
    json,
  };
}

function diffResponses(testName, nodeRes, phpRes) {
  const diffs = [];

  // 1. Status Code
  if (nodeRes.status !== phpRes.status) {
    diffs.push(`Status mismatch: Node=${nodeRes.status}, PHP=${phpRes.status}`);
  }

  // 2. CORS Headers
  const corsHeaders = [
    "access-control-allow-origin",
    "access-control-allow-methods",
    "access-control-allow-headers",
    "access-control-allow-credentials",
    "access-control-max-age",
    "vary",
  ];
  for (const h of corsHeaders) {
    const nodeVal = nodeRes.headers.get(h);
    const phpVal = phpRes.headers.get(h);
    if (nodeVal !== phpVal) {
      diffs.push(`Header [${h}] mismatch: Node="${nodeVal}", PHP="${phpVal}"`);
    }
  }

  // 3. CSP
  const nodeCsp = nodeRes.headers.get("content-security-policy");
  const phpCsp = phpRes.headers.get("content-security-policy");
  if (nodeCsp !== phpCsp) {
    diffs.push(`Header [content-security-policy] mismatch: Node="${nodeCsp}", PHP="${phpCsp}"`);
  }

  // 4. Cache-Control (set of directives)
  const nodeCc = nodeRes.headers.get("cache-control");
  const phpCc = phpRes.headers.get("cache-control");
  if (!nodeCc && !phpCc) {
    // Both omitted (e.g. 204 No Content)
  } else if (!nodeCc || !phpCc) {
    diffs.push(`Header [cache-control] presence mismatch: Node="${nodeCc}", PHP="${phpCc}"`);
  } else {
    const nodeDirectives = normalizeDirectives(nodeCc);
    const phpDirectives = normalizeDirectives(phpCc);
    // Symfony ResponseHeaderBag automatically appends ', private' when public/private directive is missing
    if (nodeDirectives.has("no-store") && phpDirectives.has("no-store")) {
      nodeDirectives.delete("private");
      phpDirectives.delete("private");
    }
    const nodeSorted = [...nodeDirectives].sort();
    const phpSorted = [...phpDirectives].sort();
    if (JSON.stringify(nodeSorted) !== JSON.stringify(phpSorted)) {
      diffs.push(`Header [cache-control] mismatch: Node="${nodeCc}", PHP="${phpCc}"`);
    }
  }

  // 5. X-Content-Type-Options
  const nodeXcto = nodeRes.headers.get("x-content-type-options");
  const phpXcto = phpRes.headers.get("x-content-type-options");
  if (nodeXcto !== phpXcto) {
    diffs.push(`Header [x-content-type-options] mismatch: Node="${nodeXcto}", PHP="${phpXcto}"`);
  }

  // 6. Location header
  const nodeLoc = nodeRes.headers.get("location");
  const phpLoc = phpRes.headers.get("location");
  if (Boolean(nodeLoc) !== Boolean(phpLoc)) {
    diffs.push(`Header [location] presence mismatch: Node="${nodeLoc}", PHP="${phpLoc}"`);
  } else if (nodeLoc && phpLoc) {
    if (!/^\/audits\/aud_[a-zA-Z0-9_-]{24}$/.test(nodeLoc)) {
      diffs.push(`Node Location format invalid: "${nodeLoc}"`);
    }
    if (!/^\/audits\/aud_[a-zA-Z0-9_-]{24}$/.test(phpLoc)) {
      diffs.push(`PHP Location format invalid: "${phpLoc}"`);
    }
  }

  // 7. Retry-After header
  const nodeRa = nodeRes.headers.get("retry-after");
  const phpRa = phpRes.headers.get("retry-after");
  if (nodeRa !== phpRa) {
    diffs.push(`Header [retry-after] mismatch: Node="${nodeRa}", PHP="${phpRa}"`);
  }

  // 8. Content-Type header (normalized)
  const nodeCt = (nodeRes.headers.get("content-type") || "").toLowerCase();
  const phpCt = (phpRes.headers.get("content-type") || "").toLowerCase();
  if (nodeRes.status === 204 && phpRes.status === 204) {
    // 204 No Content
  } else if (nodeCt !== phpCt) {
    diffs.push(`Header [content-type] mismatch: Node="${nodeCt}", PHP="${phpCt}"`);
  }

  // 9. JSON Body or Binary Body
  if (nodeRes.json !== null || phpRes.json !== null) {
    if (nodeRes.json === null) {
      diffs.push("Node returned non-JSON body while PHP returned JSON");
    } else if (phpRes.json === null) {
      diffs.push("PHP returned non-JSON body while Node returned JSON");
    } else {
      const normNode = normalizeJson(nodeRes.json);
      const normPhp = normalizeJson(phpRes.json);
      try {
        assert.deepStrictEqual(normNode, normPhp);
      } catch (err) {
        diffs.push(`JSON shape/value mismatch:\nNode: ${JSON.stringify(normNode)}\nPHP:  ${JSON.stringify(normPhp)}`);
      }
    }
  } else {
    // Binary or raw text diff
    if (!nodeRes.buffer.equals(phpRes.buffer)) {
      diffs.push(`Body content mismatch: length Node=${nodeRes.buffer.length}, PHP=${phpRes.buffer.length}`);
    }
  }

  return diffs;
}

export async function runParityCheck(options = {}) {
  const phpBin = options.phpBin || (process.platform === "win32" ? "C:\\xampp\\php\\php.exe" : "php");
  let nodeServer = null;
  let phpServer = null;

  const nodeBase = options.nodeUrl || null;
  const phpBase = options.phpUrl || null;

  let nodeUrl = nodeBase;
  let phpUrl = phpBase;

  try {
    if (!nodeUrl) {
      const port = await findOpenPort();
      const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), "aksara-parity-node-"));
      nodeServer = await startNodeServer({ port, dataDir: tempDir });
      nodeUrl = nodeServer.base;
    }

    if (!phpUrl) {
      const port = await findOpenPort();
      const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), "aksara-parity-php-"));
      phpServer = await startPhpServer({ port, dataDir: tempDir, phpBin });
      phpUrl = phpServer.base;
    }

    console.log(`\n======================================================`);
    console.log(`  AksaraNetra Parity Check: Node vs PHP Backend`);
    console.log(`  Node URL: ${nodeUrl}`);
    console.log(`  PHP URL:  ${phpUrl}`);
    console.log(`======================================================\n`);

    const results = [];

    async function runCase(name, path, init = {}, postCheck = null) {
      const nodeRes = await request(nodeUrl, path, init);
      const phpRes = await request(phpUrl, path, init);
      const diffs = diffResponses(name, nodeRes, phpRes);

      if (postCheck) {
        const extraDiffs = postCheck(nodeRes, phpRes);
        if (extraDiffs?.length) diffs.push(...extraDiffs);
      }

      const passed = diffs.length === 0;
      results.push({ name, passed, diffs, nodeRes, phpRes });

      if (passed) {
        console.log(`  [PASS] ${name} (HTTP ${nodeRes.status})`);
      } else {
        console.log(`  [FAIL] ${name}`);
        for (const d of diffs) {
          console.log(`         -> ${d}`);
        }
      }

      return { nodeRes, phpRes };
    }

    // ----------------------------------------------------
    // Test Suite
    // ----------------------------------------------------

    // 1. Health endpoint with allowed origin
    await runCase(
      "1. GET /health with allowed origin",
      "/health",
      { headers: { origin: TEST_ORIGIN } }
    );

    // 2. Health endpoint without origin header
    await runCase(
      "2. GET /health without origin header",
      "/health"
    );

    // 3. Preflight CORS OPTIONS /audits
    await runCase(
      "3. Preflight OPTIONS /audits",
      "/audits",
      {
        method: "OPTIONS",
        headers: {
          origin: TEST_ORIGIN,
          "access-control-request-method": "POST",
          "access-control-request-headers": "Authorization, Content-Type",
        },
      }
    );

    // 4. Preflight CORS OPTIONS /health
    await runCase(
      "4. Preflight OPTIONS /health",
      "/health",
      {
        method: "OPTIONS",
        headers: {
          origin: TEST_ORIGIN,
          "access-control-request-method": "GET",
        },
      }
    );

    // 5. Disallowed CORS origin on GET /health
    await runCase(
      "5. GET /health with disallowed origin (403)",
      "/health",
      { headers: { origin: DISALLOWED_ORIGIN } }
    );

    // 6. Disallowed CORS origin on POST /audits
    await runCase(
      "6. POST /audits with disallowed origin (403)",
      "/audits",
      {
        method: "POST",
        headers: { origin: DISALLOWED_ORIGIN, "content-type": "application/json" },
        body: JSON.stringify({ url: "https://example.com" }),
      }
    );

    // 7. Cache lookup - cached URL (available: true)
    await runCase(
      "7. GET /audits/cache?url=... (cached hit)",
      `/audits/cache?url=${encodeURIComponent("https://cached.example.com/")}`,
      { headers: { origin: TEST_ORIGIN } }
    );

    // 8. Cache lookup - non-cached URL (available: false)
    await runCase(
      "8. GET /audits/cache?url=... (cache miss)",
      `/audits/cache?url=${encodeURIComponent("https://notcached.example.com/")}`,
      { headers: { origin: TEST_ORIGIN } }
    );

    // 9. Cache lookup - SSRF private IP (400 blocked-address)
    await runCase(
      "9. GET /audits/cache?url=http://127.0.0.1 (400 blocked-address)",
      `/audits/cache?url=${encodeURIComponent("http://127.0.0.1/")}`,
      { headers: { origin: TEST_ORIGIN } }
    );

    // 10. Cache lookup - Invalid URL (400 invalid-url)
    await runCase(
      "10. GET /audits/cache?url=invalid (400 invalid-url)",
      "/audits/cache?url=invalid",
      { headers: { origin: TEST_ORIGIN } }
    );

    // 11. Cache lookup - Missing URL query param (400)
    await runCase(
      "11. GET /audits/cache (missing url param 400)",
      "/audits/cache",
      { headers: { origin: TEST_ORIGIN } }
    );

    // 12. Create audit - Bad Content-Type (415)
    await runCase(
      "12. POST /audits with Content-Type: text/plain (415)",
      "/audits",
      {
        method: "POST",
        headers: { "content-type": "text/plain", origin: TEST_ORIGIN },
        body: "plain text payload",
      }
    );

    // 13. Create audit - Malformed JSON (400)
    await runCase(
      "13. POST /audits with broken JSON (400)",
      "/audits",
      {
        method: "POST",
        headers: { "content-type": "application/json", origin: TEST_ORIGIN },
        body: '{"url": "broken',
      }
    );

    // 14. Create audit - Body too large (413)
    const largeBody = JSON.stringify({ url: "https://example.com", padding: "x".repeat(9000) });
    await runCase(
      "14. POST /audits with payload > 8192 bytes (413)",
      "/audits",
      {
        method: "POST",
        headers: { "content-type": "application/json", origin: TEST_ORIGIN },
        body: largeBody,
      }
    );

    // 15. Create audit - Blocked private IP URL (400)
    await runCase(
      "15. POST /audits with private IP (400 blocked-address)",
      "/audits",
      {
        method: "POST",
        headers: { "content-type": "application/json", origin: TEST_ORIGIN },
        body: JSON.stringify({ url: "http://127.0.0.1:8080" }),
      }
    );

    // 16. Create audit - Unsupported protocol (400)
    await runCase(
      "16. POST /audits with unsupported protocol ftp:// (400)",
      "/audits",
      {
        method: "POST",
        headers: { "content-type": "application/json", origin: TEST_ORIGIN },
        body: JSON.stringify({ url: "ftp://example.com/file" }),
      }
    );

    // 17. Create audit - Empty URL (400)
    await runCase(
      "17. POST /audits with empty URL (400)",
      "/audits",
      {
        method: "POST",
        headers: { "content-type": "application/json", origin: TEST_ORIGIN },
        body: JSON.stringify({ url: "" }),
      }
    );

    // 18. Create audit - Valid URL (202 Queued)
    let nodeQueuedJob = null;
    let phpQueuedJob = null;
    const queuedCreate = await runCase(
      "18. POST /audits valid URL (202 Queued)",
      "/audits",
      {
        method: "POST",
        headers: { "content-type": "application/json", origin: TEST_ORIGIN },
        body: JSON.stringify({ url: "https://freshnewaudit.example.com/" }),
      }
    );
    nodeQueuedJob = queuedCreate.nodeRes.json;
    phpQueuedJob = queuedCreate.phpRes.json;

    // 19. Create audit - Reuse cached result (200 Completed)
    await runCase(
      "19. POST /audits with reuseExisting: true (200 Completed)",
      "/audits",
      {
        method: "POST",
        headers: { "content-type": "application/json", origin: TEST_ORIGIN },
        body: JSON.stringify({ url: "https://cached.example.com/", reuseExisting: true }),
      }
    );

    // 20. Status - Without Authorization token (404)
    await runCase(
      "20. GET /audits/:id without token (404)",
      `/audits/${nodeQueuedJob?.jobId || "aud_test"}`
    );

    // 21. Status - Wrong Authorization Bearer token (404)
    await runCase(
      "21. GET /audits/:id with wrong token (404)",
      `/audits/${nodeQueuedJob?.jobId || "aud_test"}`,
      { headers: { authorization: "Bearer wrong_token_value_000000000000000000000000" } }
    );

    // 22. Status - Queued job with right Bearer token (200)
    // Execute separately with respective tokens
    const nodeStatusRes = await request(nodeUrl, `/audits/${nodeQueuedJob.jobId}`, {
      headers: { authorization: `Bearer ${nodeQueuedJob.accessToken}` },
    });
    const phpStatusRes = await request(phpUrl, `/audits/${phpQueuedJob.jobId}`, {
      headers: { authorization: `Bearer ${phpQueuedJob.accessToken}` },
    });
    const statusDiffs = diffResponses("22. GET /audits/:id queued status", nodeStatusRes, phpStatusRes);
    if (statusDiffs.length === 0) {
      console.log(`  [PASS] 22. GET /audits/:id queued status with right token (HTTP 200)`);
      results.push({ name: "22. GET /audits/:id queued status", passed: true, diffs: [] });
    } else {
      console.log(`  [FAIL] 22. GET /audits/:id queued status`);
      for (const d of statusDiffs) console.log(`         -> ${d}`);
      results.push({ name: "22. GET /audits/:id queued status", passed: false, diffs: statusDiffs });
    }

    // 23. Status - Right token in query param (?accessToken=) (200)
    const nodeStatusQueryRes = await request(nodeUrl, `/audits/${nodeQueuedJob.jobId}?accessToken=${nodeQueuedJob.accessToken}`);
    const phpStatusQueryRes = await request(phpUrl, `/audits/${phpQueuedJob.jobId}?accessToken=${phpQueuedJob.accessToken}`);
    const statusQueryDiffs = diffResponses("23. GET /audits/:id with query accessToken", nodeStatusQueryRes, phpStatusQueryRes);
    if (statusQueryDiffs.length === 0) {
      console.log(`  [PASS] 23. GET /audits/:id with query accessToken (HTTP 200)`);
      results.push({ name: "23. GET /audits/:id with query accessToken", passed: true, diffs: [] });
    } else {
      console.log(`  [FAIL] 23. GET /audits/:id with query accessToken`);
      for (const d of statusQueryDiffs) console.log(`         -> ${d}`);
      results.push({ name: "23. GET /audits/:id with query accessToken", passed: false, diffs: statusQueryDiffs });
    }

    // 24. Status - Completed pre-seeded job (200)
    await runCase(
      "24. GET /audits/:id completed pre-seeded job (200)",
      `/audits/${COMPLETED_JOB_ID}`,
      { headers: { authorization: `Bearer ${COMPLETED_ACCESS_TOKEN}` } }
    );

    // 25. Result - Queued job before completion (409 not-ready)
    const nodeResNotReady = await request(nodeUrl, `/audits/${nodeQueuedJob.jobId}/result`, {
      headers: { authorization: `Bearer ${nodeQueuedJob.accessToken}` },
    });
    const phpResNotReady = await request(phpUrl, `/audits/${phpQueuedJob.jobId}/result`, {
      headers: { authorization: `Bearer ${phpQueuedJob.accessToken}` },
    });
    const notReadyDiffs = diffResponses("25. GET /audits/:id/result before completion", nodeResNotReady, phpResNotReady);
    if (notReadyDiffs.length === 0) {
      console.log(`  [PASS] 25. GET /audits/:id/result before completion (HTTP 409 not-ready)`);
      results.push({ name: "25. GET /audits/:id/result before completion", passed: true, diffs: [] });
    } else {
      console.log(`  [FAIL] 25. GET /audits/:id/result before completion`);
      for (const d of notReadyDiffs) console.log(`         -> ${d}`);
      results.push({ name: "25. GET /audits/:id/result before completion", passed: false, diffs: notReadyDiffs });
    }

    // 26. Result - Completed job without token (404)
    await runCase(
      "26. GET /audits/:id/result without token (404)",
      `/audits/${COMPLETED_JOB_ID}/result`
    );

    // 27. Result - Completed job with wrong token (404)
    await runCase(
      "27. GET /audits/:id/result with wrong token (404)",
      `/audits/${COMPLETED_JOB_ID}/result`,
      { headers: { authorization: "Bearer invalid_result_token_000000000000000000000" } }
    );

    // 28. Result - Completed job with right token (200)
    await runCase(
      "28. GET /audits/:id/result completed job (200)",
      `/audits/${COMPLETED_JOB_ID}/result`,
      { headers: { authorization: `Bearer ${COMPLETED_ACCESS_TOKEN}` } }
    );

    // 29. Cancel - Wrong token (404)
    const nodeCancelWrong = await request(nodeUrl, `/audits/${nodeQueuedJob.jobId}`, {
      method: "DELETE",
      headers: { authorization: "Bearer wrong_cancel_token_000000000000000000000" },
    });
    const phpCancelWrong = await request(phpUrl, `/audits/${phpQueuedJob.jobId}`, {
      method: "DELETE",
      headers: { authorization: "Bearer wrong_cancel_token_000000000000000000000" },
    });
    const cancelWrongDiffs = diffResponses("29. DELETE /audits/:id with wrong token", nodeCancelWrong, phpCancelWrong);
    if (cancelWrongDiffs.length === 0) {
      console.log(`  [PASS] 29. DELETE /audits/:id with wrong token (HTTP 404)`);
      results.push({ name: "29. DELETE /audits/:id with wrong token", passed: true, diffs: [] });
    } else {
      console.log(`  [FAIL] 29. DELETE /audits/:id with wrong token`);
      for (const d of cancelWrongDiffs) console.log(`         -> ${d}`);
      results.push({ name: "29. DELETE /audits/:id with wrong token", passed: false, diffs: cancelWrongDiffs });
    }

    // 30. Cancel - Right token (202 cancelled)
    const nodeCancelRight = await request(nodeUrl, `/audits/${nodeQueuedJob.jobId}`, {
      method: "DELETE",
      headers: { authorization: `Bearer ${nodeQueuedJob.accessToken}` },
    });
    const phpCancelRight = await request(phpUrl, `/audits/${phpQueuedJob.jobId}`, {
      method: "DELETE",
      headers: { authorization: `Bearer ${phpQueuedJob.accessToken}` },
    });
    const cancelRightDiffs = diffResponses("30. DELETE /audits/:id with right token", nodeCancelRight, phpCancelRight);
    if (cancelRightDiffs.length === 0) {
      console.log(`  [PASS] 30. DELETE /audits/:id with right token (HTTP 202 cancelled)`);
      results.push({ name: "30. DELETE /audits/:id with right token", passed: true, diffs: [] });
    } else {
      console.log(`  [FAIL] 30. DELETE /audits/:id with right token`);
      for (const d of cancelRightDiffs) console.log(`         -> ${d}`);
      results.push({ name: "30. DELETE /audits/:id with right token", passed: false, diffs: cancelRightDiffs });
    }

    // 31. Artifact - Missing share token (404)
    await runCase(
      "31. GET /audits/:id/artifacts/reader without token (404)",
      `/audits/${COMPLETED_JOB_ID}/artifacts/reader`
    );

    // 32. Artifact - Wrong share token (404)
    await runCase(
      "32. GET /audits/:id/artifacts/reader with wrong token (404)",
      `/audits/${COMPLETED_JOB_ID}/artifacts/reader?token=invalid_share_token`
    );

    // 33. Artifact - Bearer token ignored in favor of query token (Quirk #2: 404)
    await runCase(
      "33. GET /audits/:id/artifacts/reader with Bearer header (Quirk #2: 404)",
      `/audits/${COMPLETED_JOB_ID}/artifacts/reader`,
      { headers: { authorization: `Bearer ${COMPLETED_ACCESS_TOKEN}` } }
    );

    // 34. Artifact - Unknown artifact name (404)
    await runCase(
      "34. GET /audits/:id/artifacts/unknown (404)",
      `/audits/${COMPLETED_JOB_ID}/artifacts/unknown?token=${COMPLETED_SHARE_TOKEN}`
    );

    // 35. Artifact - Path traversal attempt (404)
    await runCase(
      "35. GET /audits/:id/artifacts/..%2fjobs.sqlite (404 traversal)",
      `/audits/${COMPLETED_JOB_ID}/artifacts/..%2fjobs.sqlite?token=${COMPLETED_SHARE_TOKEN}`
    );

    // 36. Artifact - Valid reader HTML
    await runCase(
      "36. GET /audits/:id/artifacts/reader (200 text/html)",
      `/audits/${COMPLETED_JOB_ID}/artifacts/reader?token=${COMPLETED_SHARE_TOKEN}`
    );

    // 37. Artifact - Valid patched HTML
    await runCase(
      "37. GET /audits/:id/artifacts/patched (200 text/html)",
      `/audits/${COMPLETED_JOB_ID}/artifacts/patched?token=${COMPLETED_SHARE_TOKEN}`
    );

    // 38. Artifact - Valid report.pdf
    await runCase(
      "38. GET /audits/:id/artifacts/report.pdf (200 application/pdf)",
      `/audits/${COMPLETED_JOB_ID}/artifacts/report.pdf?token=${COMPLETED_SHARE_TOKEN}`
    );

    // 39. Artifact - Valid screenshot PNG
    await runCase(
      "39. GET /audits/:id/artifacts/screenshot (200 image/png)",
      `/audits/${COMPLETED_JOB_ID}/artifacts/screenshot?token=${COMPLETED_SHARE_TOKEN}`
    );

    // 40. Artifact - Expired job artifact (410)
    // Wait for the seeded 1200ms expiration window to lapse
    await new Promise((r) => setTimeout(r, 1300));
    await runCase(
      "40. GET /audits/:id/artifacts/reader expired job (410)",
      `/audits/${EXPIRED_JOB_ID}/artifacts/reader?token=${EXPIRED_SHARE_TOKEN}`
    );

    // 41. 404 Fallback endpoint
    await runCase(
      "41. GET /non-existent-endpoint (404)",
      "/non-existent-endpoint"
    );

    // 42. Rate limiting (loop until 429)
    console.log(`\n  Executing rate limit saturation (limit: 5/hr)...`);
    for (let i = 0; i < 4; i++) {
      await request(nodeUrl, "/audits", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ url: `https://rate-test-${i}.example.com/` }),
      });
      await request(phpUrl, "/audits", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ url: `https://rate-test-${i}.example.com/` }),
      });
    }
    await runCase(
      "42. POST /audits exceeding rate limit (429)",
      "/audits",
      {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ url: "https://rate-exceeded.example.com/" }),
      }
    );

    // ----------------------------------------------------
    // Summary
    // ----------------------------------------------------
    const passedCount = results.filter((r) => r.passed).length;
    const failedCount = results.filter((r) => !r.passed).length;

    console.log(`\n======================================================`);
    console.log(`  Parity Check Results: ${passedCount} passed, ${failedCount} failed (${results.length} total)`);
    console.log(`======================================================\n`);

    if (failedCount > 0) {
      console.error(`FAILED: ${failedCount} parity checks did not match.`);
      process.exitCode = 1;
    } else {
      console.log(`SUCCESS: Full 100% parity verified between Node and PHP backends!`);
    }

  } finally {
    if (!options.keepAlive) {
      if (nodeServer) await nodeServer.stop();
      if (phpServer) await phpServer.stop();
    }
  }
}

// CLI entrypoint
import { pathToFileURL } from "node:url";

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
  const args = process.argv.slice(2);
  const options = {};
  for (let i = 0; i < args.length; i++) {
    if (args[i] === "--node-url" && args[i + 1]) options.nodeUrl = args[++i];
    if (args[i] === "--php-url" && args[i + 1]) options.phpUrl = args[++i];
    if (args[i] === "--php-bin" && args[i + 1]) options.phpBin = args[++i];
    if (args[i] === "--keep-alive") options.keepAlive = true;
  }

  runParityCheck(options).catch((err) => {
    console.error("Parity check fatal error:", err);
    process.exit(1);
  });
}
