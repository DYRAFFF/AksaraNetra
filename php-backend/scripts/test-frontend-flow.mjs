/**
 * Manual flow verification script for Phase 7
 * Simulates the exact frontend flow as defined in src/lib/audit-api.ts
 * against a live running PHP backend and worker.
 */

import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import fs from "node:fs";
import net from "node:net";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const REPO_ROOT = path.resolve(__dirname, "../..");
const PHP_BACKEND_DIR = path.resolve(REPO_ROOT, "php-backend");
const PHP_BIN = process.platform === "win32" ? "C:\\xampp\\php\\php.exe" : "php";

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

async function runCommand(cmd, args, options = {}) {
  return new Promise((resolve, reject) => {
    const proc = spawn(cmd, args, { stdio: "inherit", ...options });
    proc.on("close", (code) => {
      if (code === 0) resolve();
      else reject(new Error(`${cmd} ${args.join(" ")} exited with code ${code}`));
    });
    proc.on("error", reject);
  });
}

async function main() {
  const port = await findOpenPort();
  const dataDir = fs.mkdtempSync(path.join(os.tmpdir(), "aksara-frontend-flow-"));
  const env = {
    ...process.env,
    APP_ENV: "testing",
    APP_DEBUG: "false",
    BACKEND_DATA_DIR: dataDir,
    BACKEND_SECRET: "test-secret-at-least-32-chars-long-frontend-flow",
    FRONTEND_ORIGINS: "http://localhost:3000",
    AUDIT_RATE_LIMIT: "10",
    AUDIT_TIMEOUT_MS: "60000",
    PORT: String(port),
    HOST: "127.0.0.1",
  };

  console.log(`Setting up database in ${dataDir}...`);
  await runCommand(PHP_BIN, ["artisan", "migrate", "--force"], { cwd: PHP_BACKEND_DIR, env });

  console.log(`Starting PHP server on port ${port}...`);
  const serverProc = spawn(
    PHP_BIN,
    ["-S", `127.0.0.1:${port}`, "../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"],
    { cwd: path.join(PHP_BACKEND_DIR, "public"), env, stdio: "pipe" }
  );

  try {
    // Wait for health
    let ready = false;
    for (let i = 0; i < 50; i++) {
      try {
        const res = await fetch(`http://127.0.0.1:${port}/health`);
        if (res.ok) { ready = true; break; }
      } catch {
        await new Promise((r) => setTimeout(r, 100));
      }
    }
    assert.ok(ready, "PHP server did not become ready in time");
    console.log(`PHP API server ready on http://127.0.0.1:${port}`);

    const API_BASE = `http://127.0.0.1:${port}`;

    // Step 1: Frontend calls checkAuditCache
    console.log("\n[Step 1] Checking audit cache for https://example.com/...");
    const cacheRes = await fetch(`${API_BASE}/audits/cache?url=${encodeURIComponent("https://example.com/")}`);
    assert.equal(cacheRes.status, 200);
    const cacheData = await cacheRes.json();
    console.log("Cache check result:", cacheData);
    assert.equal(cacheData.available, false);

    // Step 2: Frontend calls createAudit
    console.log("\n[Step 2] Creating audit for https://example.com/...");
    const createRes = await fetch(`${API_BASE}/audits`, {
      method: "POST",
      headers: { "content-type": "application/json", origin: "http://localhost:3000" },
      body: JSON.stringify({ url: "https://example.com/", reuseExisting: false }),
    });
    assert.equal(createRes.status, 202);
    const created = await createRes.json();
    console.log("Created audit:", created);
    assert.match(created.jobId, /^aud_/);
    assert.ok(created.accessToken.length > 30);
    assert.equal(created.status, "queued");

    // Step 3: Frontend polls status (currently queued)
    console.log("\n[Step 3] Polling status before worker processing...");
    const poll1Res = await fetch(`${API_BASE}/audits/${created.jobId}`, {
      headers: { authorization: `Bearer ${created.accessToken}` },
    });
    assert.equal(poll1Res.status, 200);
    const poll1 = await poll1Res.json();
    console.log("Initial polled status:", poll1.job.status, "stage:", poll1.job.stage);
    assert.equal(poll1.job.status, "queued");

    // Step 4: Run worker to process the job
    console.log("\n[Step 4] Triggering worker to process audit (php artisan audit:work --once)...");
    await runCommand(PHP_BIN, ["artisan", "audit:work", "--once"], { cwd: PHP_BACKEND_DIR, env });

    // Step 5: Frontend polls status again (completed)
    console.log("\n[Step 5] Polling status after worker processing...");
    const poll2Res = await fetch(`${API_BASE}/audits/${created.jobId}`, {
      headers: { authorization: `Bearer ${created.accessToken}` },
    });
    assert.equal(poll2Res.status, 200);
    const poll2 = await poll2Res.json();
    console.log("Polled status:", poll2.job.status, "progress:", poll2.job.progress, "stage:", poll2.job.stage);
    assert.equal(poll2.job.status, "completed");

    // Step 6: Frontend calls getAuditResult
    console.log("\n[Step 6] Fetching audit result (getAuditResult)...");
    const resultRes = await fetch(`${API_BASE}/audits/${created.jobId}/result`, {
      headers: { authorization: `Bearer ${created.accessToken}` },
    });
    assert.equal(resultRes.status, 200);
    const resultData = await resultRes.json();
    console.log("Result summary:", resultData.result.snapshot.summary);
    console.log("Result links:", resultData.links);
    assert.ok(resultData.links.reader);
    assert.ok(resultData.links.patched);
    assert.ok(resultData.links.reportPdf);
    assert.ok(resultData.links.screenshot);

    // Step 7: Frontend accesses artifacts via links
    console.log("\n[Step 7] Accessing reader artifact via link...");
    const readerRes = await fetch(`${API_BASE}${resultData.links.reader}`);
    assert.equal(readerRes.status, 200);
    assert.equal(readerRes.headers.get("content-type"), "text/html; charset=utf-8");
    const readerHtml = await readerRes.text();
    assert.ok(readerHtml.length > 50);
    console.log(`Reader HTML retrieved successfully (${readerHtml.length} bytes)`);

    console.log("Accessing patched artifact via link...");
    const patchedRes = await fetch(`${API_BASE}${resultData.links.patched}`);
    assert.equal(patchedRes.status, 200);
    console.log(`Patched HTML retrieved successfully (${(await patchedRes.text()).length} bytes)`);

    console.log("Accessing screenshot artifact via link...");
    const screenshotRes = await fetch(`${API_BASE}${resultData.links.screenshot}`);
    assert.equal(screenshotRes.status, 200);
    assert.equal(screenshotRes.headers.get("content-type"), "image/png");
    console.log(`Screenshot PNG retrieved successfully (${(await screenshotRes.arrayBuffer()).byteLength} bytes)`);

    // Step 8: Cache hit verification (checkAuditCache returns available: true)
    console.log("\n[Step 8] Verifying cache availability after completion...");
    const cacheHitRes = await fetch(`${API_BASE}/audits/cache?url=${encodeURIComponent("https://example.com/")}`);
    assert.equal(cacheHitRes.status, 200);
    const cacheHitData = await cacheHitRes.json();
    console.log("Cache hit check result:", cacheHitData);
    assert.equal(cacheHitData.available, true);
    assert.ok(cacheHitData.capturedAt);

    console.log("\n=== FRONTEND FLOW VERIFICATION SUCCESSFUL: 100% COMPATIBLE ===");
  } finally {
    serverProc.kill();
    try { fs.rmSync(dataDir, { recursive: true, force: true }); } catch {}
  }
}

main().catch((err) => {
  console.error("Frontend flow verification failed:", err);
  process.exit(1);
});
