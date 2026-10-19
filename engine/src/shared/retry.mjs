/**
 * Utilitas percobaan ulang cerdas (exponential backoff with jitter) untuk operasi jaringan.
 *
 * Hanya me-retry error sementara (transient network / DNS fluktuasi).
 * Error deterministik (seperti pelanggaran kebijakan alamat privat/SSRF) ditolak seketika
 * tanpa retry sia-sia.
 */

const TRANSIENT_ERROR_CODES = new Set([
  "EAI_AGAIN",
  "ETIMEDOUT",
  "ECONNRESET",
  "ECONNREFUSED",
  "EHOSTUNREACH",
  "UND_ERR_CONNECT_TIMEOUT",
  "UND_ERR_SOCKET",
]);

export function isTransientNetworkError(error) {
  if (!error) return false;
  const code = error.code || "";
  if (TRANSIENT_ERROR_CODES.has(code)) return true;

  const msg = String(error.message || "").toLowerCase();
  return (
    msg.includes("fetch failed") ||
    msg.includes("socket hang up") ||
    msg.includes("dns-lookup-failed") ||
    msg.includes("eai_again") ||
    msg.includes("network timeout")
  );
}

export async function withExponentialBackoff(
  operation,
  {
    maxRetries = 2,
    initialDelayMs = 200,
    backoffFactor = 2,
    isRetryable = isTransientNetworkError,
    sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
  } = {},
) {
  let attempt = 0;

  while (true) {
    try {
      return await operation(attempt);
    } catch (error) {
      if (attempt >= maxRetries || !isRetryable(error)) {
        throw error;
      }

      const jitter = Math.floor(Math.random() * 50);
      const delay = initialDelayMs * Math.pow(backoffFactor, attempt) + jitter;
      await sleep(delay);
      attempt++;
    }
  }
}
