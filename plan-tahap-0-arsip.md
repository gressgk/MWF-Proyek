# PLAN.md — Tahap 0: Fondasi Donasi WeCare (Laravel)

> **Instruksi eksekusi untuk Antigravity.** Kerjakan **Tahap 0 saja** pada repository asli **MWF-Proyek / WeCare**. Jangan melanjutkan otomatis ke issue fitur #47–#59. Tujuan tahap ini adalah membuat fondasi database, status, penghitungan progress, dan akses data konsisten sebelum integrasi Midtrans/Komship.

## 1. Konteks dan tujuan

WeCare adalah platform donasi Laravel 13 (PHP ^8.3) + Blade/Bootstrap 5 + MySQL. Role: `admin`, `penerima`, `donatur`. Bantuan dapat berupa **uang** atau **barang non-makanan**. Penerima dan campaign memerlukan persetujuan Admin. Transaksi uang nantinya menggunakan Midtrans; pengiriman barang nantinya memakai ekspedisi manual dan Komship.

**Tujuan Tahap 0:**

1. Menetapkan kontrak status donasi uang, donasi barang, dan shipment yang cocok dengan kode **dan** database.
2. Memastikan kolom progress campaign (`terkumpul_nominal`, `terkumpul_barang`) memiliki aturan penghitungan yang aman, konsisten, dan tidak double count.
3. Menentukan prosedur setup/upgrade database yang bisa direproduksi oleh semua anggota tim.
4. Memeriksa route, route-model binding, autentikasi, dan authorization yang menjadi fondasi alur donasi.
5. Menambahkan pengujian minimal dan mencatat blocker/handoff untuk issue berikutnya.

**Bukan tujuan Tahap 0:** menyelesaikan form barang (#55), konfirmasi barang secara penuh (#59), Midtrans (#47–#49), donasi anonim (#50), recurring (#51–#54), pilihan ekspedisi/Komship (#56–#58), UI baru, PDF, QR, chat, atau fitur Admin baru.

## 2. Sumber dan batasan kerja

Baca sumber di repository asli dalam urutan berikut:

1. `Dokumentasi Platform Donasi Laravel (1).pdf` (jika tersedia dalam workspace), terutama halaman 1–7, 9–12.
2. `README-mwf.md` atau README WeCare yang setara.
3. Source code Laravel asli. Jika hanya tersedia `mwf-project.md` (Repomix), **gunakan sebagai referensi baca saja**, bukan file yang diedit.
4. `issues.json` dan `my_issues.json` (jika tersedia). Tahap 0 adalah prerequisite untuk issue #27, #28, #29 dan issue spesifik #47–#59; bukan issue tersendiri.

**Prioritas sumber:** kebutuhan bisnis pada dokumentasi → kondisi nyata repository → keputusan teknis minimal yang terdokumentasi. Bila ditemukan perbedaan sumber, tulis perbedaannya dan alasan pilihan implementasi; jangan diam-diam mengganti requirement.

**Aturan wajib:**

- Gunakan **Laravel MVC + Eloquent + Blade + MySQL** yang sudah ada. Pertahankan penamaan route, relasi model (misalnya `Campaign`, `Donatur`, `Shipment`), layout, dan visual WeCare.
- Tidak melakukan redesign database atau refactor besar-besaran. Hindari perubahan pada modul yang dimiliki anggota tim lain kecuali benar-benar diperlukan untuk menutup bug fondasi; dokumentasikan handoff.
- Jangan menaruh API key, password, token, atau server key dalam kode, log, test fixture yang dikomit, maupun jawaban akhir.
- Jangan melakukan `migrate:fresh`, `db:wipe`, import SQL ke database berisi data nyata, atau `db:seed` pada database utama. Gunakan **database testing terpisah yang disposable**.
- Jangan menambahkan integrasi API/HTTP call Midtrans atau Komship, npm build, frontend framework, ataupun scheduler pada tahap ini.
- Jangan `git commit`, `git push`, membuat PR, atau mengubah issue GitHub secara otomatis tanpa instruksi pemilik project.
- Jika source repository berbeda dengan snapshot yang dijelaskan di bawah, **utamakan source terkini**, catat selisihnya dalam laporan akhir.

## 3. Temuan awal yang harus diverifikasi terlebih dahulu

Temuan ini berasal dari snapshot `mwf-project.md`; **verifikasi ulang di repository** sebelum melakukan perubahan:

| Area | Temuan spesifik | Risiko |
| --- | --- | --- |
| `database/wecare.sql` | `donations.status` = `menunggu_pembayaran`, `terverifikasi`, `diterima`, `gagal` | Tidak menerima status barang `menunggu_konfirmasi` |
| `app/Http/Controllers/Donatur/DonasiBarangController.php` | `store()` menyimpan `status = menunggu_konfirmasi` | INSERT bisa gagal pada ENUM MySQL |
| `app/Http/Controllers/Penerima/DonasiMasukController.php` | `konfirmasiDiterima()` mengubah status menjadi `terverifikasi` tetapi tidak mengisi `tanggal_diterima` atau progress | Semantik status, pelaporan, dan jumlah barang tidak sinkron |
| `database/seeders/DatabaseSeeder.php` | Donasi barang seed bisa `terverifikasi` / `diterima`, tetapi `terkumpul_barang` langsung ditambah untuk semua donasi seed | Tidak konsisten jika aturan baru hanya menghitung barang yang sudah dikonfirmasi |
| `app/Models/Campaign.php` | Ada `terkumpul_nominal`/`terkumpul_barang` dan accessor `getProgresPersenAttribute()` | Harus ada aturan tunggal kapan dan bagaimana total dihitung |
| `app/Http/Controllers/Donatur/DonasiUangController.php` | GET uang memilih campaign aktif pertama (`firstOrFail`) dan tidak memakai parameter route | Donatur dapat melihat campaign yang salah |
| `routes/web.php` | Route POST uang sudah menunjuk `DonasiUangController@store`, tetapi method tersebut belum ada | Blocker issue #48; **jangan** implement checkout pada tahap ini |
| `app/Http/Middleware/CekRole.php` | Memeriksa role, belum status verifikasi Mitra | Penerima `pending` dapat mencapai route pembuatan campaign; perlu handoff ke PIC Auth |
| `app/Http/Controllers/Penerima/DashboardController.php` | Query total donation/campaign belum di-scope ke Mitra | Statistik bisa mencampur data antar Mitra; handoff ke PIC Dashboard |
| `README-mwf.md` dan `database/migrations/2026_11_10_014137_add_document_to_campaign_reports_table.php` | README mengatakan **tanpa migration**, tetapi ada migration yang menambah kolom `dokumen_bukti` dan tabel `campaign_report_photos` | Setup database berbeda antarmesin |
| `tests/Pest.php` | `RefreshDatabase` dikomentari; database dasar berasal dari SQL | Test yang mengasumsikan seluruh tabel dibuat lewat migration dapat gagal |
| `.env.example` / `config/services.php` | Placeholder `MIDTRANS_*` dan `KOMSHIP_*` ada di `.env.example`, belum dimapping dalam `config/services.php` | Kesiapan config untuk tahap berikutnya belum rapi |

> Catatan requirement: dokumen membedakan **status pembayaran uang**, **tracking ekspedisi**, dan **konfirmasi barang fisik oleh Penerima**. Ketiganya tidak boleh digabungkan menjadi satu event.

## 4. Urutan implementasi — checklist untuk Antigravity

### T0.1 — Audit dan baseline repository

- [ ] Baca `README-mwf.md`, `database/wecare.sql`, `routes/web.php`, seluruh model/controller yang tertera pada tabel temuan.
- [ ] Jalankan `git status --short` dan catat apakah working tree sudah berisi perubahan. Jangan menimpa perubahan anggota tim.
- [ ] Periksa versi PHP/Composer, dependency Laravel, dan koneksi **database testing**, bukan database aktif.
- [ ] Inventarisasi semua penulisan/pembacaan `Donation.status`, `Shipment.status`, `tanggal_diterima`, `terkumpul_nominal`, `terkumpul_barang` (Controller, model, seeder, Blade, factory, test).
- [ ] Catat route yang belum memiliki handler (terutama `DonasiUangController@store`) sebagai blocker yang bukan scope implementasi Tahap 0.
- [ ] Buat ringkasan baseline: apa yang sudah ada, apa yang rusak, dan file apa saja yang akan disentuh.

**Output T0.1:** daftar file/temuan yang terverifikasi; jangan langsung mengedit sebelum mengetahui seluruh pemakai status terkait.

### T0.2 — Kontrak status dan alur state

- [ ] Definisikan dan dokumentasikan pemisahan tiga state berikut:

| Entitas | State yang disepakati untuk fondasi | Aturan |
| --- | --- | --- |
| `donations` jenis `uang` | `menunggu_pembayaran` → `terverifikasi` atau `gagal` | Hanya `terverifikasi` yang boleh menambah dana terkumpul; pemicunya nanti **webhook tervalidasi**, bukan halaman checkout |
| `donations` jenis `barang` | `menunggu_konfirmasi` → `diterima` | Baru `diterima` setelah Penerima mengonfirmasi barang fisik; event tracking tidak cukup |
| `shipments` | `dikirim` → `dalam_perjalanan` → `diterima` | Ini **status logistik**; `shipments.status=diterima` tidak otomatis berarti `donations.status=diterima` |

- [ ] Pastikan `tanggal_diterima` hanya diisi saat barang selesai dikonfirmasi oleh Penerima; jangan set saat form disubmit.
- [ ] Pertahankan kompatibilitas data lama yang mungkin memiliki `donations.jenis=barang` dan `status=terverifikasi`. **Jangan mass-update, hapus, atau menganggap semua legacy record sudah diterima tanpa keputusan dan bukti.** Laporkan jumlah/kondisinya dengan query read-only pada database yang diizinkan.
- [ ] Tetapkan nilai status sebagai konstanta/enum pada level aplikasi **jika memang mempermudah dan tidak memicu refactor lintas modul**. Hindari hardcode baru yang tersebar.
- [ ] Pastikan label status dalam Blade tidak bertentangan dengan kontrak. Perubahan UI dibatasi pada label/badge yang diperlukan, bukan redesign.

**Kriteria selesai T0.2:** satu tabel state final terdokumentasi; tidak ada nilai status baru di Controller yang tidak diterima database, dan tidak ada penambahan total saat status masih pending.

### T0.3 — Selaraskan database dengan kode tanpa merusak data

**Keputusan operasional minimal untuk Tahap 0:** gunakan `database/wecare.sql` sebagai **base schema** sesuai README, dan **Laravel migration sebagai perubahan inkremental** untuk instalasi yang sudah ada. Karena saat ini sudah ada migration laporan, jangan menghapus atau menulis ulang migration lama secara sembarangan.

- [ ] Audit seluruh migration dan kolom/tabel pada `wecare.sql` (termasuk `campaign_report_photos`, `dokumen_bukti`, `migrations`). Pastikan tidak ada penambahan ganda saat setup baru.
- [ ] Buat migration baru yang **additive/non-destructive** untuk memperbolehkan `menunggu_konfirmasi` pada ENUM `donations.status` apabila benar belum tersedia di schema saat ini. Pastikan DB driver MySQL, data existing, dan opsi rollback aman diperhitungkan.
- [ ] **Jangan menulis migration `down()` yang dapat menghilangkan nilai ENUM yang sedang digunakan tanpa validasi.** Jika rollback tidak aman, dokumentasikan batasannya dan gunakan prosedur pemulihan yang jelas.
- [ ] Putuskan dan dokumentasikan urutan setup disposable DB yang konsisten: **import `database/wecare.sql` → jalankan migration inkremental → seeder bila diperlukan**. Untuk DB existing, cukup jalankan migration baru; tidak re-import base SQL.
- [ ] Update README/README-mwf yang benar-benar digunakan tim (atau siapkan usulan perubahan bila file dikelola anggota lain) untuk menjelaskan bahwa project memakai **SQL base + migration inkremental**, bukan “tidak memakai migration” secara absolut.
- [ ] Uji setup dari nol menggunakan **database MySQL khusus testing**. Jangan menggunakan DB `wecare` yang menyimpan data tim.

**Kriteria selesai T0.3:** database testing bisa disiapkan ulang sesuai instruksi; kolom/laporan yang sudah ada tidak terduplikasi; INSERT dengan status pending barang yang benar tidak gagal karena ENUM.

### T0.4 — Fondasi penghitungan progress campaign

- [ ] Jadikan aturan penghitungan berikut sebagai **kontrak sumber data**:
  - `terkumpul_nominal` = jumlah `donations.nominal` milik campaign yang `jenis=uang`, `status=terverifikasi`, dan tidak di-soft-delete.
  - `terkumpul_barang` = jumlah `donations.jumlah_barang` milik campaign yang `jenis=barang`, `status=diterima`, dan tidak di-soft-delete.
  - Donasi pending/gagal dan barang yang baru dikirim **tidak ikut dihitung**.
- [ ] Rancang/implementasikan helper atau service kecil yang bisa dipakai ulang untuk **recalculate/synchronize** kedua total berdasarkan record Donation, alih-alih menambah nilai secara bebas dari banyak Controller. Tempatkan misalnya di `app/Services/` sesuai konvensi repository.
- [ ] Gunakan DB transaction dan strategi concurrency yang tepat untuk pemanggilan ulang; pastikan perhitungan idempotent. Jika menggunakan cache total di kolom Campaign, dokumentasikan bahwa tabel `donations` adalah sumber kebenaran.
- [ ] Jangan memasang webhook atau mengimplementasikan handler #59 secara penuh sekarang. Cukup sediakan mekanisme reusable dan test-nya untuk dipanggil pada Tahap 1/2.
- [ ] Audit seeder agar konsisten dengan aturan baru. Untuk **data dummy**, boleh perbaiki status dan total seed sehingga hasil fresh seed tidak menyesatkan. Jangan mengubah data nyata secara otomatis; catat kebutuhan rekonsiliasi legacy.
- [ ] Audit accessor progress di `Campaign.php`: target nominal dan target barang adalah ukuran berbeda. Jangan menjumlahkan unit barang dengan rupiah. Jika campaign `keduanya`, pertahankan perilaku saat ini atau dokumentasikan keterbatasannya untuk issue detail campaign (#45), jangan membuat formula gabungan arbitrer.

**Kriteria selesai T0.4:** pemanggilan sinkronisasi berulang menghasilkan nilai sama; donasi pending tidak dihitung; Donasi milik campaign A tidak memengaruhi campaign B; jumlah rupiah/barang tidak tercampur.

### T0.5 — Audit route dan authorization yang memblokir fondasi donasi

- [ ] Periksa route-model binding pada `donasi/{campaign:slug}/uang` dan `donasi/{campaign:slug}/barang`.
- [ ] Terapkan perbaikan kecil pada **GET donasi uang** bila masih memilih campaign pertama: gunakan `index(Campaign $campaign)` sesuai slug pada URL; batasi ke campaign `aktif` yang menerima `uang`/`keduanya`. **Jangan buat POST checkout Midtrans dalam tahap ini.**
- [ ] Pertahankan middleware role pada `/donatur`, `/mitra`, dan `/admin`. Pastikan Guest/role lain tidak bisa memakai halaman dan aksi donasi yang tidak sesuai.
- [ ] Pastikan query/action penerimaan barang selalu memeriksa **kepemilikan campaign oleh Penerima**; cek penggunaan `CampaignPolicy`/`DonationPolicy` atau scoping langsung. Jangan membuka akses lintas Mitra.
- [ ] **Audit dan catat untuk handoff**: `CekRole` belum menolak Mitra `pending/rejected` yang mencoba membuat campaign (PIC Auth/Grace); Dashboard Penerima masih membaca statistik global (PIC Dashboard/Jefferson); Settings Donatur belum scope donasi rutin (PIC Settings/Grace).
- [ ] Jika memperbaiki file milik anggota lain diperlukan untuk menutup celah keamanan yang langsung menghalangi Tahap 0, lakukan perubahan sekecil mungkin dan cantumkan alasannya dalam laporan. Hindari memperluas pekerjaan ke modul mereka.

**Kriteria selesai T0.5:** GET donasi uang selalu menggunakan campaign pada URL; campaign tidak aktif/tipe salah ditolak; tidak ada akses lintas role atau lintas pemilik dalam jalur yang disentuh.

### T0.6 — Kesiapan konfigurasi integrasi (tanpa memanggil API)

- [ ] Verifikasi placeholder berikut ada di `.env.example`: `MIDTRANS_SERVER_KEY`, `MIDTRANS_CLIENT_KEY`, `MIDTRANS_IS_PRODUCTION`, `KOMSHIP_API_KEY`, `KOMSHIP_BASE_URL`.
- [ ] Jika belum ada, tambahkan mapping terpusat yang aman di `config/services.php` (`midtrans` dan `komship`) menggunakan `env()` **hanya di file config**. Tidak perlu membuat API client atau memasang package pada tahap ini.
- [ ] Pastikan nilai rahasia tidak pernah dikirim ke Blade, di-log, di-hardcode, atau di-commit dalam `.env`.
- [ ] Catat hal yang masih harus diverifikasi pada Tahap 2/3, misalnya kontrak API provider dan akses sandbox; jangan menganggap endpoint API valid hanya karena ada placeholder URL.

**Kriteria selesai T0.6:** konfigurasi siap dipakai lewat `config('services...')` tanpa mengungkap credential dan tanpa request API eksternal.

### T0.7 — Testing dan verifikasi regresi

- [ ] Siapkan **database MySQL disposable**, misalnya `wecare_stage0_test`, dengan kredensial terpisah dari database utama. Pastikan test runner tidak diam-diam memakai `.env` production/development.
- [ ] Karena base schema proyek memakai file SQL dan `tests/Pest.php` tidak mengaktifkan `RefreshDatabase`, **jangan otomatis menyalakan `RefreshDatabase` untuk seluruh project**. Buat bootstrap test yang sesuai dengan strategi SQL base + migration, atau gunakan database test yang sudah disiapkan.
- [ ] Tambahkan Pest Feature/Unit test minimal untuk:
  1. Skema menerima status `menunggu_konfirmasi` untuk donasi barang.
  2. Donasi uang pending/gagal tidak menambah `terkumpul_nominal`.
  3. Donasi uang terverifikasi dihitung tepat sekali walaupun proses sync dipanggil ulang.
  4. Barang pending tidak menambah `terkumpul_barang`; barang `diterima` baru dihitung.
  5. Campaign A dan B tetap terisolasi saat penghitungan progress.
  6. GET donasi uang memakai campaign sesuai slug dan menolak campaign nonaktif/tipe tidak sesuai.
  7. User dengan role yang salah ditolak, dan aksi Penerima untuk donation campaign orang lain tidak diizinkan (pada jalur yang tersedia).
- [ ] Jalankan test terkait dan test keseluruhan **hanya pada environment aman**; laporkan PASS/FAIL/SKIPPED dan alasan blocker. Jangan mengklaim lolos jika belum dijalankan.
- [ ] Periksa route list dan error server (tanpa mengekspos kredensial).

Perintah inspeksi yang aman (sesuaikan dengan lingkungan):

```bash
git status --short
php -v
composer --version
php artisan about
php artisan route:list --path=donatur
php artisan route:list --path=mitra
php artisan test
```

> **Penting:** `php artisan test` hanya dijalankan setelah konfigurasi test benar-benar menunjuk ke database disposable dan lifecycle database aman. Jangan menjalankan import/migration destruktif pada database utama.

## 5. Urutan commit logis (tanpa auto-commit)

Kerjakan dan review perubahan dalam batch kecil:

1. **Batch A — State & schema:** kontrak status, migration non-destruktif, dokumentasi strategi database.
2. **Batch B — Progress foundation:** service/helper penghitungan, perbaikan seed **khusus dummy** bila diperlukan.
3. **Batch C — Route/auth minimal:** GET donasi uang berdasarkan slug, audit ownership/security, handoff ke PIC lain.
4. **Batch D — Tests & docs:** test fondasi, pembuktian setup bersih, dan ringkasan risiko tersisa.

Tidak perlu membuat commit nyata kecuali diminta. Bila conflict dengan pekerjaan anggota tim, berhenti mengedit file yang konflik dan catat solusi/handoff, bukan memaksa overwrite.

## 6. Definition of Done — Tahap 0

Tahap 0 **selesai** apabila seluruh syarat berikut terpenuhi:

- [ ] Status yang ditulis Controller valid menurut schema database (terutama `menunggu_konfirmasi`).
- [ ] Kontrak uang/barang/shipment dan aturan `tanggal_diterima` terdokumentasi.
- [ ] Strategi **base SQL + migration inkremental** bisa direproduksi pada DB test kosong; README sesuai implementasi.
- [ ] Tersedia mekanisme sinkronisasi total campaign yang idempotent dan teruji untuk uang serta barang.
- [ ] Status pending/gagal tidak dihitung; nominal rupiah dan jumlah barang terpisah.
- [ ] GET donasi uang tidak memilih campaign yang salah; akses role/campaign tervalidasi pada jalur yang disentuh.
- [ ] Konfigurasi Midtrans dan Komship memiliki tempat aman (tanpa implementasi API di tahap ini).
- [ ] Automated tests fondasi dijalankan dan hasilnya dilaporkan secara jujur.
- [ ] Semua bug/dependency di luar scope ditulis sebagai handoff, bukan dikerjakan diam-diam.
- [ ] **Belum ada implementasi** checkout Midtrans, webhook, recurring, Komship, atau form barang baru.

## 7. Format laporan akhir yang WAJIB diberikan Antigravity

Setelah selesai, jawab dalam Bahasa Indonesia dengan format berikut:

1. **Ringkasan:** apa yang telah dikerjakan pada Tahap 0.
2. **Perubahan file:** daftar path file, alasan perubahan, dan dampaknya.
3. **Keputusan teknis:** kontrak status final; strategi database; formula progress; perilaku legacy.
4. **Testing:** perintah yang dijalankan, PASS/FAIL/SKIPPED, dan bukti singkat hasil.
5. **Temuan di luar scope:** blocker yang harus di-handoff kepada Grace, Anastasia, atau Jefferson.
6. **Risiko tersisa:** terutama data legacy, migration/rollback, race condition, atau asumsi yang belum terverifikasi.
7. **Kesiapan Tahap 1:** apakah #55, #57, #59 siap dikerjakan; sebutkan prerequisite yang belum beres.

**Jangan menyatakan “selesai” apabila masih ada kriteria DoD yang gagal.** Jika lingkungan tidak memungkinkan menjalankan database atau test, selesaikan analisis dan perubahan aman yang bisa dibuat, lalu tandai hasil sebagai **belum terverifikasi**, bukan lulus.

---

**Batas akhir eksekusi:** STOP setelah Tahap 0. Jangan melanjutkan mengerjakan issue #47–#59 tanpa instruksi berikutnya.
