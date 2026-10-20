# AksaraNetra PHP Backend: Use Case Coverage & Catalog

Dokumen ini mendokumentasikan pemetaan pengujian berbasis skenario penggunaan (*use cases*) pada AksaraNetra PHP Backend, mencakup skenario pengguna nyata (*user scenarios*), kasus kegagalan dan abnormalitas (*failure cases*), serta pengujian keamanan (*security & attack testing*).

---

## Bagian 1: Cakupan Pengujian Saat Ini (Current Coverage)

### Tabel Pengujian Awal (Baseline: 49 Tes)

| Komponen / Endpoint | Berkas Tes | Nama Tes | Deskripsi & Perilaku yang Diuji |
|---|---|---|---|
| `GET /health` | `ApiTest.php` | `test_health_endpoint` | Memastikan endpoint healthcheck mengembalikan status 200, status worker, konkurensi 1, dan header `no-store` serta `nosniff`. |
| `GET /health` (CORS) | `CorsTest.php` | `test_rejects_untrusted_browser_origins` | Origin peramban yang tidak terdaftar ditolak dengan status 403 `origin-not-allowed`. |
| `GET /health` (CORS) | `CorsTest.php` | `test_allows_trusted_browser_origins` | Origin terdaftar menerima header `access-control-allow-origin`, `credentials`, `methods`, dan `headers`. |
| `OPTIONS /health` (CORS) | `CorsTest.php` | `test_preflight_options_for_trusted_origin` | Preflight HTTP OPTIONS untuk origin terpercaya mengembalikan 204 tanpa body. |
| `OPTIONS /health` (CORS) | `CorsTest.php` | `test_preflight_options_for_untrusted_origin_returns_403` | Preflight HTTP OPTIONS untuk origin tidak terpercaya mengembalikan 403. |
| `GET /health` (CORS) | `CorsTest.php` | `test_requests_without_origin_are_allowed` | Permintaan non-browser (tanpa header Origin) diizinkan normal. |
| `GET /audits/cache` | `ApiTest.php` | `test_lets_user_explicitly_reuse_stored_result` | Memeriksa ketersediaan cache untuk URL yang telah selesai diaudit sebelumnya. |
| `POST /audits` | `ApiTest.php` | `test_creates_anonymous_job_and_hides_without_token` | Membuat pekerjaan audit baru, menerima token akses, memastikan job tersembunyi tanpa token. |
| `POST /audits` | `ApiTest.php` | `test_enforces_rate_limit_and_queued_cancellation` | Membatasi jumlah audit per jam dan mendukung pembatalan saat dalam antrean. |
| `POST /audits` | `ApiTest.php` | `test_json_body_validation_and_size_limits` | Validasi Content-Type (415), JSON rusak (400), dan ukuran body melebihi batas (413). |
| `POST /audits` | `ApiTest.php` | `test_lets_user_explicitly_reuse_stored_result` | Penggunaan `reuseExisting: true` menyalin artefak dan langsung mengembalikan status completed (200). |
| `GET /audits/{id}` | `ApiTest.php` | `test_creates_anonymous_job_and_hides_without_token` | Status pekerjaan dapat diakses via Bearer token atau query parameter `?accessToken=`. |
| `DELETE /audits/{id}` | `ApiTest.php` | `test_enforces_rate_limit_and_queued_cancellation` | Membatalkan pekerjaan yang masih dalam antrean (status berubah menjadi cancelled). |
| `GET /audits/{id}/result` | `ApiTest.php` | `test_returns_expiring_reader_and_pdf_links_only_after_completion` | Mengembalikan 409 `not-ready` sebelum selesai, dan mengembalikan tautan artefak ber-token setelah selesai. |
| `GET /audits/{id}/result` | `ApiTest.php` | `test_artifact_route_serves_report_pdf_and_reproduces_report_html_fallback_quirk` | Memastikan link reportPdf tetap mengarah ke `report.pdf` meskipun fallback hanya membuat `report.html`. |
| `GET /audits/{id}/artifacts/{artifact}` | `ApiTest.php` | `test_returns_expiring_reader_and_pdf_links_only_after_completion` | Mengunduh artefak HTML dan PDF dengan header keamanan dan token share valid. Menolak permintaan tanpa token atau token salah (404). |
| `GET /audits/{id}/artifacts/{artifact}` | `ApiTest.php` | `test_artifact_route_serves_report_pdf_and_reproduces_report_html_fallback_quirk` | Menguji pengembalian 404 ketika file PDF tidak ada atau route HTML diminta langsung. |
| `Endpoint Fallback` | `ApiTest.php` | `test_not_found_endpoint` | Memastikan rute tidak terdaftar menghasilkan JSON 404 `Endpoint tidak ditemukan.`. |
| `Auditor` | `AuditorTest.php` | `test_auditor_runs_full_lifecycle_and_generates_artifacts` | Menguji siklus penuh auditor menghasilkan laporan HTML, PDF, reader, dan snapshot. |
| `Auditor` | `AuditorTest.php` | `test_auditor_resumes_from_checkpoint_without_calling_bridge_audit` | Menguji pemulihan auditor dari checkpoint `snapshot.json` tanpa memanggil bridge audit ulang. |
| `Auditor` | `AuditorTest.php` | `test_auditor_handles_pdf_fallback_gracefully` | Menguji graceful fallback ketika rendering PDF gagal karena memori rendah. |
| `Auditor` | `AuditorTest.php` | `test_auditor_aborts_when_cancellation_signaled` | Menguji pembatalan auditor saat sinyal cancel diterima. |
| `BridgeClient` | `BridgeTest.php` | `test_check_url_allows_valid_public_url` | Memvalidasi URL publik standar `https://example.com/` disetujui. |
| `BridgeClient` | `BridgeTest.php` | `test_check_url_rejects_empty_url` | Menolak URL kosong dengan kode `empty-url`. |
| `BridgeClient` | `BridgeTest.php` | `test_check_url_rejects_ip_literal` | Menolak IP literal langsung seperti `127.0.0.1` dengan `ip-literal-not-allowed`. |
| `BridgeClient` | `BridgeTest.php` | `test_check_url_rejects_localhost` | Menolak nama hostname `localhost` dengan `local-host-name`. |
| `BridgeClient` | `BridgeTest.php` | `test_check_url_rejects_non_http_protocol` | Menolak protokol selain HTTP/HTTPS seperti `ftp://` dengan `protocol-not-allowed`. |
| `JobStore` | `StoreTest.php` | `test_persists_queue_state_and_recovers_interrupted_jobs_once` | Memastikan pekerjaan `running` dipulihkan ke antrean saat restart pertama, dan ditandai gagal saat restart kedua. |
| `JobStore` | `StoreTest.php` | `test_returns_expired_artifact_folders_before_deleting_rows` | Mengembalikan daftar folder artefak pekerjaan yang kedaluwarsa sebelum menghapus baris basis data. |
| `JobStore` | `StoreTest.php` | `test_recovers_interrupted_job_with_checkpoint_to_progress_90` | Pekerjaan terputus yang memiliki checkpoint dipulihkan ke status antrean dengan progres 90%. |
| `JobStore` | `StoreTest.php` | `test_complete_job_lifecycle_and_helpers` | Menguji seluruh siklus pekerjaan di database: hitung IP, posisi antrean, kenaikan progres monotonik, penyelesaian, pembatalan, dan pencarian cache. |
| `AuditWorker` | `WorkerTest.php` | `test_runs_only_one_audit_at_a_time` | Menjamin konkurensi tepat 1, tidak ada dua audit yang berjalan bersamaan. |
| `AuditWorker` | `WorkerTest.php` | `test_retries_one_transient_failure_and_then_succeeds` | Kegagalan sementara (`browser-failed`) dicoba ulang 1 kali sebelum berhasil. |
| `AuditWorker` | `WorkerTest.php` | `test_cancels_a_running_audit_and_removes_partial_artifacts` | Pembatalan audit yang sedang berjalan menghentikan proses dan menghapus direktori artefak parsial. |
| `AuditWorker` | `WorkerTest.php` | `test_fails_with_audit_timeout_and_removes_partial_artifacts` | Batas waktu audit (timeout) menandai pekerjaan gagal dengan `audit-timeout` dan membersihkan artefak. |
| `AuditWorker` | `WorkerTest.php` | `test_fails_permanently_on_second_transient_failure` | Kegagalan sementara yang terjadi berturut-turut dua kali menjadi kegagalan permanen. |
| `AuditWorker` | `WorkerTest.php` | `test_fails_immediately_on_non_transient_error` | Kesalahan non-transient (seperti `robots-disallowed`) langsung ditandai gagal tanpa retry. |
| `AksaraConfig` | `ConfigTest.php` | `test_default_config_matches_node_defaults` | Konfigurasi default PHP persis sama dengan default Node.js. |
| `AksaraConfig` | `ConfigTest.php` | `test_production_environment_sets_rate_limit_to_5` | Batas laju disetel ke 5 di lingkungan produksi. |
| `AksaraConfig` | `ConfigTest.php` | `test_production_rejects_secret_shorter_than_32_characters` | Menolak rahasia kurang dari 32 karakter di produksi dengan pesan bahasa Indonesia. |
| `AksaraConfig` | `ConfigTest.php` | `test_parses_comma_separated_origins` | Parsing string daftar origin CORS yang dipisahkan koma dan spasi. |
| `AksaraConfig` | `ConfigTest.php` | `test_validates_integer_ranges_with_indonesian_messages` | Memvalidasi batasan nilai angka konfigurasi dengan pesan kesalahan bahasa Indonesia. |
| `AksaraConfig` | `ConfigTest.php` | `test_trust_proxy_boolean_flag` | Menguji opsi boolean `TRUST_PROXY`. |
| `ReportRenderer` | `ReportRendererTest.php` | `test_esc_escapes_only_html_special_chars_matching_node` | Memastikan escaping karakter HTML `&<">` sama persis dengan Node (kutip tunggal tidak di-escape). |
| `ReportRenderer` | `ReportRendererTest.php` | `test_rows_renders_empty_notice_when_empty` | Menampilkan pemberitahuan kosong jika tidak ada temuan. |
| `ReportRenderer` | `ReportRendererTest.php` | `test_rows_renders_table_with_escaped_cells` | Menghasilkan tabel HTML dengan isi sel yang lolos sanitasi. |
| `ReportRenderer` | `ReportRendererTest.php` | `test_stringify_regression_matches_node_two_space_json` | Indentasi JSON blok regresi menggunakan 2 spasi per tingkat seperti Node. |
| `ReportRenderer` | `ReportRendererTest.php` | `test_byte_identical_parity_against_all_node_fixtures` | Kesamaan byte-for-byte laporan HTML terhadap 5 fixture dari Node.js. |
| `SecurityService` | `SecurityTest.php` | `test_known_answer_vectors_match_node` | Hasil digest HMAC-SHA256 dan deriveShareToken identik dengan vektor Node. |
| `SecurityService` | `SecurityTest.php` | `test_random_job_id_format` | Format ID acak diawali `aud_` dengan panjang 28 karakter base64url. |
| `SecurityService` | `SecurityTest.php` | `test_random_token_format` | Format token acak base64url tidak ber-padding. |
| `SecurityService` | `SecurityTest.php` | `test_safe_equal_hex` | Perbandingan heksadesimal waktu-konstan timing-safe. |
| `SecurityService` | `SecurityTest.php` | `test_client_ip_direct_and_trusted` | Penentuan IP klien langsung vs melalui proxy terpercaya (`X-Forwarded-For`). |
| `SecurityService` | `SecurityTest.php` | `test_bearer_token_extraction` | Ekstraksi token dari header Authorization Bearer dan query string `accessToken`. |

---

### Kesenjangan Pengujian (*Testing Gaps*) Teridentifikasi

Berdasarkan perbandingan menyeluruh terhadap perilaku backend Node.js (`backend/src/` dan `engine/src/fetcher/url-policy.mjs`), ditemukan kesenjangan cakupan yang belum diuji secara otomatis di sisi PHP:

1. **Skenario Pengguna Lengkap (*End-to-End User Story*)**:
   - Belum ada tes integrasi HTTP tunggal yang menjalankan cerita pengguna dari awal hingga akhir: membuat audit -> polling berkala dengan kenaikan status `queued` -> `running` dengan progres dan tahap -> `completed` -> mengambil hasil -> mengunduh keempat berkas artefak (`reader`, `patched`, `report.pdf`, `screenshot`).
2. **Alur Cache Lengkap (*Cache Lookup Matrix*)**:
   - `GET /audits/cache` belum diuji untuk URL tidak valid (mengembalikan 400 dengan pesan kesalahan sama seperti POST).
   - Pengujian `reuseExisting: false` memastikan audit baru benar-benar dijadwalkan ulang dan tidak mengambil hasil lama meskipun tersedia.
3. **Pembatalan Saat Audit Sedang Berjalan (*In-flight Cancellation*)**:
   - Belum ada pengujian HTTP DELETE yang membatalkan saat audit berstatus `running`, memastikan worker menangkap sinyal pembatalan, proses berhenti seketika, dan semua file sementara di direktori dibersihkan.
   - Pengujian pembatalan dua kali (*double cancel*) atau membatalkan pekerjaan yang sudah `completed` / `failed` / `cancelled`.
4. **Alur Antrean dan Posisi Antrean (*Queue Concurrency & Positions*)**:
   - Belum diuji dua pengguna mengirim permintaan bersamaan: verifikasi bahwa pekerjaan pertama berstatus `running` dengan `queuePosition: null`, dan pekerjaan kedua `queued` dengan `queuePosition: 1`, lalu keduanya selesai berurutan secara FIFO.
5. **Daur Hidup Kedaluwarsa dan Perintah Pembersihan Artisan**:
   - Belum diuji pengembalian HTTP 410 `Artefak sudah kedaluwarsa.` pada endpoint artefak setelah melewati batas retensi `RESULT_RETENTION_MS`.
   - Belum diuji eksekusi perintah Artisan `php artisan audit:cleanup` dalam menghapus baris DB dan menghapus folder fisik di disk.
6. **Pemulihan Crash Worker (*Worker Crash Recovery State Machine*)**:
   - Belum diuji skenario di mana worker mengalami crash saat pekerjaan sedang `running` dan bangkit kembali melalui Artisan `audit:work`: pemulihan tanpa checkpoint (kembali ke antrean progres 0%), pemulihan dengan checkpoint (kembali ke antrean progres 90%), dan pemulihan setelah gagal 2 kali (ditandai permanen gagal `server-restarted`).
7. **Kompatibilitas Kontrak Frontend (*Frontend Client Schema Compatibility*)**:
   - Belum ada pengujian yang mereplikasi secara persis seluruh panggilan fungsi dari `src/lib/audit-api.ts` dan memvalidasi setiap tipe data dari properti `AuditJob`, `Snapshot`, `AuditResult`, dan error representation.
8. **Matriks Validasi Input Abnormal (*Failure Cases*)**:
   - `POST /audits`: URL bukan string (angka, array, objek), string kosong, URL > 2048 karakter, field tambahan yang tidak dikenal, `reuseExisting` non-boolean.
   - Autentikasi: Share token dipakai di endpoint hasil/status (harus ditolak), Access token dipakai di endpoint artefak (harus ditolak), token milik job lain ditolak.
   - Kesalahan ID: Format ID aneh (sangat panjang, karakter Unicode, simbol SQL, karakter path).
   - Kesalahan Status: Meminta `/result` pada pekerjaan yang masih `queued`, `running`, `failed`, atau `cancelled` (mengembalikan 409 `not-ready`).
   - Rate limit boundary: Tepat di batas (berhasil), 1 di atas batas (429 dengan `Retry-After: 3600`), reset jendela waktu setelah 1 jam, pemisahan IP berbeda, dan pengujian header palsu `X-Forwarded-For` saat `TRUST_PROXY=0`.
   - Gangguan infrastruktur: File artefak hilang dari disk padahal status DB selesai (mengembalikan 404), checkpoint `snapshot.json` rusak / korup, bridge process crash atau menghasilkan JSON terpotong, bridge timeout, dan fallback peringatan saat PDF gagal dibuat.
9. **Matriks Serangan dan Keamanan (*Security Cases*)**:
   - SSRF & Kebijakan URL: Penolakan alamat IP privat (10.x, 172.16-31.x, 192.168.x), loopback (127.x, ::1), cloud metadata (169.254.169.254, 100.100.100.200), variasi localhost, kredensial (`user:pass@`), protokol non-HTTP (`file:`, `ftp:`, `javascript:`, `data:`), port non-standar, format IP desimal/oktal/heksadesimal, dan redirect ke alamat internal.
   - Keamanan Token: Entropi acak, token tidak pernah bocor ke log, perbandingan konstan (`hash_equals`), dan respon 404 seragam antara token salah dan ID tidak ditemukan untuk mencegah enumerasi job.
   - Path Traversal: Serangan `..`, encoded `%2e%2e`, slash ganda, dan null bytes pada nama artefak maupun ID job tidak boleh membaca file di luar direktori artefak pekerjaan.
   - Header Keamanan: Header CSP ketat (`default-src 'none'`), `X-Content-Type-Options: nosniff`, dan pembuktian HTML artefak tidak dapat mengeksekusi script.
   - CORS Security: Penolakan origin tidak sah (403), origin `null`, origin dengan trailing slash atau perbedaan huruf kapital, dan jaminan kredensial tidak pernah digabung dengan wildcard `*`.
   - Injeksi & XSS: Parameter SQL injection tidak merusak query (prepared statement), upaya HTTP header injection (CRLF) tidak bocor ke header respons, dan karakter `<script>` atau tanda kutip pada judul/selector dieksekusi dengan aman (lolos escaping).
   - Proteksi Sumber Daya: Permintaan dengan body terlalu besar (413) atau URL terlalu panjang (400) langsung ditolak tanpa memicu eksekusi bridge Node/Playwright.
   - Kebocoran Informasi: Memastikan respons kesalahan 4xx dan 500 tidak pernah menampilkan jejak panggilan (*stack trace*), path internal server, maupun konfigurasi rahasia saat `APP_DEBUG=false`.

---

## Bagian 2: Katalog Skenario Penggunaan (*Use Case Catalog*)

Setiap use case memiliki ID unik, deskripsi bahasa Indonesia yang mudah dimengerti pengguna non-teknis, hasil yang diharapkan, serta berkas dan nama fungsi tes otomatis yang memvalidasinya.

### 1. Skenario Pengguna Nyata (Prefix `UC-USR`)

| ID Use Case | Deskripsi Sederhana (Bahasa Indonesia) | Hasil yang Diharapkan | Tes Otomatis |
|---|---|---|---|
| `UC-USR-01` | Alur lengkap sukses: Pengguna membuat audit baru, memantau progres hingga selesai, melihat hasil, dan mengunduh seluruh berkas artefak. | HTTP 202 saat dibuat -> progres bertahap (5%, 50%, 90%, 100%) -> HTTP 200 pada `/result` -> keempat artefak (`reader`, `patched`, `report.pdf`, `screenshot`) berhasil diunduh. | `UserScenarioTest::test_UC_USR_01_happy_path_walks_full_audit_lifecycle` |
| `UC-USR-02` | Pengguna memeriksa ketersediaan cache: belum ada hasil -> lakukan audit -> audit selesai -> pencarian cache kini tersedia -> audit ulang dengan hasil instan. | Cache lookup awal `available: false` -> setelah selesai menjadi `available: true` -> `reuseExisting: true` mengembalikan HTTP 200 langsung; `reuseExisting: false` membuat audit baru HTTP 202. | `UserScenarioTest::test_UC_USR_02_cache_flow_lookup_miss_hit_and_reuse_toggle` |
| `UC-USR-03` | Pengguna membatalkan audit yang masih berada di dalam antrean sebelum sempat diproses oleh sistem. | Permintaan DELETE menghasilkan HTTP 202, status berubah menjadi `cancelled`, dan pekerjaan tidak akan diproses oleh worker. | `UserScenarioTest::test_UC_USR_03_cancel_flow_queued_job_cancelled_immediately` |
| `UC-USR-04` | Pengguna membatalkan audit yang sedang berjalan di tengah proses pemeriksaan halaman web. | Permintaan DELETE menghasilkan HTTP 202, worker mendeteksi pembatalan, proses anak dihentikan, dan direktori berkas sementara dibersihkan dari penyimpanan. | `UserScenarioTest::test_UC_USR_04_cancel_flow_running_job_aborted_and_cleans_artifacts` |
| `UC-USR-05` | Dua pengguna mengirim audit secara bersamaan; sistem memproses satu per satu sesuai urutan antrean tanpa tumpang-tindih. | Pengguna pertama langsung berjalan (`queuePosition: null`), pengguna kedua menunggu di antrean (`queuePosition: 1`), dan keduanya selesai bergantian secara FIFO. | `UserScenarioTest::test_UC_USR_05_queue_flow_two_concurrent_users_run_fifo_with_queue_positions` |
| `UC-USR-06` | Gangguan peramban sementara otomatis dicoba ulang sekali oleh sistem dan akhirnya berhasil tanpa membingungkan pengguna. | Percobaan 1 gagal (`browser-failed`) -> pekerjaan dijadwalkan ulang ke antrean -> percobaan 2 berhasil dan status akhir menjadi `completed`. | `UserScenarioTest::test_UC_USR_06_retry_flow_transient_failure_retried_once_then_succeeds` |
| `UC-USR-07` | Pemeriksaan web yang ditolak oleh aturan situs (robots.txt) langsung dinyatakan gagal dengan pesan bahasa Indonesia yang jelas. | Status menjadi `failed`, kode error `robots-disallowed`, dan pesan ramah pengguna bahasa Indonesia tersimpan tanpa mencoba ulang. | `UserScenarioTest::test_UC_USR_07_permanent_failure_terminates_immediately_with_indonesian_message` |
| `UC-USR-08` | Hasil audit yang telah melewati masa simpan 7 hari ditolak saat diakses, dan data lamanya dibersihkan oleh perintah sistem. | Mengunduh artefak mengembalikan HTTP 410 `Artefak sudah kedaluwarsa.`, dan perintah Artisan `audit:cleanup` menghapus catatan database beserta folder berkasnya. | `UserScenarioTest::test_UC_USR_08_expiry_flow_returns_410_and_artisan_cleanup_removes_files_and_db` |
| `UC-USR-09` | Server mengalami restart mendadak saat audit berjalan; pekerjaan dipulihkan secara otomatis saat server menyala kembali. | Status `running` dipulihkan ke `queued`: tanpa checkpoint mulai dari awal (0%), dengan checkpoint melanjutkan finalisasi (90%), dan gagal permanen jika sudah terputus 2 kali. | `UserScenarioTest::test_UC_USR_09_crash_recovery_recovers_interrupted_jobs_on_worker_startup` |
| `UC-USR-10` | Antarmuka pengguna web (frontend Next.js) dapat berkomunikasi lancar dengan backend PHP tanpa kendala ketidakcocokan tipe data. | Seluruh struktur data dan tipe data yang dipanggil oleh `src/lib/audit-api.ts` terpenuhi 100% dengan tipe data yang persis diharapkan. | `UserScenarioTest::test_UC_USR_10_frontend_compatibility_replays_exact_audit_api_client_contract` |

---

### 2. Skenario Kegagalan dan Kasus Abnormal (Prefix `UC-FAIL`)

| ID Use Case | Deskripsi Sederhana (Bahasa Indonesia) | Hasil yang Diharapkan | Tes Otomatis |
|---|---|---|---|
| `UC-FAIL-01` | Pendaftaran audit tanpa menyertakan alamat web (URL kosong atau tidak ada). | HTTP 400 dengan error `empty-url` dan pesan `URL kosong.`. | `FailureScenarioTest::test_UC_FAIL_01_missing_or_empty_url` |
| `UC-FAIL-02` | Nilai URL yang dikirimkan bukan berupa teks (misalnya angka, larik, atau objek). | HTTP 400 dengan error `empty-url` atau `unparseable-url`. | `FailureScenarioTest::test_UC_FAIL_02_url_not_a_string` |
| `UC-FAIL-03` | Alamat web yang dikirimkan terlalu panjang (melebihi batas aman 2048 karakter). | HTTP 400 dengan error `url-too-long` dan pesan batas karakter. | `FailureScenarioTest::test_UC_FAIL_03_url_exceeds_maximum_length` |
| `UC-FAIL-04` | Format data JSON yang dikirimkan rusak atau tidak lengkap susunannya. | HTTP 400 dengan error `bad-request` dan pesan `JSON tidak valid.`. | `FailureScenarioTest::test_UC_FAIL_04_malformed_json_body` |
| `UC-FAIL-05` | Mengirim permintaan POST dengan isi badan permintaan (*body*) kosong. | HTTP 400 dengan error `empty-url` dan pesan `URL kosong.`. | `FailureScenarioTest::test_UC_FAIL_05_empty_request_body` |
| `UC-FAIL-06` | Mengirim data menggunakan tipe konten selain JSON (misalnya text/plain atau form data). | HTTP 415 dengan pesan `Gunakan Content-Type application/json.`. | `FailureScenarioTest::test_UC_FAIL_06_unsupported_media_type_content_type` |
| `UC-FAIL-07` | Mengirimkan ukuran data badan permintaan yang melampaui batas maksimal sistem. | HTTP 413 dengan pesan `Body permintaan terlalu besar.`. | `FailureScenarioTest::test_UC_FAIL_07_request_body_exceeds_maximum_bytes` |
| `UC-FAIL-08` | Parameter opsi `reuseExisting` diisi nilai bukan boolean (misalnya string atau angka). | Nilai non-boolean diperlakukan sebagai false dan membuat audit baru secara aman. | `FailureScenarioTest::test_UC_FAIL_08_reuse_existing_non_boolean_handling` |
| `UC-FAIL-09` | Permintaan menyertakan field tambahan yang tidak dikenal sistem. | Sistem mengabaikan field tambahan tanpa menimbulkan kesalahan internal (HTTP 202). | `FailureScenarioTest::test_UC_FAIL_09_unknown_payload_fields_ignored_safely` |
| `UC-FAIL-10` | Mengakses status atau hasil audit tanpa menyertakan token otentikasi sama sekali. | HTTP 404 dengan pesan `Audit tidak ditemukan.` (disamarkan demi privasi). | `FailureScenarioTest::test_UC_FAIL_10_missing_authorization_token` |
| `UC-FAIL-11` | Mengakses status audit menggunakan token acak yang salah atau milik pekerjaan lain. | HTTP 404 dengan pesan `Audit tidak ditemukan.`. | `FailureScenarioTest::test_UC_FAIL_11_invalid_or_mismatched_authorization_token` |
| `UC-FAIL-12` | Menggunakan token share publik untuk mengakses status pekerjaan atau hasil audit utama. | HTTP 404 dengan pesan `Audit tidak ditemukan.` (token share tidak boleh mengakses endpoint privat). | `FailureScenarioTest::test_UC_FAIL_12_share_token_rejected_on_private_endpoints` |
| `UC-FAIL-13` | Menggunakan token akses utama pada endpoint unduh artefak publik. | HTTP 404 dengan pesan `Artefak tidak ditemukan.` (endpoint artefak hanya menerima token share). | `FailureScenarioTest::test_UC_FAIL_13_access_token_rejected_on_artifact_download` |
| `UC-FAIL-14` | Pengiriman token akses melalui header HTTP Authorization Bearer dan melalui query URL. | Keduanya diterima dengan baik dan mengembalikan hasil yang identik. | `FailureScenarioTest::test_UC_FAIL_14_token_via_header_versus_query_parameter` |
| `UC-FAIL-15` | Mengakses ID pekerjaan yang tidak ada, atau ID dengan format aneh (simbol SQL, path, karakter aneh). | HTTP 404 dengan pesan `Audit tidak ditemukan.` tanpa menimbulkan kesalahan basis data. | `FailureScenarioTest::test_UC_FAIL_15_unknown_or_malformed_job_ids` |
| `UC-FAIL-16` | Meminta nama berkas artefak yang tidak terdaftar (misalnya `unknown.bin` atau `secret.txt`). | HTTP 404 dengan pesan `Artefak tidak ditemukan.`. | `FailureScenarioTest::test_UC_FAIL_16_unknown_artifact_names` |
| `UC-FAIL-17` | Pengguna mencoba mengambil hasil akhir saat audit masih mengantre atau sedang berjalan. | HTTP 409 dengan error `not-ready` disertai informasi status progres terkini. | `FailureScenarioTest::test_UC_FAIL_17_result_requested_before_completion` |
| `UC-FAIL-18` | Pengguna mencoba membatalkan pekerjaan yang sudah selesai, gagal, atau sudah dibatalkan sebelumnya. | HTTP 202 mengembalikan status pekerjaan tanpa mengubah status pekerjaan yang sudah final. | `FailureScenarioTest::test_UC_FAIL_18_cancelling_already_completed_or_cancelled_job` |
| `UC-FAIL-19` | Pengguna mencapai batas audit per jam dan mencoba mengirim audit satu kali lagi. | Permintaan tepat batas diterima (HTTP 202); permintaan berikutnya ditolak HTTP 429 dengan header `Retry-After: 3600`. | `FailureScenarioTest::test_UC_FAIL_19_rate_limit_exact_boundary_and_retry_after` |
| `UC-FAIL-20` | Jeda waktu pembatasan audit: pengguna yang terblokir mencoba lagi setelah satu jam berlalu. | Sistem mengizinkan audit baru setelah jendela waktu 1 jam berlalu (HTTP 202). | `FailureScenarioTest::test_UC_FAIL_20_rate_limit_window_reset` |
| `UC-FAIL-21` | Pengujian kuota audit untuk dua alamat IP berbeda dan penolakan pemalsuan IP melalui proxy. | Tiap IP dihitung terpisah; pemalsuan `X-Forwarded-For` diabaikan jika `TRUST_PROXY=0`. | `FailureScenarioTest::test_UC_FAIL_21_rate_limit_ip_isolation_and_spoofing` |
| `UC-FAIL-22` | Berkas artefak fisik tidak sengaja terhapus dari disk padahal status di basis data selesai. | Endpoint unduh artefak mengembalikan HTTP 404 `Artefak tidak ditemukan.` secara elegan tanpa crash. | `FailureScenarioTest::test_UC_FAIL_22_artifact_file_missing_on_disk` |
| `UC-FAIL-23` | Berkas checkpoint `snapshot.json` rusak atau terpotong saat proses pemulihan audit. | Auditor mendeteksi kerusakan JSON dan melakukan audit ulang secara bersih dari awal. | `FailureScenarioTest::test_UC_FAIL_23_corrupted_checkpoint_recovery_fallback` |
| `UC-FAIL-24` | Proses engine eksternal mengalami crash atau mengeluarkan format respon yang rusak. | Worker menangani kegagalan proses secara aman dan menandai audit gagal tanpa mematikan aplikasi. | `FailureScenarioTest::test_UC_FAIL_24_bridge_process_crash_or_invalid_ndjson` |
| `UC-FAIL-25` | Proses audit berjalan melebihi batas waktu maksimal yang ditetapkan sistem (*timeout*). | Audit ditandai gagal dengan error `audit-timeout` dan pesan bahasa Indonesia ramah pengguna. | `FailureScenarioTest::test_UC_FAIL_25_bridge_execution_timeout` |
| `UC-FAIL-26` | Pembuatan laporan PDF gagal karena keterbatasan memori server. | Sistem beralih ke fallback laporan HTML dengan peringatan tercatat tanpa membatalkan audit. | `FailureScenarioTest::test_UC_FAIL_26_pdf_rendering_fallback_with_warning` |
| `UC-FAIL-27` | Kunci rahasia `BACKEND_SECRET` kurang dari 32 karakter saat dijalankan di mode produksi. | Aplikasi menolak booting dengan pesan `BACKEND_SECRET minimal 32 karakter di production.`. | `FailureScenarioTest::test_UC_FAIL_27_config_short_secret_in_production` |
| `UC-FAIL-28` | Pengaturan angka lingkungan (port, timeout, retensi) bernilai tidak valid atau di luar rentang. | Inisialisasi konfigurasi melempar pengecualian dengan pesan bahasa Indonesia yang jelas. | `FailureScenarioTest::test_UC_FAIL_28_config_invalid_numeric_ranges` |
| `UC-FAIL-29` | Pengaturan `FRONTEND_ORIGINS` kosong atau tidak memiliki origin yang valid. | Inisialisasi konfigurasi melempar pengecualian yang mengharuskan minimal satu origin terdaftar. | `FailureScenarioTest::test_UC_FAIL_29_config_empty_frontend_origins` |

---

### 3. Skenario Keamanan dan Pencegahan Serangan (Prefix `UC-SEC`)

| ID Use Case | Deskripsi Sederhana (Bahasa Indonesia) | Hasil yang Diharapkan | Tes Otomatis |
|---|---|---|---|
| `UC-SEC-01` | Mencegah penyerang memasukkan alamat IP jaringan internal/privat (SSRF IPv4 privat). | Ditolak HTTP 400 dengan `ip-literal-not-allowed` atau `blocked-address`. | `SecurityScenarioTest::test_UC_SEC_01_rejects_private_and_local_ip_addresses` |
| `UC-SEC-02` | Mencegah penyerang memasukkan alamat loopback/mesin server sendiri (localhost, 127.0.0.1). | Ditolak HTTP 400 dengan error `local-host-name` atau `ip-literal-not-allowed`. | `SecurityScenarioTest::test_UC_SEC_02_rejects_loopback_and_localhost_variants` |
| `UC-SEC-03` | Mencegah penyerang mengakses endpoint metadata penyedia cloud (AWS, GCP, Alibaba, Azure). | Ditolak HTTP 400 dengan error `local-host-name` atau `blocked-address`. | `SecurityScenarioTest::test_UC_SEC_03_rejects_cloud_metadata_endpoints` |
| `UC-SEC-04` | Mencegah manipulasi format alamat IP pintar (angka desimal murni, heksadesimal, oktal). | Ditolak HTTP 400 dengan error `hostname-not-allowed` atau `ip-literal-not-allowed`. | `SecurityScenarioTest::test_UC_SEC_04_rejects_obscure_ip_notations_octal_hex_decimal` |
| `UC-SEC-05` | Mencegah penyisipan username atau password di dalam URL (`user:pass@host`). | Ditolak HTTP 400 dengan error `credentials-in-url`. | `SecurityScenarioTest::test_UC_SEC_05_rejects_credentials_in_url` |
| `UC-SEC-06` | Mencegah penggunaan skema protokol selain web HTTP/HTTPS (`file:`, `ftp:`, `javascript:`, `data:`). | Ditolak HTTP 400 dengan error `protocol-not-allowed`. | `SecurityScenarioTest::test_UC_SEC_06_rejects_non_http_protocols` |
| `UC-SEC-07` | Mencegah penyerang memindai port jaringan internal non-standar (misal port 22, 3306, 8080). | Ditolak HTTP 400 dengan error `port-not-allowed`. | `SecurityScenarioTest::test_UC_SEC_07_rejects_non_standard_ports` |
| `UC-SEC-08` | Mencegah pengalihan alamat (redirect) dari URL publik menuju jaringan internal server. | URL pengalihan diverifikasi ulang dari awal; jika mengarah ke internal, proses ditolak seketika. | `SecurityScenarioTest::test_UC_SEC_08_prevents_ssrf_via_redirect_chain` |
| `UC-SEC-09` | Memastikan token akses dan ID pekerjaan dibuat secara acak kriptografis dengan panjang aman. | ID memiliki entropi tinggi diawali `aud_`, dan token akses memiliki panjang minimal 43 karakter. | `SecurityScenarioTest::test_UC_SEC_09_tokens_are_cryptographically_random_and_secure` |
| `UC-SEC-10` | Memastikan token akses dan kunci rahasia sistem tidak pernah bocor ke dalam catatan log. | Penangkapan log aplikasi selama proses audit tidak memuat string token akses maupun `BACKEND_SECRET`. | `SecurityScenarioTest::test_UC_SEC_10_tokens_and_secret_never_appear_in_system_logs` |
| `UC-SEC-11` | Verifikasi token otentikasi wajib menggunakan fungsi tahan serangan waktu (*timing-safe*). | Fungsi `safeEqualHex` menggunakan `hash_equals` untuk mencegah serangan analisis waktu (*timing attack*). | `SecurityScenarioTest::test_UC_SEC_11_token_verification_is_constant_time` |
| `UC-SEC-12` | Mencegah penyerang menebak keberadaan audit melalui respon beda saat token salah. | Respon untuk pekerjaan tidak ada dan respon untuk token salah menghasilkan HTTP 404 dengan body persis sama. | `SecurityScenarioTest::test_UC_SEC_12_uniform_404_response_prevents_job_enumeration` |
| `UC-SEC-13` | Mencegah serangan pembacaan file di luar folder izin (*path traversal* `../`, `%2e%2e`). | Permintaan dengan karakter traversal mengembalikan 404 tanpa pernah menyentuh file di luar folder pekerjaan. | `SecurityScenarioTest::test_UC_SEC_13_path_traversal_in_artifacts_strictly_prevented` |
| `UC-SEC-14` | Memastikan respons berkas artefak memuat header keamanan ketat (CSP) yang mencegah eksekusi skrip berbahaya. | Header `Content-Security-Policy` memuat `default-src 'none'`, mencegah eksekusi inline script pada HTML artefak. | `SecurityScenarioTest::test_UC_SEC_14_artifact_responses_carry_strict_csp_and_security_headers` |
| `UC-SEC-15` | Mencegah akses lintas situs dari domain tidak dikenal (CORS origin validation). | Permintaan peramban dari origin yang tidak ada di daftar ditolak tegas dengan HTTP 403 `origin-not-allowed`. | `SecurityScenarioTest::test_UC_SEC_15_cors_untrusted_origins_rejected_with_403` |
| `UC-SEC-16` | Memastikan konfigurasi CORS tidak pernah menggabungkan wildcard `*` dengan izin kredensial. | Header `Access-Control-Allow-Origin` merespon origin spesifik pemanggil, bukan `*`, dan menyertakan `Vary: Origin`. | `SecurityScenarioTest::test_UC_SEC_16_cors_never_combines_wildcard_with_credentials` |
| `UC-SEC-17` | Mencegah injeksi kode SQL pada parameter pencarian, ID pekerjaan, dan token. | Semua query SQL menggunakan parameter binding PDO yang aman; karakter petik atau perintah SQL tidak mempengaruhi eksekusi. | `SecurityScenarioTest::test_UC_SEC_17_sql_injection_defense_via_parameterized_queries` |
| `UC-SEC-18` | Mencegah injeksi header HTTP melalui penyisipan karakter baris baru (CRLF `\r\n`). | Karakter CRLF pada input pengguna tidak dapat memecah header HTTP respons. | `SecurityScenarioTest::test_UC_SEC_18_header_injection_crlf_attacks_blocked` |
| `UC-SEC-19` | Mencegah serangan Cross-Site Scripting (XSS) pada laporan HTML melalui escaping otomatis tag berbahaya. | Karakter tag `<script>` atau kutip pada selector dan judul halaman diubah menjadi entitas HTML aman (`&lt;script&gt;`). | `SecurityScenarioTest::test_UC_SEC_19_html_report_escapes_xss_vectors` |
| `UC-SEC-20` | Perlindungan terhadap penyalahgunaan sumber daya (Resource Exhaustion / DoS). | Permintaan dengan ukuran melebihi batas atau URL sangat panjang ditolak sebelum memicu beban komputasi engine. | `SecurityScenarioTest::test_UC_SEC_20_resource_abuse_rejected_cheaply_without_engine_overhead` |
| `UC-SEC-21` | Mencegah kebocoran informasi teknis server (*information leakage*) saat terjadi kesalahan sistem. | Respons kesalahan HTTP 4xx dan 500 saat `APP_DEBUG=false` tidak memuat path file server, query SQL, atau jejak stack. | `SecurityScenarioTest::test_UC_SEC_21_error_responses_never_leak_sensitive_internals` |

---
