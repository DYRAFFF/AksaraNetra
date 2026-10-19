import http from "node:http";

/**
 * Harness mock HTTP server lokal untuk pengujian regresi audit luring (offline).
 *
 * Memungkinkan pengujian menyeluruh terhadap alur auditUrl, penarikan robots.txt,
 * pengalihan 1-hop, dan respon lambat tanpa ketergantungan koneksi internet.
 */
export async function createMockServer(routes = {}) {
  const activeSockets = new Set();

  const defaultRoutes = {
    "/robots.txt": (req, res) => {
      res.writeHead(200, { "Content-Type": "text/plain; charset=utf-8" });
      res.end("User-agent: *\nAllow: /\n");
    },
    "/": (req, res) => {
      res.writeHead(200, { "Content-Type": "text/html; charset=utf-8" });
      res.end(`<!doctype html>
<html lang="id">
  <head>
    <meta charset="utf-8">
    <title>Halaman Simulasi Audit Mock</title>
  </head>
  <body>
    <main id="konten-utama">
      <h1>Portal Simulasi Layanan Publik</h1>
      <p>Selamat datang di portal simulasi pengujian otomatis.</p>
      <button class="fa fa-chevron-left"></button>
      <a href="https://example.com/info" class="tautan-info">Informasi Publik</a>
    </main>
  </body>
</html>`);
    },
  };

  const effectiveRoutes = { ...defaultRoutes, ...routes };

  const server = http.createServer((req, res) => {
    const urlPath = req.url ? req.url.split("?")[0] : "/";
    const handler = effectiveRoutes[urlPath] || effectiveRoutes["*"];

    if (typeof handler === "function") {
      handler(req, res);
    } else if (typeof handler === "string") {
      res.writeHead(200, { "Content-Type": "text/html; charset=utf-8" });
      res.end(handler);
    } else if (handler && typeof handler === "object") {
      const status = handler.status || 200;
      const headers = handler.headers || { "Content-Type": "text/html; charset=utf-8" };
      const body = handler.body || "";
      res.writeHead(status, headers);
      res.end(body);
    } else {
      res.writeHead(404, { "Content-Type": "text/plain" });
      res.end("Not Found");
    }
  });

  server.on("connection", (socket) => {
    activeSockets.add(socket);
    socket.once("close", () => activeSockets.delete(socket));
  });

  await new Promise((resolve, reject) => {
    server.listen(0, "127.0.0.1", (err) => {
      if (err) reject(err);
      else resolve();
    });
  });

  const address = server.address();
  const port = address.port;
  const baseUrl = `http://127.0.0.1:${port}`;

  const close = async () => {
    for (const socket of activeSockets) {
      try {
        socket.destroy();
      } catch {}
    }
    activeSockets.clear();
    await new Promise((resolve) => server.close(resolve));
  };

  return {
    url: baseUrl,
    port,
    server,
    close,
  };
}
