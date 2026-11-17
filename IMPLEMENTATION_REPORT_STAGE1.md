# IMPLEMENTATION_REPORT_STAGE1.md — Laporan Implementasi Tahap 1: Donasi Barang Dasar WeCare

> **Dokumen Laporan Resmi Pelaksanaan Tahap 1.**
> Repository: **MWF-Proyek / WeCare** (Laravel 13, PHP 8.5/8.3, Blade, Bootstrap 5, MySQL).
> Target Issue: **#55 Form Donasi Barang**, **#57 Pilihan Ekspedisi**, **#59 Konfirmasi Manual Penerimaan Barang**.
> Milestone: **#29 Donasi Barang** (tetap berstatus Open/In Progress hingga integrasi Komship pada tahap berikutnya).

---

## 1. Summary

| Issue | Target & Cakupan | Status Penyelesaian | Keterangan |
|---|---|---|---|
| **#55 Form Donasi Barang** | Pemisahan jumlah dan estimasi berat (`berat_gram`), filter kategori non-makanan, validasi file foto, upload aman + rollback, catatan, dan anonim. | **Selesai (100%)** | Seluruh validasi server-side dan tampilan Blade telah berfungsi penuh dan teruji. |
| **#57 Pilihan Ekspedisi** | Pemilihan metode `dikirim_sendiri` (JNE, J&T Express, SiCepat) dengan resi wajib & pembuatan 1 Shipment, serta `diantar_langsung` tanpa pembuatan Shipment. | **Selesai (100%)** | Mendukung kurir dengan simbol khusus (`J&T Express`), isolasi input stale antar metode pengiriman. |
| **#59 Konfirmasi Manual Penerimaan** | Konfirmasi fisik barang oleh Penerima pemilik campaign, pembaruan status Donation & Shipment ke `diterima`, pengisian `tanggal_diterima`, dan sinkronisasi progress via `CampaignProgressService::sync()`. | **Selesai (100%)** | Transaksional, terlindungi concurrency lock, idempotent (repeat call aman), dan menolak akses lintas user/role. |
| **#29 Donasi Barang (Parent)** | Milestone penampung alur donasi barang dari registrasi hingga tracking. | **Sebagian (In Progress)** | Sesuai batas instruksi, parent issue #29 **tidak ditutup** karena issue #56 (estimasi ongkir Komship) dan #58 (scheduled tracking resi) dialokasikan pada tahap berikutnya. |

**Hal yang sengaja belum/tidak dikerjakan (sesuai scope):**
- Panggilan HTTP/API ke Komship API (`#56`).
- Scheduled job polling nomor resi ekspedisi (`#58`).
- Gateway Midtrans donasi uang (`#47`–`#49`) dan Recurring (`#51`–`#54`).

---

## 2. Audit Baseline: Sebelum vs Sesudah

| Modul / Area | Kondisi Baseline (Awal Tahap 1) | Kondisi Terkini (Setelah Tahap 1) |
|---|---|---|
| **Skema Database Berat** | Kolom `donations.jumlah_barang` dipakai ganda dan berlabel "Jumlah / Berat Estimasi"; tidak ada kolom berat terpisah. | Ditambahkan kolom terpisah `donations.berat_gram` (`INT UNSIGNED NULL`) via migrasi inkremental. Data legacy tetap aman dengan nilai `NULL`. |
| **Dropdown Kategori Barang** | Mengambil seluruh record `ItemCategory::all()`, sehingga kategori makanan bisa muncul jika ada di DB. | Menggunakan query `ItemCategory::whereNull('deleted_at')->whereRaw('LOWER(slug) != ?', ['makanan'])->whereRaw('LOWER(nama) != ?', ['makanan'])->get()`. Kategori makanan dijamin tidak muncul di UI dan ditolak di backend. |
| **Estimasi Ongkir UI** | Menampilkan kartu statis *"Estimasi Ongkir (Komship) Rp25.000–Rp45.000"* yang seolah-olah berasal dari API Komship. | Kotak statis dihapus dan diganti dengan informasi jujur: *"Estimasi ongkir otomatis belum tersedia. Pengiriman dan ongkos kirim diatur sendiri oleh Donatur."* |
| **Aturan Validasi Ekspedisi** | Aturan `in:JNE,J\&T Express,SiCepat` rentan gagal parsing string untuk `J&T Express`. | Menggunakan `Rule::in(['JNE', 'J&T Express', 'SiCepat'])` dan validasi kondisional `Rule::requiredIf`. |
| **Metode Antar Langsung** | Nilai ekspedisi/resi stale dari browser bisa tersimpan ke dalam tabel `shipments`. | Diproteksi di backend: Shipment **hanya** dibuat bila `metode_pengiriman === 'dikirim_sendiri'`. Untuk `diantar_langsung`, input kurir diabaikan dan tidak dibuat Shipment. |
| **Konfirmasi Barang & Progress** | `DonasiMasukController@konfirmasiDiterima` hanya mengubah `donations.status = 'diterima'`; belum mengubah status Shipment dan belum memperbarui progress campaign. | Memakai `DB::transaction()` dengan `lockForUpdate()`, mengupdate status Donation & Shipment menjadi `'diterima'`, mengisi `tanggal_diterima = now()`, dan memanggil `CampaignProgressService::sync($campaign)`. |
| **Badge Riwayat Donatur** | Pada `donatur/riwayat.blade.php`, status `menunggu_konfirmasi` jatuh ke warna badge `danger`. | Status `menunggu_konfirmasi` dipetakan ke badge `warning` ("Menunggu Konfirmasi"). Tampil pula metode pengiriman, kurir, dan nomor resi. |

---

## 3. Daftar File yang Dibuat / Diubah

### File Baru:
1. **`database/migrations/2026_11_17_153500_add_berat_gram_to_donations_table.php`**
   - Menambahkan kolom `berat_gram` bertipe `unsignedInteger` (nullable) setelah kolom `satuan_barang`.
   - Method `down()` mendrop kolom `berat_gram` dengan pengecekan `hasColumn`.
2. **`tests/Feature/Stage1GoodsDonationTest.php`**
   - Berisi 18 feature test suite komprehensif untuk Issue #55, #57, dan #59.

### File Diubah:
1. **`app/Models/Donation.php`**
   - Menambahkan `'berat_gram'` pada properti `$fillable`.
   - Menambahkan cast `'berat_gram' => 'integer'`, `'jumlah_barang' => 'integer'`, `'is_anonim' => 'boolean'`, `'tanggal_diterima' => 'datetime'`.
2. **`app/Http/Controllers/Donatur/DonasiBarangController.php`**
   - Memfilter kategori non-makanan pada `index()`.
   - Menambahkan validasi `berat_gram`, `Rule::in` untuk kurir manual (`JNE`, `J&T Express`, `SiCepat`), serta penolakan ganda kategori makanan.
   - Mengisolasi pembuatan Shipment hanya untuk `dikirim_sendiri`.
   - Membersihkan berkas foto pada storage jika terjadi exception transaksi DB.
3. **`resources/views/donatur/donasi-barang.blade.php`**
   - Memisahkan input "Jumlah Barang" dan "Estimasi Berat Total (gram)".
   - Menghapus klaim ongkir statis Komship dan menggantinya dengan informasi transparan.
   - Memasang toggle JavaScript untuk field nomor resi dan ekspedisi.
   - Menjaga pemulihan state input menggunakan `old()`.
4. **`app/Http/Controllers/Penerima/DonasiMasukController.php`**
   - Menggunakan `DB::transaction()` dan locking baris (`Campaign` lalu `Donation`).
   - Mengupdate status `donations.status` dan `shipments.status` menjadi `diterima`.
   - Menghubungkan proses konfirmasi dengan `CampaignProgressService::sync($campaign)`.
5. **`resources/views/penerima/donasi-masuk.blade.php`**
   - Menampilkan detail estimasi berat (gram), metode pengiriman (badge), ekspedisi, resi kurir, dan status kurir.
   - Menampilkan status terkonfirmasi beserta tanggal penerimaan fisik.
6. **`resources/views/donatur/riwayat.blade.php`**
   - Memperbaiki pemetaan status `menunggu_konfirmasi` ke warna `warning`.
   - Menampilkan detail estimasi berat, metode pengiriman, dan status resi.
7. **`database/migrations/2026_01_01_000001_create_foundation_schema_for_testing.php`**
   - Menambahkan kolom `berat_gram` pada definisi skema SQLite testing agar konsisten sejak bootstrap database in-memory.

---

## 4. Kontrak Data Final

| Parameter / Entitas | Aturan Kontrak |
|---|---|
| **`donations.jenis`** | Selalu `'barang'` untuk alur ini. |
| **`donations.status`** | Status awal: `'menunggu_konfirmasi'`. Status akhir setelah konfirmasi Penerima: `'diterima'`. |
| **`donations.jumlah_barang`** | Kuantitas unit barang sesuai `campaigns.satuan_barang` (misal: pcs, paket). Menjadi dasar utama progress campaign. |
| **`donations.berat_gram`** | Estimasi berat total paket dalam satuan gram (`1 kg = 1.000 gram`). Minimal 1 gram, maksimal 1.000.000 gram (1 ton). Tidak mencampur aduk dengan kuantitas unit. |
| **`donations.tanggal_diterima`** | Bernilai `NULL` saat donasi dibuat. Diisi waktu saat ini (`now()`) **hanya** ketika Penerima mengonfirmasi penerimaan barang. |
| **`donations.metode_pengiriman`** | ENUM: `'dikirim_sendiri'` atau `'diantar_langsung'`. |
| **`shipments` (Ekspedisi)** | Dibuat tepat satu record jika `metode_pengiriman === 'dikirim_sendiri'`, dengan status awal `'dikirim'`. Kolom `estimasi_ongkir` dan `last_tracked_at` bernilai `NULL`. |
| **`shipments` (Antar Langsung)** | **Tidak ada** record `shipments` yang dibuat. |
| **Kategori Terlarang** | Kategori `makanan` (baik nama maupun slug) ditolak oleh sistem dan tidak dimunculkan pada antarmuka. |
| **Kalkulasi Progress** | Diperbarui secara terpusat melalui `CampaignProgressService::sync($campaign)`: `SUM(jumlah_barang)` hanya untuk donasi barang yang berstatus `'diterima'`. |

---

## 5. Hasil Testing Aktual

### A. Eksekusi Pest Test Runner
```bash
php artisan test
```

**Hasil:**
- **Status:** **PASS (100% Lolos)**
- **Total Tests:** **32 passed** (0 failed, 0 skipped, 0 error)
  - `tests/Feature/Stage1GoodsDonationTest.php`: **18 passed** (93 assertions)
  - `tests/Feature/Stage0FoundationTest.php`: **12 passed** (25 assertions)
  - `tests/Feature/ExampleTest.php` & `Unit/ExampleTest.php`: **2 passed** (2 assertions)
- **Total Assertions:** **120 assertions**
- **Waktu Eksekusi:** **2.28 detik**

### B. Rincian Test Cases Stage 1 (`Stage1GoodsDonationTest.php`):
1. `donatur login dapat mengakses GET form barang pada campaign aktif tipe barang` — **PASS**
2. `GET dan POST donasi barang menerima campaign tipe keduanya, namun menolak campaign tipe uang atau nonaktif` — **PASS**
3. `guest dialihkan ke login dan user penerima/admin ditolak dari route donasi barang` — **PASS**
4. `kategori makanan tidak tampil pada dropdown dan ditolak bila di-POST secara manual` — **PASS**
5. `kategori yang di-soft-delete ditolak oleh validasi backend` — **PASS**
6. `POST donasi barang yang valid menyimpan berat_gram, foto, status menunggu_konfirmasi, dan tanggal_diterima null` — **PASS**
7. `validasi menolak jumlah/berat tidak valid dan foto tidak valid tanpa menyimpan record atau file yatim` — **PASS**
8. `metode dikirim_sendiri dengan ekspedisi JNE dan resi valid membuat tepat satu Shipment berstatus dikirim` — **PASS**
9. `metode dikirim_sendiri mendukung kurir J&T Express dengan karakter ampersand` — **PASS**
10. `metode dikirim_sendiri mewajibkan ekspedisi terdaftar dan nomor resi` — **PASS**
11. `metode diantar_langsung tidak membuat record Shipment meskipun form mengirim resi/ekspedisi stale` — **PASS**
12. `penerima pemilik campaign berhasil konfirmasi barang: status donation dan shipment menjadi diterima serta progress terhitung` — **PASS**
13. `konfirmasi barang dengan metode diantar_langsung (tanpa shipment) tetap sukses dan progress terhitung` — **PASS**
14. `konfirmasi berulang (repeat request) bersifat idempotent dan tidak menambah progress ganda` — **PASS**
15. `penerima lain tidak dapat mengonfirmasi donasi milik campaign orang lain` — **PASS**
16. `donatur atau guest tidak dapat memanggil endpoint konfirmasi donasi barang` — **PASS**
17. `donasi uang tidak dapat dikonfirmasi lewat endpoint konfirmasi donasi barang` — **PASS**
18. `beberapa donasi pada campaign yang sama terakumulasi secara tepat dan terisolasi dari donasi uang` — **PASS**

### C. Verifikasi pada MySQL Testing Disposable (Port 3306)
- Database disposable `wecare_stage1_disposable_test` dibuat pada MySQL 8.0.30 lokal.
- Base schema `database/wecare.sql` diimpor, diikuti penambahan kolom `berat_gram INT UNSIGNED NULL`.
- Operasi `INSERT` donasi barang lengkap dengan `berat_gram = 2500` dan `status = 'menunggu_konfirmasi'` berhasil dieksekusi tanpa error.
- Database disposable langsung di-drop setelah verifikasi selesai; database utama `wecare` tidak disentuh.

---

## 6. Manual Smoke Test Flow

1. **Alur Donatur**:
   - Membuka halaman campaign aktif bertipe barang (`/donatur/campaign/{slug}`).
   - Mengklik tombol "Donasi Barang" -> diarahkan ke `/donatur/donasi/{slug}/barang`.
   - Mengisi nama barang, memilih kategori non-makanan, memasukkan jumlah barang (unit) dan estimasi berat (gram).
   - Mengunggah foto barang (JPG/PNG).
   - Memilih metode pengiriman:
     - Jika memilih "Dikirim sendiri", memilih kurir (JNE / J&T Express / SiCepat) dan mengisi nomor resi pengiriman.
     - Jika memilih "Diantar langsung", form resi tersembunyi secara otomatis.
   - Mengirimkan donasi -> dialihkan ke detail campaign dengan pesan sukses bahwa donasi menunggu konfirmasi Penerima.
   - Melihat daftar donasi pada menu `/donatur/riwayat` -> status tampil sebagai badge kuning ("Menunggu Konfirmasi") lengkap dengan rincian berat dan resi kurir.

2. **Alur Penerima**:
   - Login sebagai Penerima pemilik campaign dan membuka `/mitra/donasi-masuk`.
   - Melihat donasi barang yang baru masuk berstatus "Menunggu Konfirmasi".
   - Mengklik tombol "Konfirmasi Diterima" -> sistem memproses secara atomik.
   - Status donasi berubah menjadi "Terkonfirmasi" (hijau) dan tanggal penerimaan tercatat.
   - Progress barang pada campaign terkait bertambah secara presisi sesuai jumlah unit barang yang diterima.

---

## 7. Security & Regression Check

1. **Scoping & Authorization**:
   - Penerima A tidak dapat mengonfirmasi donasi milik campaign Penerima B (dibatasi oleh query ber-scope `user_id` pemilik di dalam transaksi; request ilegal menghasilkan HTTP 404 tanpa side-effect).
   - Donatur maupun Guest tidak dapat menembus middleware role pada route konfirmasi penerima (HTTP 403 / redirect 302).
2. **Kategori Makanan**:
   - Divalidasi berlapis: difilter pada dropdown view dan ditolak secara mutlak pada validasi backend controller.
3. **Pencegahan Berkas Yatim (Orphan Files)**:
   - Blok `try ... catch` pada controller memastikan file foto yang tersimpan pada `public` disk langsung dihapus via `Storage::disk('public')->delete()` apabila query transaksi database mengalami kegagalan.
4. **Idempotency & Concurrency**:
   - Konfirmasi berulang kali pada donasi yang sama tidak menyebabkan penambahan ganda (*double counting*) pada `Campaign.terkumpul_barang`. Request kedua ditolak dengan HTTP 404 karena status record sudah bukan lagi `menunggu_konfirmasi`.

---

## 8. Handoff & Risiko Tersisa

1. **Issue #56 (Estimasi Ongkir Komship API)**:
   - Form donasi barang saat ini menggunakan placeholder teks netral tanpa API eksternal. Integrasi HTTP client Komship untuk kalkulasi ongkir real-time dapat dikerjakan pada Tahap 2 dengan memanfaatkan konfigurasi `config('services.komship')` yang telah disiapkan.
2. **Issue #58 (Tracking Resi Ekspedisi Otomatis)**:
   - Kolom `shipments.last_tracked_at` saat ini tetap bernilai `NULL` karena status logistik belum diperbarui secara terjadwal. Service scheduler polling Komship perlu diimplementasikan pada tahap tracking.
3. **Koordinasi dengan Tim (Grace / Edo)**:
   - Seluruh perubahan pada form barang, konfirmasi penerimaan, dan model donasi telah dibuat modular tanpa memodifikasi tabel user atau modul di luar donasi barang. Disarankan melakukan sinkronisasi sebelum merge bersama.

---

## 9. Status File Repository

```
Modified tracked files:
- app/Http/Controllers/Donatur/DonasiBarangController.php
- app/Http/Controllers/Penerima/DonasiMasukController.php
- app/Models/Donation.php
- resources/views/donatur/donasi-barang.blade.php
- resources/views/donatur/riwayat.blade.php
- resources/views/penerima/donasi-masuk.blade.php

Untracked new files:
- database/migrations/2026_11_17_153500_add_berat_gram_to_donations_table.php
- tests/Feature/Stage1GoodsDonationTest.php
- IMPLEMENTATION_REPORT_STAGE1.md
```

*Catatan: Tidak ada git commit atau git push yang dilakukan sesuai instruksi.*
