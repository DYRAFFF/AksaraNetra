# AksaraNetra

Platform audit aksesibilitas web terstandarisasi untuk regulasi Indonesia (UU No. 8/2016, Permen Kominfo No. 8/2021) dan kepatuhan global **WCAG 2.1 Level AA**.

Proyek ini menggunakan arsitektur monorepo terpadu:
- **Frontend**: Next.js 16 (App Router), React 19, Tailwind CSS, Lucide React, Framer Motion.
- **Backend (Terbaru)**: PHP 8.2+ / Laravel 11 (API-only) dengan database SQLite (WAL mode) & background queue worker.
- **Engine Audit**: Node.js 22+ & Playwright Chromium headless untuk inspeksi DOM nyata, rendering visual, dan komputasi kontras warna.

---

## 🏛️ Arsitektur Sistem

```text
 +--------------------------------------------------------------------------+
 |                       Next.js Frontend (Port 3000)                       |
 +--------------------------------------------------------------------------+
             |                                              ^
             | POST /audits (Mulai Audit)                   | Polling status &
             | GET  /audits/cache (Cek Cache)               | Ambil hasil
             v                                              |
 +--------------------------------------------------------------------------+
 |                     PHP Backend / Laravel (Port 8787)                    |
 | - RESTful API (CORS, Rate Limiting, Validasi Request)                    |
 | - SQLite DB (/data/jobs.sqlite dengan Write-Ahead Logging)               |
 | - Token HMAC-SHA256 & Proteksi Akses Artefak                             |
 +--------------------------------------------------------------------------+
             |                                              ^
             | Antrean Tugas (Queue Job)                    | Update progress &
             v                                              | Simpan artefak
 +--------------------------------------------------------------------------+
 |                   Audit Worker (php artisan audit:work)                  |
 | - Memproses antrean satu per satu secara sekuensial (Concurrency: 1)     |
 | - Manajemen timeout, recovery saat crash, dan pembersihan artefak        |
 +--------------------------------------------------------------------------+
             |
             | Memanggil via CLI Bridge (bridge/audit-cli.mjs)
             v
 +--------------------------------------------------------------------------+
 |                      Engine Audit (engine/src)                           |
 | - Playwright + Headless Chromium                                         |
 | - Penilaian WCAG 2.1 AA, Reader View, Screenshot, & Report Renderer      |
 +--------------------------------------------------------------------------+
```

---

## 📋 Prasyarat Sistem (Prerequisites)

Sebelum menjalankan proyek, pastikan perangkat Anda sudah terinstall:

1. **Node.js**: Versi `20.x` atau `22.x+` (rekomendasi `v22+` / `v24+`) & **npm**.
2. **PHP**: Versi `8.2+` atau `8.3+` dengan ekstensi aktif:
   - `pdo_sqlite`
   - `sqlite3`
   - `mbstring`
   - `openssl`
   - `curl`
3. **Composer**: Versi `2.x+`
4. *(Opsional)* **Docker & Docker Compose**: Jika ingin menjalankan backend menggunakan container.

---

## 🚀 Panduan Setup Awal (Installation)

Lakukan langkah instalasi berikut secara berurutan:

### 1. Setup Engine & Browser Chromium
Audit membutuhkan Chromium headless dari Playwright:
```bash
cd engine
npm install
npx playwright install chromium
cd ..
```

### 2. Setup Backend PHP
Buka folder `php-backend`, pasang pustaka PHP, siapkan environment, dan jalankan migrasi database SQLite:
```bash
cd php-backend
composer install
cp .env.example .env
php artisan migrate
cd ..
```
*(Di Windows PowerShell, jika `cp` tidak tersedia gunakan `copy .env.example .env`)*.

### 3. Setup Frontend Next.js
Pasang dependensi frontend di root project dan siapkan file konfigurasi `.env.local`:
```bash
npm install
```
Pastikan file `.env.local` di root proyek memiliki konfigurasi berikut:
```env
NEXT_PUBLIC_AUDIT_API_URL=http://localhost:8787
NEXT_PUBLIC_BACKEND_URL=http://localhost:8787
```

---

## 💻 Cara Menjalankan Aplikasi

AksaraNetra membutuhkan **3 layanan aktif**: Frontend, Backend HTTP API, dan Backend Audit Worker.

### Cara 1: Menjalankan di Terminal Lokal (Rekomendasi Dev)

Buka **3 tab/jendela terminal**:

#### Terminal 1 — Backend HTTP API Server
```bash
cd php-backend
php artisan serve --port=8787
```
> API aktif di: `http://127.0.0.1:8787`

#### Terminal 2 — Backend Audit Worker
```bash
cd php-backend
php artisan audit:work
```
> **Penting**: Worker ini bertugas mengeksekusi antrean audit dan Playwright browser saat user meminta audit di frontend.

#### Terminal 3 — Frontend Next.js
```bash
npm run dev
```
> Web UI aktif di: `http://localhost:3000`  
> Akses halaman audit di: `http://localhost:3000/periksa`

---

### Cara 2: Menjalankan Backend via Docker

Jika Anda memiliki Docker Desktop, backend API dan worker dapat dijalankan bersamaan dengan satu perintah:

```bash
cd php-backend
docker compose up --build -d
```
Lalu jalankan frontend di terminal lain:
```bash
npm run dev
```

Untuk menghentikan container Docker:
```bash
cd php-backend
docker compose down
```

---

## 🔍 Memastikan Backend Berjalan Normal

Uji koneksi backend dengan memanggil endpoint health check:

```bash
# Menggunakan curl di terminal
curl http://localhost:8787/health
```
Atau buka `http://localhost:8787/health` di browser. Respons yang diharapkan:
```json
{
  "status": "ok"
}
```

---

## ⚙️ Variabel Lingkungan (Environment Variables)

### Frontend (`.env.local` di root)
| Variabel | Default | Keterangan |
|---|---|---|
| `NEXT_PUBLIC_AUDIT_API_URL` | `http://localhost:8787` | URL endpoint backend PHP yang dipanggil browser |
| `NEXT_PUBLIC_BACKEND_URL` | `http://localhost:8787` | URL backend fallback untuk kompatibilitas tautan |

### Backend (`php-backend/.env`)
| Variabel | Default | Keterangan |
|---|---|---|
| `PORT` | `8787` | Port server HTTP backend |
| `FRONTEND_ORIGINS` | `http://localhost:3000` | Domain asal yang diizinkan CORS |
| `BACKEND_SECRET` | *(string rahasia)* | Kunci HMAC SHA-256 untuk token audit |
| `BACKEND_DATA_DIR` | `./data` | Lokasi database SQLite & artefak hasil audit |
| `AUDIT_RATE_LIMIT` | `10` | Batas maksimum audit per IP per jam |
| `AUDIT_TIMEOUT_MS` | `180000` | Batas waktu eksekusi Playwright (3 menit) |
| `CHROMIUM_LOW_RESOURCE_MODE` | `0` | Nilai `1` untuk hemat RAM (mematikan GPU overhead) |

---

## 🧪 Pengujian & Quality Checks

Untuk memverifikasi keutuhan kode dan fungsionalitas sistem:

```bash
# 1. Jalankan unit & feature test Backend PHP (49 tests, 100% passing)
cd php-backend
php vendor/bin/phpunit
cd ..

# 2. Uji alur end-to-end frontend ke backend PHP
node php-backend/scripts/test-frontend-flow.mjs

# 3. Uji paritas 42 skenario backend Node vs PHP
node php-backend/scripts/parity-check.mjs

# 4. Uji kontrak frontend
npm run test:frontend
```

---

## 📁 Struktur Direktori Utama

```text
├── src/                  # Kode sumber Frontend Next.js 16 (App Router, UI komponen)
├── php-backend/          # Backend Laravel 11 API-only
│   ├── app/              # Controller, Middleware CORS, Services, Artisan Commands
│   ├── bridge/           # Node CLI bridge penghubung PHP ke Engine Playwright
│   ├── docker/           # Konfigurasi Nginx, PHP-FPM, Dockerfile, Supervisord
│   ├── routes/api.php    # Rute endpoint RESTful API
│   └── tests/            # Test suite PHPUnit
├── engine/               # Playwright Chromium headless & logic audit aksesibilitas
├── public/               # Asset statis frontend & sample data audit
├── tests/                # Contract tests frontend
├── .env.local            # Konfigurasi environment frontend
└── README.md             # Dokumentasi utama proyek
```

---

## 💡 Masalah Umum (Troubleshooting)

1. **Chromium Executable Not Found**  
   *Penyebab:* Browser Chromium Playwright belum terpasang.  
   *Solusi:* Jalankan `cd engine && npx playwright install chromium`.

2. **Audit Berstatus "Sedang Mengantre" Terus Menerus**  
   *Penyebab:* Worker belum dinyalakan.  
   *Solusi:* Pastikan `php artisan audit:work` berjalan di terminal terpisah.

3. **CORS Blocked saat Frontend Request ke Backend**  
   *Penyebab:* URL frontend berbeda dengan `FRONTEND_ORIGINS`.  
   *Solusi:* Periksa file `php-backend/.env` dan pastikan `FRONTEND_ORIGINS=http://localhost:3000` (atau tambahkan domain Anda dipisahkan koma).

---

## 📄 Lisensi

Proyek ini dilindungi lisensi MIT. Silakan lihat berkas [LICENSE](./LICENSE) untuk informasi lebih lanjut.
