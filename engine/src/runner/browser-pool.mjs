/**
 * Pengelolaan Pool Browser dan Isolasi Konteks (Browser Pooling & Context Lifecycle).
 *
 * Mengelola satu instance browser Chromium bersama (shared browser instance)
 * untuk menghilangkan overhead spawn/kill proses Chromium di setiap audit.
 *
 * Setiap tugas audit tetap mendapatkan context dan page terisolasi 100%:
 * `await browser.newContext(contextOptions)` yang selalu ditutup di blok `finally`.
 *
 * Dilengkapi dengan:
 * 1. Siklus daur ulang mandiri (recycle circuit) setelah maxUses (default 50).
 * 2. Penutupan idle otomatis setelah idleTimeoutMs (default 30.000ms).
 * 3. Deteksi diskoneksi dan auto-recovery otomatis jika Chromium crash.
 */

import { chromium } from "playwright";

export const DEFAULT_POOL_CONFIG = Object.freeze({
  maxUses: 50,
  idleTimeoutMs: 30000,
});

let sharedBrowser = null;
let launchingPromise = null;
let activeContexts = 0;
let usageCount = 0;
let idleTimer = null;

export function getPoolStats() {
  return {
    hasBrowser: sharedBrowser !== null,
    isConnected: sharedBrowser ? sharedBrowser.isConnected() : false,
    activeContexts,
    usageCount,
    hasIdleTimer: idleTimer !== null,
  };
}

function clearIdleTimer() {
  if (idleTimer) {
    clearTimeout(idleTimer);
    idleTimer = null;
  }
}

function scheduleIdleClose(idleTimeoutMs) {
  clearIdleTimer();
  if (!idleTimeoutMs || idleTimeoutMs <= 0) return;

  idleTimer = setTimeout(async () => {
    idleTimer = null;
    if (activeContexts === 0 && sharedBrowser) {
      await closeSharedBrowser();
    }
  }, idleTimeoutMs);

  if (typeof idleTimer?.unref === "function") {
    idleTimer.unref();
  }
}

export async function getSharedBrowser({
  headless = true,
  launchArgs = [],
  chromiumImpl = chromium,
} = {}) {
  if (sharedBrowser && sharedBrowser.isConnected()) {
    return sharedBrowser;
  }

  if (launchingPromise) {
    return launchingPromise;
  }

  launchingPromise = (async () => {
    try {
      if (sharedBrowser) {
        await sharedBrowser.close().catch(() => {});
        sharedBrowser = null;
      }

      const browser = await chromiumImpl.launch({
        headless,
        args: launchArgs,
      });

      browser.on("disconnected", () => {
        if (sharedBrowser === browser) {
          sharedBrowser = null;
          usageCount = 0;
        }
      });

      sharedBrowser = browser;
      usageCount = 0;
      return browser;
    } finally {
      launchingPromise = null;
    }
  })();

  return launchingPromise;
}

export async function closeSharedBrowser() {
  clearIdleTimer();
  const browser = sharedBrowser;
  sharedBrowser = null;
  launchingPromise = null;
  usageCount = 0;

  if (browser) {
    await browser.close().catch(() => {});
  }
}

export async function withPooledBrowser(
  task,
  {
    headless = true,
    signal = null,
    launchArgs = [],
    contextOptions = {},
    maxUses = DEFAULT_POOL_CONFIG.maxUses,
    idleTimeoutMs = DEFAULT_POOL_CONFIG.idleTimeoutMs,
    chromiumImpl = chromium,
  } = {},
) {
  signal?.throwIfAborted();
  clearIdleTimer();

  // Daur ulang browser jika sudah mencapai batas maksimal penggunaan
  if (sharedBrowser && usageCount >= maxUses && activeContexts === 0) {
    await closeSharedBrowser();
  }

  const browser = await getSharedBrowser({
    headless,
    launchArgs,
    chromiumImpl,
  });

  usageCount++;
  activeContexts++;

  let context;
  try {
    context = await browser.newContext(contextOptions);
  } catch (err) {
    // Jika pembuatan context gagal (misal koneksi browser putus mendadak),
    // daur ulang browser dan coba sekali lagi
    activeContexts = Math.max(0, activeContexts - 1);
    await closeSharedBrowser();
    const freshBrowser = await getSharedBrowser({
      headless,
      launchArgs,
      chromiumImpl,
    });
    activeContexts++;
    usageCount++;
    context = await freshBrowser.newContext(contextOptions);
  }

  const page = await context.newPage();
  const abort = () => context.close().catch(() => {});
  signal?.addEventListener("abort", abort, { once: true });

  try {
    signal?.throwIfAborted();
    return await task({ page, context, browser: sharedBrowser || browser });
  } finally {
    signal?.removeEventListener("abort", abort);
    await context.close().catch(() => {});
    activeContexts = Math.max(0, activeContexts - 1);

    if (activeContexts === 0) {
      if (usageCount >= maxUses) {
        await closeSharedBrowser();
      } else {
        scheduleIdleClose(idleTimeoutMs);
      }
    }
  }
}
