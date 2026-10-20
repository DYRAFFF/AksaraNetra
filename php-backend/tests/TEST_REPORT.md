# Laporan Pengujian AksaraNetra PHP Backend

Dokumen ini menjelaskan hasil pengujian menyeluruh pada backend baru AksaraNetra berbasis PHP/Laravel. Bahasa yang digunakan dibuat sederhana dan mudah dipahami oleh siapa saja, termasuk mereka yang bukan programmer. Setiap istilah teknis diberikan penjelasan ringkas.

---

## 1. Ringkasan Eksekutif & Angka Pengujian

Pengujian dilakukan untuk membuktikan bahwa backend PHP dapat menggantikan backend Node.js lama tanpa kendala (*drop-in replacement*), mampu melayani pengguna asli secara andal, tangguh menangani kegagalan, dan aman dari berbagai upaya peretasan.

### Perbandingan Angka Sebelum dan Sesudah

| Indikator | Sebelum Peningkatan | Sesudah Peningkatan | Keterangan |
| :--- | :---: | :---: | :--- |
| **Jumlah Tes Otomatis** | 49 tes | **178 tes** | Bertambah **+129 tes** baru |
| **Jumlah Pengecekan (*Assertions*)** | 320 pengecekan | **933 pengecekan** | Bertambah **+613 pengecekan** |
| **Cakupan Baris Kode (*Line Coverage*)** | ~68% | **79.47%** (863/1086 baris) | Mencakup >90% pada modul inti |
| **Waktu Eksekusi Penuh** | ~9.3 detik | **~23.1 detik** | Menguji 178 skenario secara komprehensif |
| **Stabilitas Eksekusi** | Stabil | **100% Lulus (Green)** | Lulus 3x berurutan dan 1x acak (*random order*) |
| **Mutation Testing (Uji Ketahanan)** | Belum ada | **10 dari 10 tertangkap (100%)** | Seluruh kerusakan kode buatan berhasil terdeteksi |

---

## 2. Cakupan Baris Kode per Komponen Utama

Pengukuran cakupan kode (*code coverage*) dilakukan menggunakan ekstensi resmi PHP Xdebug 3.5:

- **Modul Penyimpanan Data (`JobStore`)**: **95.54%** — Menjamin transaksi database SQLite, antrean audit, status pengerjaan, dan pencatatan error berfungsi sempurna.
- **Pembuat Laporan Aksesibilitas (`ReportRenderer`)**: **96.88%** — Menjamin laporan HTML identik byte-per-byte dengan Node dan kebal terhadap serangan injeksi script (*XSS*).
- **Layanan Keamanan & Kriptografi (`SecurityService`)**: **94.12%** — Menjamin pembuatan token acak, validasi tanda tangan HMAC, perbandingan waktu konstan (*constant-time*), dan deteksi IP aman.
- **Konfigurasi Sistem (`AksaraConfig`)**: **93.18%** — Memastikan seluruh variabel lingkungan (*environment variables*) divalidasi dengan ketat saat aplikasi menyala.
- **Pintu Akses API (*Controllers*)**:
  - `HealthController` (Cek kesehatan): **100.00%**
  - `AuditCacheController` (Cek riwayat hasil audit): **100.00%**
  - `AuditResultController` (Pengambilan hasil temuan audit): **100.00%**
  - `AuditController` (Pendaftaran audit baru & pembatalan): **97.14%**
  - `AuditArtifactController` (Pengunduhan dokumen/foto): **88.89%**
- **Lapisan Pengaman API (*Middleware*)**:
  - `AksaraCorsMiddleware` (Izin domain frontend): **100.00%**
  - `AksaraResponseHeadersMiddleware` (Header keamanan HTTP): **90.91%**
  - `EnforceJsonRequestMiddleware` (Pemeriksaan format JSON): **78.95%**
- **Pekerja Antrean & Auditor**:
  - `AuditCleanupCommand` (Pembersih audit kedaluwarsa): **100.00%**
  - `Auditor` (Pengatur alur audit & penyimpanan berkas): **89.06%**
  - `AuditWorker` (Pekerja latar belakang satu per satu): **86.02%**
  - *Catatan `BridgeClient` (14.02%)*: Modul ini merupakan jembatan yang memanggil browser Chromium melalui Node.js. Sesuai batasan pengujian (*deterministic without external network/Chromium*), pemanggilan browser disimulasikan menggunakan pemeran tiruan (*fake auditor*), sehingga proses eksekusi Chromium asli tidak dijalankan saat tes unit.

---

## 3. Apa Saja yang Diuji?

Pengujian dibagi menjadi 3 kelompok besar use case:

### A. Skenario Pengguna Nyata (Prefix `UC-USR`, 10 Tes)
Menguji cerita utuh dari sudut pandang pengguna yang mengakses website AksaraNetra:
1. **Alur Normal Lengkap (*Happy Path*)**: Pengguna memasukkan URL situs, status awal "menunggu antrean" (*queued*), lalu "sedang dikerjakan" (*running*) dengan persentase kemajuan bertahap, hingga "selesai" (*completed*). Semua dokumen hasil (halaman bacaan, halaman perbaikan, screenshot, dan laporan) berhasil diunduh.
2. **Pemanfaatan Hasil Cek Sebelumnya (*Cache Flow*)**: Jika situs yang sama sudah pernah diperiksa dan pengguna memilih opsi gunakan ulang (*reuseExisting: true*), sistem langsung mengembalikan hasil dalam hitungan milidetik tanpa perlu mengulang pemindaian browser. Jika pengguna memilih audit baru (*reuseExisting: false*), sistem membuat audit terpisah.
3. **Pembatalan Permintaan (*Cancel Flow*)**: Pengguna membatalkan audit saat masih mengantre, maupun saat sedang berjalan. Pekerja latar belakang segera menghentikan proses dan menghapus berkas sementara dari disk.
4. **Antrean Pengguna Bersamaan (*Queue Concurrency*)**: Ketika dua pengguna mendaftar di detik yang sama, sistem memastikan hanya 1 audit yang berjalan pada satu waktu (agar komputer server tidak kehabisan memori), pengguna kedua diberi info nomor urut antrean yang tepat (*queuePosition: 1*), dan keduanya diselesaikan bergantian sesuai urutan.
5. **Percobaan Ulang Otomatis (*Retry Flow*)**: Jika browser mengalami kendala sementara (misal browser macet sesaat), sistem mencoba ulang 1 kali secara otomatis. Jika kegagalan bersifat permanen (misal situs web tidak dapat diakses), status berubah menjadi "gagal" (*failed*) disertai pesan bahasa Indonesia yang ramah.
6. **Kedaluwarsa Berkas (*Expiry Flow*)**: Sesuai batas waktu penyimpanan data (default 24 jam), hasil dan berkas audit yang sudah melewati masa berlaku akan mengembalikan status kedaluwarsa (HTTP 410 Gone). Perintah pembersih otomatis (*artisan audit:cleanup*) menghapus data di database dan folder berkas di disk.
7. **Pemulihan Pascamacet (*Crash Recovery*)**: Jika server tiba-tiba mati saat sedang memeriksa sebuah situs, begitu server menyala kembali sistem mendeteksi audit yang tertinggal dan memulihkan statusnya agar tidak macet selamanya.
8. **Kesesuaian dengan Antarmuka Frontend (*Frontend Compatibility*)**: Menguji urutan permintaan yang dikirimkan persis oleh modul `src/lib/audit-api.ts` pada aplikasi Next.js AksaraNetra, memastikan semua nama kolom, format angka, dan tautan sesuai tanpa menimbulkan galat di layar pengguna.

### B. Kasus Kegagalan & Masukan Tidak Normal (Prefix `UC-FAIL`, 52 Tes)
Menguji ketahanan sistem ketika menerima data yang rusak, salah, atau tidak sesuai aturan:
- **Input Rusak pada Pendaftaran Audit**: URL kosong, URL berupa angka/array/boolean, URL sangat panjang (>2048 karakter), format data bukan JSON, body kosong, ukuran berkas melewati batas maksimal, penambahan kolom tak dikenal, dan opsi penggunaan cache non-boolean. Semua ditolak dengan pesan galat bahasa Indonesia yang jelas.
- **Kesalahan Kunci Akses (*Authentication*)**: Mengakses data tanpa token, menggunakan token acak yang salah, menggunakan token milik audit orang lain, menukar token akses dengan token berbagi tautan (*share token*), serta mengirim token lewat header vs URL.
- **Permintaan yang Belum Waktunya**: Meminta hasil audit saat audit masih mengantre atau masih berjalan; sistem merespons dengan HTTP 409 (*not ready*).
- **Pembatalan Ganda**: Membatalkan audit yang sudah selesai, sudah gagal, atau membatalkan 2 kali berturut-turut.
- **Pembatasan Laju Akses (*Rate Limiting*)**: Memastikan pengguna dibatasi maksimal 10 audit per jam. Permintaan ke-11 ditolak dengan HTTP 429 dan header `Retry-After: 3600`. Pengguna dari IP berbeda dihitung terpisah, dan pemalsuan header IP (*X-Forwarded-For spoofing*) dicegah saat proxy tidak dipercaya.
- **Gangguan Infrastruktur**: Berkas artefak hilang dari disk, berkas laporan ringkasan rusak/terpotong, jembatan engine mengeluarkan teks sampah, proses browser timeout, dan kegagalan pembuatan PDF secara otomatis ditangani dengan pesan fallback tanpa membuat sistem crash.

### C. Uji Ketahanan Keamanan & Serangan (Prefix `UC-SEC`, 62 Tes)
Menguji sistem dengan metode serangan keamanan siber:
- **Pencegahan Akses Jaringan Lokal (*SSRF & URL Policy*)**: Penyerang mencoba memasukkan alamat IP lokal (`127.0.0.1`, `localhost`, `0.0.0.0`), alamat metadata cloud (`169.254.169.254`), variasi desimal/heksadesimal IP (`http://2130706433/`, `http://0x7f000001/`), protokol terlarang (`file://`, `ftp://`, `javascript:`, `data:`), URL dengan sandi (`user:pass@`), dan port terlarang. Semua berhasil diblokir sebelum browser membuka tautan.
- **Keamanan Token & Kerahasiaan Kunci**: Token dibuat secara acak kriptografis (panjang 32 karakter URL-safe), tidak pernah dicetak dalam log sistem, diverifikasi dengan fungsi tahan serangan waktu (*timing-safe* `hash_equals`), dan upaya tebak token menghasilkan jawaban 404 yang seragam agar penyerang tidak bisa menebak apakah ID audit ada atau tidak.
- **Pencegahan Pembacaan Berkas Ilegal (*Path Traversal*)**: Penyerang mencoba membaca berkas sistem atau database menggunakan karakter `..` atau `%2e%2e` pada nama dokumen maupun ID audit. Sistem memastikan hanya berkas resmi di dalam folder audit yang bersangkutan yang dapat dibaca.
- **Kebijakan Keamanan Konten (*Content Security Policy - CSP*)**: Seluruh berkas artefak dikirim dengan header CSP ketat (`default-src 'none'`) dan `X-Content-Type-Options: nosniff` sehingga script berbahaya yang disisipkan di dalam HTML audit tidak dapat dieksekusi di browser.
- **Keamanan Lintas Domain (*CORS*)**: Hanya domain frontend resmi yang diizinkan mengambil data. Domain luar, domain tiruan phishing, dan origin palsu langsung ditolak dengan status HTTP 403 (*origin-not-allowed*).
- **Pencegahan Injeksi Kode (*SQL Injection, CRLF Injection, XSS*)**: Upaya injeksi SQL pada parameter audit diproteksi oleh query terparameter (*parameterized query* PDO); karakter pemisah baris (CR/LF) pada URL tidak bisa menembus header respons; dan tag HTML/script pada judul halaman dan nama rule aksesibilitas otomatis disanitasi (*escaped*) pada laporan HTML.
- **Pencegahan Kebocoran Informasi (*Information Leakage*)**: Pesan kesalahan sistem tidak pernah membocorkan *stack trace*, path direktori server, kueri SQL, atau kunci rahasia (*BACKEND_SECRET*). Mode debug (`APP_DEBUG=false`) dipastikan sebagai standar pada kontainer produksi.

---

## 4. Hasil Uji Ketahanan Kode (*Mutation Sanity Check*)

Untuk membuktikan bahwa pengujian benar-benar peka terhadap kerusakan dan bukan sekadar tes formalitas, kami sengaja merusak 10 baris kode penting secara bergantian (*code mutation*) dan memeriksa apakah rangkaian tes berhasil mendeteksi dan menggagalkannya:

| No | Modifikasi yang Sengaja Dirusak (*Mutation*) | Modul / Lokasi | Hasil Pengujian | Tes yang Berhasil Menangkap (*Killing Test*) |
|:--:| :--- | :--- | :---: | :--- |
| **1** | Menghapus verifikasi token akses pada pengambilan audit | `AuditController.php:127` | **GAGAL (Tertangkap)** | `SecurityScenarioTest::test_UC_SEC_12` (mendeteksi akses ilegal tanpa token) |
| **2** | Mematikan pengecekan batas laju permintaan (*rate limit*) | `AuditController.php:62` | **GAGAL (Tertangkap)** | `FailureScenarioTest::test_UC_FAIL_19` (menerima HTTP 202 saat seharusnya 429) |
| **3** | Mengganti `hash_equals` menjadi perbandingan longgar `==` | `SecurityService.php:76` | **GAGAL (Tertangkap)** | `SecurityScenarioTest::test_UC_SEC_11` (mendeteksi tabrakan notasi eksponensial `0e...`) |
| **4** | Mematikan validasi daftar putih (*whitelist*) berkas dokumen | `AuditArtifactController.php:58` | **GAGAL (Tertangkap)** | `FailureScenarioTest::test_UC_FAIL_16` (5 pengujian gagal mendeteksi artefak palsu) |
| **5** | Menghapus sanitasi karakter HTML (*escaping*) pada laporan | `ReportRenderer.php:27` | **GAGAL (Tertangkap)** | `SecurityScenarioTest::test_UC_SEC_19` (mendeteksi tag `<script>` tidak disanitasi) |
| **6** | Menghapus pemeriksaan domain asal (*CORS origin check*) | `AksaraCorsMiddleware.php:24` | **GAGAL (Tertangkap)** | `SecurityScenarioTest::test_UC_SEC_15` (5 domain asing diterima padahal wajib ditolak) |
| **7** | Mematikan mekanisme coba ulang (*retry*) pada kegagalan sementara | `AuditWorker.php:166` | **GAGAL (Tertangkap)** | `UserScenarioTest::test_UC_USR_06` (status langsung 'failed' tanpa 'queued' kedua) |
| **8** | Menghapus pembersihan berkas saat audit dibatalkan | `AuditWorker.php:158` | **GAGAL (Tertangkap)** | `WorkerTest::test_cancels_a_running_audit` & `UC-USR-04` (folder berkas masih tertinggal) |
| **9** | Menghilangkan kewajiban header `Content-Type: application/json` | `EnforceJsonRequestMiddleware.php:24` | **GAGAL (Tertangkap)** | `FailureScenarioTest::test_UC_FAIL_06` (4 tipe media asing diterima padahal wajib ditolak) |
| **10** | Menghilangkan pengecekan masa berlaku (*expiry check*) berkas | `AuditArtifactController.php:50` | **GAGAL (Tertangkap)** | `UserScenarioTest::test_UC_USR_08` (menerima HTTP 200 saat dokumen sudah kedaluwarsa) |

**Kesimpulan Mutation Testing**: 10 dari 10 kerusakan buatan (100%) langsung tertangkap oleh tes otomatis yang relevan. Seluruh kode yang dirusak telah dikembalikan (*reverted*) ke kondisi semula sehingga sistem kembali bersih dan 100% lulus.

---

## 5. Temuan & Catatan Kesesuaian (*Node Compatibility*)

Selama proses pengujian mendalam, ditemukan beberapa perilaku bawaan dari sistem Node.js asli yang dipertahankan sepenuhnya di PHP untuk menjamin keselarasan (*drop-in compatibility*):

1. **Penguraian IP Non-Standar pada URL Policy**:
   - Node.js menggunakan standar WHATWG URL parser yang secara otomatis mengubah alamat IP format bilangan desimal (`http://2130706433/`) atau heksadesimal (`http://0x7f000001/`) menjadi format kanonikal `127.0.0.1`.
   - Akibatnya, `url-policy.mjs` mengidentifikasinya sebagai literal IP terlarang (`ip-literal-not-allowed`) dan bukan hostname terlarang.
   - Hostname metadata cloud seperti `metadata` dan `instance-data` secara spesifik didaftarkan dalam daftar blokir host lokal (`local-host-name`).
   - Backend PHP memanggil langsung jembatan validasi Node (`bridge/audit-cli.mjs check-url`), sehingga keputusan penolakan URL 100% identik tanpa perbedaan sedikit pun.
2. **Normalisasi Jalur URL pada ID Audit**:
   - Ketika penyerang mencoba menyelundupkan `..` atau `%2F` pada URL seperti `/audits/aud_../../etc/passwd`, router web (baik Node HTTP maupun Laravel Routing) menormalisasi segmen URL sebelum mencocokkan rute. Karena tidak cocok dengan pola tunggal `/audits/{id}`, sistem merespons dengan HTTP 404 standar `Endpoint tidak ditemukan.`. Perilaku ini aman dan konsisten di kedua backend.

---

## 6. Apa yang Masih Belum Diuji & Mengapa?

Sesuai dengan batasan kerja dan prinsip pengujian perangkat lunak yang cepat dan deterministik (*tidak bergantung pada faktor luar*):
1. **Eksekusi Browser Chromium Sungguhan dalam Rangkaian Tes Otomatis**:
   - Rangkaian pengujian unit dan fitur menggunakan *Fake Auditor* dan pemanggilan terkontrol. Pengujian tidak menyalakan browser Chromium asli dalam setiap tes agar pengujian dapat selesai cepat (~23 detik untuk 178 tes), hemat memori, dan tidak gagal karena keterbatasan hardware atau ketiadaan layar tampilan (*headless display*).
   - *Verifikasi Chromium Asli*: Tetap dilakukan secara terpisah melalui skrip verifikasi integrasi live (`node php-backend/scripts/test-frontend-flow.mjs`).
2. **Akses Internet / Jaringan Luar Nyata**:
   - Seluruh pengujian sengaja dibuat terisolasi dari internet (*offline*) agar tidak bergantung pada koneksi internet, tidak membebani situs pihak ketiga, dan deterministik (hasilnya selalu sama kapan pun dan di mana pun dijalankan).
