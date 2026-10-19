# AksaraNetra Engine Enhancements

Laporan analisis arsitektur, kualitas perbaikan, keamanan, keandalan, dan cakupan pengujian untuk engine audit dan remediasi aksesibilitas AksaraNetra (`engine/`). Seluruh usulan didasarkan langsung pada temuan pada kode sumber, berkas pengujian, dan dokumentasi saat ini.

---

## 1. More Accessibility Rules

### 1.1 Remediasi dan Klasifikasi Atribut Alt Gambar (`image-alt`)
- **Penjelasan**: Mendeteksi gambar dekoratif (misal berdekatan dengan teks identik, memiliki class ikonik, atau role pembantu) untuk diberi `alt=""`, serta mengekstrak teks keterangan eksplisit (`<figcaption>`, `title`, `aria-describedby`) untuk gambar informatif tanpa pernah mengarang deskripsi visual.
- **Berkas terkait**: `engine/src/rules/image-alt.mjs` (baru), `engine/src/engine/index.mjs:20-23`, `engine/src/engine/extract-targets.mjs:3-7`, `engine/src/serializer/index.mjs:157-173`.
- **Effort / Impact**: M / High
- **Risiko**: Halusinasi deskripsi jika heuristik terlalu longgar; harus dijaga ketat agar gambar tanpa sumber teks eksplisit hanya disembunyikan atau diteruskan ke review manusia.

### 1.2 Pemberian Label Elemen Formulir Publik (`label`, `aria-label`)
- **Penjelasan**: Memberikan nama aksesibel pada kontrol formulir umum (seperti kotak pencarian berita dan kolom langganan) dengan mengaitkan teks `<label>` terdekat, atribut `placeholder`, atau tombol submit pendamping, memperluas cakupan dari penonaktifan formulir saat ini.
- **Berkas terkait**: `engine/src/rules/form-label.mjs` (baru), `engine/src/engine/index.mjs`, `engine/src/engine/extract-targets.mjs`, `engine/src/serializer/index.mjs:39-43`.
- **Effort / Impact**: M / High
- **Risiko**: Salah melabeli input bertingkat atau input tersembunyi jika penentuan keterkaitan label tidak presisi.

### 1.3 Deklarasi Bahasa Dokumen dan Bagian Konten (`html-has-lang`, `html-lang-valid`)
- **Penjelasan**: Memperbaiki atribut `lang` pada elemen `<html>` di halaman tertambal (`patched.html`) berdasarkan konfigurasi situs atau deteksi bahasa utama dokumen, sehingga pembaca layar tidak menggunakan aksen bahasa asing saat membaca teks Indonesia.
- **Berkas terkait**: `engine/src/rules/html-lang.mjs` (baru), `engine/src/engine/index.mjs`, `engine/src/serializer/index.mjs:186`, `engine/src/config/sites.mjs:11-32`.
- **Effort / Impact**: S / Med
- **Risiko**: Salah menentukan kode bahasa pada laman yang memuat konten dwibahasa atau artikel berbahasa daerah.

### 1.4 Perbaikan Judul Dokumen yang Hilang atau Generik (`document-title`)
- **Penjelasan**: Menambal tag `<title>` pada `patched.html` dengan judul utama `<h1>` atau identitas instansi dari konfigurasi situs saat dokumen asli memiliki `<title>` kosong atau hanya berisi nama berkas.
- **Berkas terkait**: `engine/src/rules/document-title.mjs` (baru), `engine/src/engine/index.mjs`, `engine/src/serializer/index.mjs:187`.
- **Effort / Impact**: S / Med
- **Risiko**: Judul hasil tambalan dapat terkesan berulang bila halaman sudah memiliki struktur breadcrumb yang panjang.

### 1.5 Penandaan Wilayah Pokok Halaman (`landmark-one-main`, `region`)
- **Penjelasan**: Menyematkan atribut `role="main"` atau tag `<main>` secara non-destruktif pada kontainer isi pokok di `patched.html` yang belum memiliki penanda wilayah, menyelaraskan halaman asli dengan jaminan navigasi landmark di `reader.html`.
- **Berkas terkait**: `engine/src/rules/landmarks.mjs` (baru), `engine/src/engine/index.mjs`, `engine/src/serializer/guarantees.mjs:124-133`.
- **Effort / Impact**: M / Med
- **Risiko**: Salah memilih kontainer utama bila markup halaman induk membungkus konten dan sidebar dalam satu div pembungkus tanpa kelas semantik.

---

## 2. Better Fix Quality and Accuracy

### 2.1 Disambiguasi Kontekstual untuk Nama Kembar (`duplicateLabels`)
- **Penjelasan**: Mengatasi label tombol atau tautan kembar yang dilaporkan `findDuplicateLabels` (misal tombol carousel "Media sebelumnya" di multi-widget) dengan menempelkan nama section heading terdekat sebagai pembeda kontekstual alih-alih sekadar memberi peringatan pasif.
- **Berkas terkait**: `engine/src/engine/report.mjs:109-130`, `engine/src/rules/button-name.mjs:254-285`, `engine/src/engine/index.mjs:89`.
- **Effort / Impact**: M / High
- **Risiko**: Label tombol dapat menjadi terlalu panjang jika heading section induk memuat kalimat berita yang panjang.

### 2.2 Mekanisme Rollback Parsial (Per-Patch Rollback)
- **Penjelasan**: Mengisolasi dan membatalkan hanya patch spesifik yang memicu regresi rule target, menggantikan perilaku saat ini di mana seluruh perbaikan satu halaman dibatalkan serentak ketika `metrics.noRegression` bernilai false.
- **Berkas terkait**: `engine/src/engine/index.mjs:69-74`, `engine/src/engine/apply-patch.mjs:105-125`, `engine/src/engine/report.mjs:8-52`.
- **Effort / Impact**: M / High
- **Risiko**: Menambah waktu proses audit karena membutuhkan evaluasi axe terfokus per kandidat yang dicurigai memicu regresi.

### 2.3 Resolusi Target DOM Multi-Strategi yang Lebih Tangguh
- **Penjelasan**: Memperkuat pencarian elemen yang saat ini langsung gagal jika `document.querySelectorAll(selector).length !== 1` dengan menambahkan strategi fingerprinting atribut (kombinasi tag, role, teks, dan posisi hirarkis) saat selector bawaan axe memuat karakter CSS escape yang rapuh.
- **Berkas terkait**: `engine/src/shared/selector.mjs:1-16`, `engine/src/rules/button-name.mjs:181-200`, `engine/src/engine/apply-patch.mjs:21-39`.
- **Effort / Impact**: M / Med
- **Risiko**: Potensi salah sasaran pada elemen tetangga jika kriteria relaksasi selector terlalu longgar pada daftar berulang.

### 2.4 Kalibrasi Multi-Sinyal pada Heuristik Tautan
- **Penjelasan**: Meningkatkan bobot keyakinan slug tautan dengan menggabungkan teks slug bersama elemen SVG inline (`<title>`, `<desc>`), atribut tooltip, atau `aria-describedby` terdekat alih-alih langsung menetapkan nilai flat 0.65.
- **Berkas terkait**: `engine/src/rules/link-name.mjs:147-162`.
- **Effort / Impact**: S / Med
- **Risiko**: Terlalu percaya diri pada slug URL teknis seperti `/detail/12` atau `/view/post`.

### 2.5 Pencatatan Jejak State Atribut Komprehensif
- **Penjelasan**: Mencatat nilai awal seluruh atribut yang dimodifikasi sebelum patch diterapkan ke dalam `appliedPatches` agar pembatalan rollback pada elemen dengan multi-atribut (misal `tabindex` sekaligus `role`) terjamin sinkron sempurna.
- **Berkas terkait**: `engine/src/engine/apply-patch.mjs:41-68`, `engine/src/engine/apply-patch.mjs:105-125`.
- **Effort / Impact**: S / Low
- **Risiko**: Sangat kecil; hanya menambah sedikit metadata in-memory pada objek hasil engine.

---

## 3. Coverage of Real-World Pages

### 3.1 Jendela Hidrasi Terkendali untuk Single-Page Applications (SPA)
- **Penjelasan**: Menyediakan opsi dua tahap pada guard jaringan agar skrip internal situs diizinkan berjalan sesaat pada awal pemuatan halaman untuk merender konten berbasis client-side framework (React, Next.js, Vue), sebelum guard memutus lalu lintas eksternal.
- **Berkas terkait**: `engine/src/guard/index.mjs:56-64`, `engine/src/runner/index.mjs:124-145`, `engine/src/api/index.mjs:367-377`.
- **Effort / Impact**: L / High
- **Risiko**: Mengeksekusi script pihak ketiga meningkatkan waktu henti dan risiko inkonsistensi DOM; butuh sandbox waktu dan isolasi memori ketat.

### 3.2 Dukungan Audit Pelanggaran di Dalam Iframe dan Shadow DOM
- **Penjelasan**: Memperluas fungsi pemecah selector agar tidak mengabaikan pelanggaran axe yang berada di dalam `iframe` dan shadow root (`target.length > 1`), memungkinkan remediasi peta publik, pemutar media, dan web components.
- **Berkas terkait**: `engine/src/engine/extract-targets.mjs:23-26`, `engine/src/shared/selector.mjs:1-5`, `engine/src/runner/index.mjs:202-205`.
- **Effort / Impact**: L / Med
- **Risiko**: Terbentur kebijakan keamanan lintas-asal (cross-origin iframe) peramban yang memblokir injeksi DOM dari konteks induk.

### 3.3 Penanganan Penutup Otomatis untuk Dialog Banner Cookie dan Modal Popup
- **Penjelasan**: Menambahkan langkah pra-audit untuk mendeteksi dan menutup overlay pop-up pengumuman atau banner persetujuan privasi yang kerap menjebak fokus keyboard dan menutupi konten utama pada portal pemerintah.
- **Berkas terkait**: `engine/src/runner/index.mjs:124-145`.
- **Effort / Impact**: M / Med
- **Risiko**: Salah memicu klik pada tombol navigasi biasa jika pola pendeteksi tombol dismiss modal keliru.

### 3.4 Pematangan Pengguliran Halaman Adaptif (Lazy Loading & Virtual Lists)
- **Penjelasan**: Mengganti batas statis pengguliran 3600px / 150ms dengan pemantauan mutasi DOM atau intersection observer terukur agar gambar dan kartu berita malas-muat (lazy-loaded) terpicu sempurna tanpa meledakkan DOM portal berita tak berujung (infinite scroll).
- **Berkas terkait**: `engine/src/runner/index.mjs:128-142`.
- **Effort / Impact**: S / Med
- **Risiko**: Penambahan durasi audit pada halaman dengan muatan gambar berukuran besar.

### 3.5 Tokenisasi Berbasis Unicode untuk Aksara Daerah dan Teks Non-Latin
- **Penjelasan**: Memperbarui pemecah camelCase dan penyaring karakter pemisah di `button-name.mjs` agar menggunakan escape properti Unicode (`\p{L}`), mencegah hilangnya token pada teks berbahasa daerah atau aksara selain alfabet Latin standar.
- **Berkas terkait**: `engine/src/rules/button-name.mjs:139-142`, `engine/src/shared/text.mjs:1-18`.
- **Effort / Impact**: S / Med
- **Risiko**: Sangat kecil; merupakan fitur standar ekspresi reguler JavaScript modern.

---

## 4. Performance and Reliability

### 4.1 Manajemen Context Browser Berkelanjutan (Browser Context Reuse / Pooling)
- **Penjelasan**: Mencegah overhead pembuatan dan pembongkaran proses Chromium pada setiap pemanggilan audit tunggal atau pembuatan PDF (`renderPdf`) dengan memanfaatkan browser instance pool yang terisolasi per context.
- **Berkas terkait**: `engine/src/runner/index.mjs:100-116`, `engine/src/runner/index.mjs:295-308`, `engine/src/api/index.mjs:258-266`.
- **Effort / Impact**: M / High
- **Risiko**: Kebocoran memori antar-tugas jika context browser tidak ditutup dan dibersihkan secara mutlak pada blok `finally`.

### 4.2 Batas Waktu Tersegmen dan Pemulihan Audit Parsial
- **Penjelasan**: Memecah batas waktu statis `page.goto` 60 detik menjadi tahapan koneksi, pemuatan HTML, dan pengguliran dengan waktu terukur, serta tetap menghasilkan laporan parsial bila sub-aset halaman mengalami kemacetan.
- **Berkas terkait**: `engine/src/runner/index.mjs:167-170`, `engine/src/api/index.mjs:379-391`.
- **Effort / Impact**: S / Med
- **Risiko**: Menghentikan proses terlalu dini pada portal instansi yang infrastruktur servernya memang lambat.

### 4.3 Optimasi Alokasi Memori Container Terbatas (Lingkungan 512MB RAM)
- **Penjelasan**: Mengurangi lonjakan memori saat evaluasi axe dan tangkapan layar dengan membatasi ruang lingkup pemindaian ke elemen `body` dan menerapkan kompresi tangkapan layar langsung ke format stream.
- **Berkas terkait**: `engine/src/runner/index.mjs:70-98`, `engine/src/runner/index.mjs:267-293`.
- **Effort / Impact**: M / High
- **Risiko**: Sangat kecil; langsung memperkuat ketahanan container produksi terhadap sinyal OOM-kill kernel.

### 4.4 Percobaan Ulang Cerdas (Exponential Backoff Retries) pada Kegagalan Jaringan
- **Penjelasan**: Menambahkan mekanisme retry singkat dengan jeda eksponensial pada tahap pencarian DNS dan pengambilan berkas `robots.txt` sebelum langsung menggagalkan audit karena fluktuasi koneksi sesaat.
- **Berkas terkait**: `engine/src/api/index.mjs:321-326`, `engine/src/api/index.mjs:334-340`.
- **Effort / Impact**: S / Med
- **Risiko**: Memperpanjang antrean pemrosesan jika domain target memang mengalami pemadaman total.

---

## 5. Security and Safety

### 5.1 Penutupan Celah DNS Rebinding pada Engine Runner
- **Penjelasan**: Mengikat alamat IP hasil resolusi DNS awal langsung ke opsi peluncuran Chromium (`--host-resolver-rules` atau custom forwarding) agar Chromium tidak melakukan resolusi DNS ulang independen yang berpotensi dialihkan penyerang ke IP privat.
- **Berkas terkait**: `engine/src/guard/index.mjs:13-20`, `engine/src/runner/index.mjs:100-116`, `engine/src/api/index.mjs:321-331`.
- **Effort / Impact**: M / High
- **Risiko**: Konfigurasi DNS resolver Chromium yang keliru dapat memblokir pemuatan sub-aset dari CDN pihak ketiga yang sah.

### 5.2 Izin Pengalihan Satu Hop pada Host yang Sama (Same-Host 1-Hop Redirect)
- **Penjelasan**: Mengizinkan peramban mengikuti satu kali pengalihan URL tipe document selama host tujuan sama persis dengan host yang diminta (misal pengalihan `http://` ke `https://` atau `/` ke `/home`), menyelesaikan batasan audit gagal saat pengguna mengetik URL tanpa https.
- **Berkas terkait**: `engine/src/guard/index.mjs:22-43`, `engine/src/guard/index.mjs:118-123`, `engine/src/api/index.mjs:311-316`.
- **Effort / Impact**: S / High
- **Risiko**: Pengalihan ke host yang berbeda harus tetap ditolak mutlak agar tidak membuka celah bypass validasi DNS dan robots.txt.

### 5.3 Sanitasi dan Serialisasi HTML Berbasis DOM pada wrapPatchedPage
- **Penjelasan**: Mengganti penyisipan header CSP dan banner pemberitahuan yang saat ini menggunakan regex string mentah (`html.replace(/<head...>/)`) dengan manipulasi DOM terstruktur untuk mencegah kerusakan markup bila halaman asli memiliki tag yang cacat atau komentar bersarang.
- **Berkas terkait**: `engine/src/serializer/index.mjs:196-211`.
- **Effort / Impact**: S / Med
- **Risiko**: Sedikit penambahan waktu pemrosesan saat melakukan parsing dan serialisasi ulang dokumen HTML penuh.

### 5.4 Penambahan Daftar Hitam Endpoint Metadata Cloud Provider
- **Penjelasan**: Memperluas klasifikasi IP terlarang pada `ip-rules.mjs` dengan mencakup variasi endpoint metadata internal cloud (seperti GCP `metadata.google.internal` dan Alibaba `100.100.100.200`) serta representasi IPv4-mapped IPv6 terbungkus.
- **Berkas terkait**: `engine/src/fetcher/ip-rules.mjs:17-47`, `engine/src/fetcher/url-policy.mjs:102-122`.
- **Effort / Impact**: S / Med
- **Risiko**: Sangat kecil; murni mempertebal perimeter pencegahan SSRF.

---

## 6. Reports and Output

### 6.2 Penyajian Bukti Potongan Kode Sebelum dan Sesudah yang Tersanitasi
- **Penjelasan**: Menghadirkan cuplikan kode diff HTML mini yang sudah dibersihkan dari tag berbahaya pada `engine-result.json` (menggantikan `htmlAfter` mentah yang dihapus), sehingga pembuat situs dapat langsung menyalin perbaikan ke sistem mereka.
- **Berkas terkait**: `engine/src/decisions/index.mjs:9-22`, `engine/src/decisions/index.mjs:72-85`, `engine/src/engine/apply-patch.mjs:81`.
- **Effort / Impact**: M / High
- **Risiko**: Cuplikan kode harus dibersihkan secara ketat agar tidak membawa muatan payload XSS atau SVG berukuran besar ke UI.


### 6.4 Sinkronisasi Kontrak Pemindaian Luas (Wide Regression Contract Fix)
- **Penjelasan**: Menghubungkan kembali properti `wideAfterAxe`, `wideRegression`, dan `rolledBackRecords` dari `remediatePage` ke objek laporan di `runner/index.mjs`, memperbaiki nilai yang saat ini bernilai `undefined` pada berkas hasil.
- **Berkas terkait**: `engine/src/engine/index.mjs:78-92`, `engine/src/runner/index.mjs:209-237`.
- **Effort / Impact**: S / High
- **Risiko**: Memerlukan pemindaian axe luas pasca-patch pada engine, yang menambah sedikit waktu namun mengembalikan validitas data regresi.

---

## 7. Testing

### 7.1 Penambahan Fixture untuk Kasus Tepi Dunia Nyata (Complex Real-World Fixtures)
- **Penjelasan**: Memperbanyak koleksi di `tests/fixtures/` yang saat ini baru berjumlah 5 berkas dengan menambahkan kasus tepi markup nyata: struktur heading yang melompat jauh (h2 ke h5), judul berita kembar non-tautan, tabel data kompleks, dan tombol dengan icon font multi-class.
- **Berkas terkait**: `engine/tests/fixtures/` (berkas baru), `engine/tests/engine.test.mjs`, `engine/tests/serializer.test.mjs`.
- **Effort / Impact**: S / Med
- **Risiko**: Tidak ada.

### 7.2 Harness Pengujian Regresi Luring dengan Mock HTTP Server (HAR Replay)
- **Penjelasan**: Membangun harness pengujian berbasis server mock lokal yang memutar ulang rekaman respon HTTP (HAR) situs katalog (Sulselprov, Kepriprov), memungkinkan pengujian otomatis end-to-end tanpa bergantung pada koneksi internet dan perubahan situs langsung.
- **Berkas terkait**: `engine/tests/harness/mock-server.mjs` (baru), `engine/tests/api.test.mjs`, `engine/package.json:6-14`.
- **Effort / Impact**: M / High
- **Risiko**: Rekaman fixture perlu diperbarui berkala jika situs target melakukan perubahan besar.

### 7.3 Pengujian End-to-End untuk Antarmuka Bridge CLI Streaming
- **Penjelasan**: Menyusun unit test untuk `php-backend/bridge/audit-cli.mjs` guna memvalidasi parsing input stdin JSON, pemancaran event progress, penanganan sinyal abort (`SIGTERM`), dan kode keluar proses CLI.
- **Berkas terkait**: `php-backend/bridge/audit-cli.mjs:1-120`, `engine/tests/api.test.mjs`.
- **Effort / Impact**: S / Med
- **Risiko**: Tidak ada.

---

## Top 5 Recommended First

1. **Sinkronisasi Kontrak Pemindaian Luas (`runner/index.mjs:209-237` & `engine/index.mjs:78-92`)**
   - *Alasan*: Memperbaiki bug nyata saat ini di mana field `wideAfterAxe`, `wideRegression`, dan `rolledBackRecords` bernilai `undefined` pada artefak audit.
2. **Izin Pengalihan Satu Hop pada Host yang Sama (`guard/index.mjs:22-43`, `118-123`)**
   - *Alasan*: Mencegah kegagalan audit fatal ketika pengguna mengetik URL tanpa `https://` atau tanpa trailing slash, menyelesaikan catatan TODO yang sudah dirancang tim.
3. **Disambiguasi Kontekstual untuk Nama Kembar (`report.mjs:109-130` & `button-name.mjs`)**
   - *Alasan*: Mengubah peringatan nama tombol kembar pasif menjadi perbaikan nyata yang membedakan tombol bagi pengguna screen reader tanpa mengarang teks.
4. **Remediasi dan Klasifikasi Atribut Alt Gambar (`image-alt.mjs`)**
   - *Alasan*: Menangani jenis pelanggaran aksesibilitas paling dominan pada portal pemerintah dengan tetap setia pada prinsip tidak pernah mengarang deskripsi visual.
5. **Mekanisme Rollback Parsial / Per-Patch (`engine/index.mjs:69-74` & `apply-patch.mjs`)**
   - *Alasan*: Menghindari pembatalan sepihak puluhan perbaikan valid hanya karena satu elemen bermasalah memicu regresi metrik.

---

## Hal yang Terlihat Rapuh atau Belum Jelas (Fragile / Unclear Areas)

1. **Ketidaksesuaian Kontrak Kembalian `remediatePage` dengan `runner/index.mjs`**:
   Pada `runner/index.mjs:209-237`, kode mengambil `result.wideAfterAxe`, `result.wideRegression`, dan `result.rolledBackRecords`. Namun, `remediatePage` di `src/engine/index.mjs:78-92` sama sekali tidak mengembalikan ketiga properti tersebut. Hal ini menyebabkan field laporan terkait regresi luas dan jejak rollback selalu bernilai `undefined`.
2. **Penyisipan CSP dan Banner dengan Regex String Mentah pada `wrapPatchedPage`**:
   Di `src/serializer/index.mjs:196-211`, injeksi `<meta http-equiv="Content-Security-Policy">` dan banner peringatan dilakukan melalui penggantian string reguler `html.replace(/<head([^>]*)>/i, ...)` dan `html.replace(/<body([^>]*)>/i, ...)`. Jika halaman tidak memiliki tag penutup teratur, memiliki tag di dalam komentar HTML, atau tag ditulis tidak baku, penyisipan dapat gagal diam-diam atau merusak struktur halaman.
3. **Pemblokiran Total Seluruh Script (`ALWAYS_BLOCKED`) Menghancurkan Audit Laman SPA**:
   Pada `src/guard/index.mjs:56-64`, jenis permintaan `script`, `xhr`, dan `fetch` selalu ditolak mutlak. Akibatnya, portal modern berbasis SPA (React, Vue, Next.js) akan tertahan sebagai kontainer kosong (`<div id="root"></div>`) dengan konten 0 node dan menghasilkan laporan yang keliru.
4. **Penolakan Seluruh Pengalihan Dokumen di Tingkat Guard**:
   Pada `src/guard/index.mjs:118-123`, jika `asked.url !== target.url`, guard langsung menolak dengan alasan `document-not-the-target`. Situs umum yang mengarahkan HTTP ke HTTPS atau menambahkan trailing slash langsung gagal diaudit di langkah 6/7.
5. **Celah DNS Rebinding Masih Terbuka Lebar**:
   Sesuai catatan jujur di `src/guard/index.mjs:13-20`, DNS divalidasi satu kali di Node.js, tetapi Chromium melakukan resolusi DNS kedua secara terpisah. Penyerang dapat mengembalikan alamat IP publik pada resolusi pertama, lalu mengembalikan IP jaringan privat (127.0.0.1 atau 169.254.169.254) pada resolusi Chromium.
6. **Kebijakan Rollback All-or-Nothing yang Terlalu Kasar**:
   Di `src/engine/index.mjs:69-74`, bila satu saja rule target memburuk (`!metrics.noRegression`), seluruh tambalan pada halaman itu ditarik kembali tanpa kompromi, mengorbankan belasan perbaikan lain yang sebenarnya sudah berhasil dan valid.
7. **Pengabaian Total Node Iframe dan Shadow DOM**:
   Di `src/engine/extract-targets.mjs:23-26` dan `src/shared/selector.mjs:1-5`, setiap node pelanggaran dari axe yang memiliki target lebih dari satu segmen (format penandaan iframe/shadow root oleh axe-core) langsung dibuang ke `unsupportedTargets` dan diabaikan dari alur remediasi.
