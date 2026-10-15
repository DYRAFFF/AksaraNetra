#!/usr/bin/env node
import fs from "node:fs";
import { checkUrl } from "../../engine/src/fetcher/url-policy.mjs";

const [, , command, ...args] = process.argv;

if (command === "check-url") {
  const url = args[0] ?? "";
  const verdict = checkUrl(url);
  process.stdout.write(JSON.stringify(verdict) + "\n");
  process.exit(0);
}

if (command === "audit") {
  const controller = new AbortController();
  const handleAbort = () => controller.abort();
  process.on("SIGTERM", handleAbort);
  process.on("SIGINT", handleAbort);

  let rawInput = "";
  try {
    for await (const chunk of process.stdin) {
      rawInput += chunk;
    }
  } catch (err) {
    process.stderr.write(`Failed to read stdin: ${err.message}\n`);
    process.exit(1);
  }

  let params;
  try {
    params = JSON.parse(rawInput);
  } catch (err) {
    process.stdout.write(JSON.stringify({
      type: "error",
      code: "invalid-input",
      stage: 1,
      message: "Invalid JSON input on stdin: " + err.message,
    }) + "\n");
    process.exit(1);
  }

  const { id, url, artifactDir } = params;
  const { auditUrl } = await import("../../engine/src/api/index.mjs");

  const onProgress = async (update) => {
    process.stdout.write(JSON.stringify({ type: "progress", ...update }) + "\n");
  };

  try {
    const outcome = await auditUrl({
      url,
      id,
      origin: "manual",
      outputDir: artifactDir,
      signal: controller.signal,
      onProgress,
    });

    if (!outcome.ok) {
      process.stdout.write(JSON.stringify({
        type: "error",
        code: outcome.code,
        stage: outcome.stage,
        message: outcome.message,
      }) + "\n");
      process.exit(0);
    }

    process.stdout.write(JSON.stringify({
      type: "result",
      snapshot: outcome.snapshot,
      files: outcome.files,
    }) + "\n");
    process.exit(0);
  } catch (error) {
    if (controller.signal.aborted) {
      process.exit(0);
    }
    process.stdout.write(JSON.stringify({
      type: "error",
      code: error.code || "audit-failed",
      stage: error.stage || 7,
      message: error.message || String(error),
    }) + "\n");
    process.exit(0);
  }
}

if (command === "render-pdf") {
  const [htmlPath, outPath] = args;
  if (!htmlPath || !outPath) {
    process.stderr.write("Usage: audit-cli.mjs render-pdf <htmlPath> <outPath>\n");
    process.exit(1);
  }

  const controller = new AbortController();
  const handleAbort = () => controller.abort();
  process.on("SIGTERM", handleAbort);
  process.on("SIGINT", handleAbort);

  const { renderPdf } = await import("../../engine/src/runner/index.mjs");

  try {
    const html = fs.readFileSync(htmlPath, "utf8");
    await renderPdf({ html, outputPath: outPath, signal: controller.signal });
    process.stdout.write(JSON.stringify({ ok: true, path: outPath }) + "\n");
    process.exit(0);
  } catch (error) {
    if (controller.signal.aborted) {
      process.exit(0);
    }
    process.stderr.write(error?.message || String(error) + "\n");
    process.stdout.write(JSON.stringify({ ok: false, message: error?.message || String(error) }) + "\n");
    process.exit(1);
  }
}

process.stderr.write(`Unknown command: ${command}\n`);
process.exit(1);
