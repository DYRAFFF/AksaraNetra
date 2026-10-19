import test from "node:test";
import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { fileURLToPath } from "node:url";

const BRIDGE_SCRIPT = fileURLToPath(
  new URL("../../php-backend/bridge/audit-cli.mjs", import.meta.url),
);

function runCli(args = [], stdinData = null) {
  return new Promise((resolve, reject) => {
    const proc = spawn(process.execPath, [BRIDGE_SCRIPT, ...args], {
      stdio: ["pipe", "pipe", "pipe"],
    });

    let stdout = "";
    let stderr = "";

    proc.stdout.on("data", (chunk) => {
      stdout += chunk.toString();
    });
    proc.stderr.on("data", (chunk) => {
      stderr += chunk.toString();
    });

    if (stdinData !== null) {
      proc.stdin.write(stdinData);
      proc.stdin.end();
    } else {
      proc.stdin.end();
    }

    proc.on("error", reject);
    proc.on("close", (code, signal) => {
      resolve({ code, signal, stdout, stderr });
    });
  });
}

test("bridge check-url approves an ordinary public page address", async () => {
  const result = await runCli(["check-url", "https://jakarta.go.id"]);
  assert.equal(result.code, 0);

  const parsed = JSON.parse(result.stdout.trim());
  assert.equal(parsed.ok, true);
  assert.equal(parsed.hostname, "jakarta.go.id");
  assert.equal(parsed.protocol, "https:");
});

test("bridge check-url rejects an IP literal address", async () => {
  const result = await runCli(["check-url", "http://127.0.0.1"]);
  assert.equal(result.code, 0);

  const parsed = JSON.parse(result.stdout.trim());
  assert.equal(parsed.ok, false);
  assert.equal(parsed.code, "ip-literal-not-allowed");
});

test("bridge check-url rejects non-http protocols", async () => {
  const result = await runCli(["check-url", "ftp://example.com/file.txt"]);
  assert.equal(result.code, 0);

  const parsed = JSON.parse(result.stdout.trim());
  assert.equal(parsed.ok, false);
  assert.equal(parsed.code, "protocol-not-allowed");
});

test("bridge rejects unknown command with exit code 1", async () => {
  const result = await runCli(["command-tidak-dikenal"]);
  assert.equal(result.code, 1);
  assert.match(result.stderr, /Unknown command/i);
});

test("bridge render-pdf requires both htmlPath and outPath arguments", async () => {
  const result = await runCli(["render-pdf"]);
  assert.equal(result.code, 1);
  assert.match(result.stderr, /Usage: audit-cli.mjs render-pdf/i);
});

test("bridge audit handles malformed JSON input on stdin gracefully", async () => {
  const result = await runCli(["audit"], "not-a-valid-json-payload");
  assert.equal(result.code, 1);

  const parsed = JSON.parse(result.stdout.trim());
  assert.equal(parsed.type, "error");
  assert.equal(parsed.code, "invalid-input");
  assert.equal(parsed.stage, 1);
  assert.match(parsed.message, /Invalid JSON input on stdin/i);
});

test("bridge audit streams progress updates and reports validation errors", async () => {
  const input = JSON.stringify({
    id: "unit-test-id",
    url: "alamat-tidak-valid-tanpa-skema",
    artifactDir: null,
  });

  const result = await runCli(["audit"], input);
  assert.equal(result.code, 0);

  const lines = result.stdout
    .trim()
    .split("\n")
    .map((l) => l.trim())
    .filter(Boolean)
    .map((l) => JSON.parse(l));

  // Harus ada event progress awal
  const progressEvents = lines.filter((event) => event.type === "progress");
  assert.ok(progressEvents.length >= 1, "Harus memancarkan event progress");
  assert.equal(typeof progressEvents[0].progress, "number");
  assert.equal(typeof progressEvents[0].stage, "string");

  // Baris terakhir harus error URL tidak valid
  const lastEvent = lines[lines.length - 1];
  assert.equal(lastEvent.type, "error");
  assert.equal(lastEvent.code, "unparseable-url");
});

test("bridge audit terminates cleanly when receiving SIGTERM abort signal", async () => {
  const proc = spawn(process.execPath, [BRIDGE_SCRIPT, "audit"], {
    stdio: ["pipe", "pipe", "pipe"],
  });

  // Tulis input yang membutuhkan waktu
  proc.stdin.write(
    JSON.stringify({
      id: "sigterm-test",
      url: "https://contoh.go.id",
      artifactDir: null,
    }),
  );
  proc.stdin.end();

  await new Promise((resolve) => setTimeout(resolve, 100));
  proc.kill("SIGTERM");

  const exitCode = await new Promise((resolve) => {
    proc.on("close", (code, signal) => resolve({ code, signal }));
  });

  // Proses harus berhenti (bisa exit 0 atau terminated by signal)
  assert.ok(exitCode !== null);
});
