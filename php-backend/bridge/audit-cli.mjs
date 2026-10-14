#!/usr/bin/env node
import { checkUrl } from "../../engine/src/fetcher/url-policy.mjs";

const [, , command, ...args] = process.argv;

if (command === "check-url") {
  const url = args[0] ?? "";
  const verdict = checkUrl(url);
  process.stdout.write(JSON.stringify(verdict) + "\n");
  process.exit(0);
}

process.stderr.write(`Unknown command: ${command}\n`);
process.exit(1);
