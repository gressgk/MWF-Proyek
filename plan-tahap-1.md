# PLAN.md — Tahap 1: Donasi Barang Dasar WeCare (Laravel)

> **Instruksi kerja untuk Antigravity — implementasikan HANYA Tahap 1.**
> Repository: **MWF-Proyek / WeCare**. Issue yang ditargetkan: **[#55 Form Donasi Barang](https://github.com/gressgk/MWF-Proyek/issues/55)**, **[#57 Pilihan Ekspedisi](https://github.com/gressgk/MWF-Proyek/issues/57)**, dan **[#59 Konfirmasi Manual Penerimaan Barang](https://github.com/gressgk/MWF-Proyek/issues/59)**. Parent issue **[#29 Donasi Barang](https://github.com/gressgk/MWF-Proyek/issues/29)** adalah milestone dan **belum** dinyatakan selesai sebelum integrasi Komship pada tahap berikutnya.
>
> **Mulai dengan audit repository asli, bukan langsung menulis ulang kode.** Gunakan rencana ini sebagai checklist eksekusi. Jika implementasi nyata sudah memenuhi sebuah item, cukup verifikasi dan tulis buktinya. Jangan mengerjakan Tahap 2/3/4 atau issue lain.

## 0. Konteks, baseline, dan target

WeCare memakai **Laravel 13, PHP ^8.3, Blade, Bootstrap 5, Eloquent, dan MySQL**. Terdapat tiga role: `admin`, `penerima`, `donatur`. Platform mengizinkan bantuan berupa `uang`, `barang`, atau `keduanya`. Donasi barang **tidak menerima makanan**. Donatur mengatur sendiri pengiriman melalui ekspedisi manual (misalnya JNE/J&T) dengan nomor resi, atau mengantar langsung. Penerima wajib mengonfirmasi bahwa barang benar-benar diterima.

**Baseline yang dilaporkan telah selesai pada Tahap 0:**

- Kontrak status Donation: uang `menunggu_pembayaran → terverifikasi/gagal`; barang `menunggu_konfirmasi → diterima`.
- Kontrak status Shipment: `dikirim → dalam_perjalanan → diterima`. Status Shipment tidak boleh otomatis mengonfirmasi Donation tanpa tindakan Penerima.
- `CampaignProgressService::sync()` sudah tersedia. `terkumpul_barang` dihitung dari `SUM(jumlah_barang)` untuk Donation `jenis='barang'`, `status='diterima'`, bukan dari barang yang baru dikirim.
- Migration memperbolehkan `menunggu_konfirmasi` pada `donations.status` MySQL/MariaDB.
- Route GET/POST donasi barang serta route PUT konfirmasi sudah tersedia; pengujian Tahap 0 terakhir **14 passed, 27 assertions** menurut hasil yang dibagikan pemilik proyek.
- Base database berasal dari `database/wecare.sql` dan upgrade inkremental memakai `php artisan migrate`.

**Target yang harus berfungsi dari ujung ke ujung di Tahap 1:**

1. Donatur memilih campaign aktif yang menerima barang, mengisi **nama, kategori non-makanan, jumlah, estimasi berat, foto, catatan opsional, anonim opsional**, dan metode pengiriman.
2. **Dikirim sendiri:** Donatur mengisi ekspedisi + nomor resi. Donation berstatus `menunggu_konfirmasi` dan memiliki satu Shipment awal `dikirim`.
3. **Diantar langsung:** Donatur tidak diwajibkan mengisi ekspedisi/resi, dan Donation tersimpan tanpa Shipment ekspedisi palsu.
4. Penerima hanya melihat donasi campaign miliknya dan dapat mengonfirmasi penerimaan fisik. Donation berubah ke `diterima`, `tanggal_diterima` diisi, Shipment terkait (jika ada) berubah ke `diterima`, dan `CampaignProgressService::sync()` menghitung progress **tepat satu kali**, termasuk bila request diulang.
5. Error validasi, unauthorized access, database failure, dan upload failure ditangani dengan aman, tanpa file yatim atau data setengah tersimpan.
6. Seluruh automated tests lama tetap lulus, ditambah tests Tahap 1.

## 1. Sumber dan aturan eksekusi

### Urutan baca WAJIB

1. Baca `Dokumentasi Platform Donasi Laravel (1).pdf` (jika ada di workspace), terutama bagian halaman Donatur **Form Donasi Barang**, halaman Penerima **Donasi Masuk**, dan section **6.2 Donasi Barang**. Jika PDF tidak tersedia, gunakan README dan issue yang tersedia serta catat keterbatasan referensi.
2. Baca `README-mwf.md`, lalu `plan.md` ini.
3. Baca source code TERKINI secara langsung, minimal:
   - `routes/web.php`
   - `app/Http/Controllers/Donatur/DonasiBarangController.php`
   - `app/Http/Controllers/Penerima/DonasiMasukController.php`
   - `app/Models/Donation.php`, `Shipment.php`, `Campaign.php`, `ItemCategory.php`
   - `app/Services/CampaignProgressService.php`
   - `resources/views/donatur/donasi-barang.blade.php`
   - `resources/views/penerima/donasi-masuk.blade.php`
   - `resources/views/donatur/riwayat.blade.php`
   - `database/wecare.sql`, semua migration yang relevan, `tests/Feature/Stage0FoundationTest.php`, `phpunit.xml`.
4. Jika diperlukan, baca `issues.json`/`my_issues.json` untuk memastikan judul dan assign. **Jangan edit file Repomix** (`mwf-project.md` atau `tahap-0-1-repomix.xml`); keduanya hanyalah snapshot.

### Batasan WAJIB

- **Scope ketat:** hanya #55, #57, #59 dan penyesuaian schema/test/view yang diperlukan untuk ketiganya. **Jangan** mengimplementasikan Komship API/estimasi ongkir sungguhan (#56), scheduled polling resi (#58), Midtrans (#47–#49), recurring (#51–#54), chat, PDF, QR code, atau fitur Admin.
- Jangan redesign UI. Pertahankan layout Blade, Bootstrap, CSS/mascot WeCare, penamaan route yang sudah ada, dan konvensi relasi Eloquent (`Campaign`, `Shipment`, dll.). Tambahkan hanya field dan status yang diperlukan.
- Jangan menambah dependensi eksternal, framework frontend baru, atau integrasi HTTP/API eksternal. JavaScript kecil untuk conditional visibility form boleh bila perlu; **validasi server adalah sumber kebenaran**.
- Jangan mengubah modul Auth/Settings/Chat/dashboard anggota lain tanpa kebutuhan langsung. Jika ada bug di luar scope, tulis pada bagian **Handoff** dalam laporan.
- **Jangan** menjalankan `migrate:fresh`, `db:wipe`, `db:seed` ulang, `migrate:rollback`, atau mengimpor ulang `wecare.sql` ke database development/production yang menyimpan data. Migration baru harus additive/non-destructive dan diuji pada database disposable.
- Jangan menyimpan data sensitif, API key, password, token, atau dokumen identitas pada report / screenshot / commit. Jangan `git commit`, `git push`, atau menutup issue GitHub otomatis.
- Jangan mengklaim integrasi Komship aktif, ongkir realtime, atau tracking otomatis bila implementasi belum ada. **Hapus/ubah angka estimasi ongkir statis** di UI agar tidak menyesatkan.
- Tidak perlu meminta konfirmasi pemilik project untuk pekerjaan yang sudah jelas di plan ini. Jika ada ketidakcocokan code dengan baseline, sesuaikan secara minimal dan catat alasan teknisnya.

## 2. Audit kondisi aktual sebelum coding — T1.0

Sebelum menyentuh file, inspeksi dan catat apakah temuan snapshot berikut masih berlaku:

| Lokasi | Kondisi baseline yang perlu diverifikasi |
|---|---|
| `DonasiBarangController@index()` | Sudah membatasi campaign ke `status='aktif'` dan tipe `barang/keduanya`, tetapi daftar kategori masih `ItemCategory::all()` sehingga kategori makanan dapat muncul jika dibuat Admin. |
| `DonasiBarangController@store()` | Sudah validasi, upload foto, `DB::transaction()`, create Donation + Shipment, rollback storage bila DB gagal. Namun **belum ada field berat tersendiri**, dan aturan `in:JNE,J\&T Express,SiCepat` perlu diperiksa kompatibilitas nilainya. |
| `donasi-barang.blade.php` | Input `jumlah_barang` masih berlabel "Jumlah / Berat Estimasi"; berat belum disimpan terpisah. Ada kotak **"Estimasi Ongkir (Komship) Rp25.000–Rp45.000" statis**, yang seolah-olah nilai dari API. Toggle ekspedisi mungkin tidak bekerja konsisten saat ganti radio tanpa validasi. |
| `DonasiMasukController@konfirmasiDiterima()` | Sudah cek pemilik campaign dan set Donation `diterima` + `tanggal_diterima`; **belum** update Shipment serta sync progress. |
| `donasi-masuk.blade.php` | Tombol konfirmasi sudah ada untuk status `menunggu_konfirmasi`; detail berat/metode pengiriman belum tampil lengkap. |
| `riwayat.blade.php` | Status `menunggu_konfirmasi` jatuh ke badge bahaya/default walaupun status tersebut normal saat menunggu konfirmasi. |
| `database/wecare.sql` + migration | Donation belum memiliki kolom khusus berat; perubahan schema harus melalui migration baru, bukan import ulang SQL. |

**Output T1.0:** ringkasan audit singkat dalam laporan akhir, daftar file yang benar-benar perlu diubah, dan keputusan nama field berat.

## 3. Kontrak data/status yang TIDAK BOLEH dilanggar

| Konsep | Aturan |
|---|---|
| `Donation.jenis` | Selalu `barang` untuk alur ini. Tidak boleh menulis ke alur uang/Midtrans. |
| Status awal Donation barang | `menunggu_konfirmasi`, baik ekspedisi maupun antar langsung. |
| Status final Donation barang | `diterima` **hanya setelah** Penerima yang berhak melakukan konfirmasi. |
| `tanggal_diterima` | `NULL` ketika baru didaftarkan; set satu kali saat konfirmasi sukses. Request ulang tidak mengubah timestamp sebelumnya. |
| Status awal Shipment (ekspedisi) | `dikirim`; `last_tracked_at` tetap `NULL` karena belum ada polling Komship. |
| Status final Shipment | `diterima` setelah konfirmasi oleh Penerima; tidak boleh mengubah Donation hanya karena polling/logistik berubah. |
| Shipment untuk antar langsung | Tidak diwajibkan dan tidak membuat Shipment ekspedisi palsu. |
| `terkumpul_barang` | SUM(`jumlah_barang`) Donation yang `jenis='barang'`, `status='diterima'`, bukan soft-deleted. Gunakan `CampaignProgressService`; jangan gunakan increment manual. |
| Berat vs jumlah | **Dua field berbeda**. `jumlah_barang` mengikuti `satuan_barang` campaign (`pcs`/`paket`/dll.); berat adalah estimasi berat **total** barang/paket dalam gram, tidak digunakan untuk mengisi progress barang. |
| Kategori | Kategori non-makanan aktif saja. Pilihan terlarang tidak boleh lolos dengan request yang dibuat manual (bukan sekadar tersembunyi di UI). |
| Campaign | Hanya `aktif` dan `tipe_bantuan` dalam `barang`, `keduanya` yang menerima request baru. |
| Authorization | Donatur tidak boleh konfirmasi. Penerima hanya boleh konfirmasi Donation dari campaign yang `user_id`-nya sendiri. Akses invalid harus gagal tanpa side effect. |

**Keputusan implementasi mengenai berat:** Gunakan satu kolom baru `donations.berat_gram` bertipe **unsigned integer, nullable di database untuk menjaga data lama**. Semua submit Donation barang **baru** harus menyediakan `berat_gram` positif (dalam gram, estimasi berat total paket). Jangan menimpa `jumlah_barang` atau mengubah `target_barang` karena progress campaign tetap berbasis jumlah. Jika code terkini sudah memiliki field berat setara, gunakan field itu alih-alih membuat duplikat, lalu dokumentasikan pemetaan dan pilihan unitnya.

## 4. Urutan implementasi (kerjakan berurutan)

### T1.1 — Database dan model berat estimasi (prasyarat #55/#56 nanti)

**Tindakan:**

- [ ] Buat **migration inkremental baru** untuk menambahkan `berat_gram` pada tabel `donations` secara aman, misalnya `unsignedInteger('berat_gram')->nullable()`; jangan edit import SQL lama secara destruktif. `down()` hanya menghapus kolom yang ditambah migration ini dan beri catatan risiko bila ada data nyata.
- [ ] Pastikan migration dijalankan setelah foundation SQLite testing pada environment testing dan setelah import base SQL pada MySQL. Jangan mengasumsikan `migrate:fresh` aman di database developer.
- [ ] Tambahkan `berat_gram` ke `Donation::$fillable` (dan cast integer bila sesuai pola existing). Jangan mengubah field alur uang/recurring.
- [ ] Verifikasi skema MySQL baru dan SQLite test dapat menyimpan data baru; data lama tanpa berat tetap dapat dibaca dan nilainya `NULL` tanpa error.
- [ ] Pertahankan `shipment.estimasi_ongkir` nullable; **jangan** menulis tarif ongkir palsu.

**Definition of Done T1.1:** Kolom baru tersedia tanpa data loss, data legacy tetap terbaca, dan migration bisa dijalankan pada DB testing yang terisolasi.

### T1.2 — Issue #55: Form Donasi Barang lengkap & validasi backend

**Tindakan backend:**

- [ ] Pertahankan GET/POST route yang sudah ada: `GET/POST /donatur/donasi/{campaign:slug}/barang` dengan nama route GET `donatur.donasi.barang`; jangan mengubah kontrak URL.
- [ ] Pada `index()`, tampilkan **hanya** kategori non-makanan yang aktif/non-soft-deleted. Jika kategori `makanan` ada di DB, kategorinya tidak muncul di dropdown.
- [ ] Pada `store()`, validasi secara server-side: nama barang tidak kosong; kategori ada dan bukan makanan serta tidak soft-deleted; jumlah integer minimal 1; `berat_gram` integer positif dengan batas atas wajar yang didokumentasikan; foto gambar (`jpg/jpeg/png/webp`, batas ukuran sesuai baseline atau ditetapkan eksplisit); catatan opsional; `is_anonim` boolean opsional; metode pengiriman valid.
- [ ] `is_anonim` yang tidak dicentang menjadi `false`, sedangkan yang dicentang menjadi `true` dan identitas Donatur **tetap disimpan** di `donations.user_id`.
- [ ] Pastikan campaign yang divalidasi adalah campaign yang terikat pada slug URL, aktif dan menerima `barang/keduanya`. Tidak boleh pindah ke campaign pertama secara global.
- [ ] Simpan satu Donation berstatus `menunggu_konfirmasi`, `tanggal_diterima=NULL`, dan berat serta jumlah yang benar. Jangan menambahkan `terkumpul_barang` saat create.
- [ ] Pertahankan pola upload foto di disk `public` + transaksi database. Jika salah satu operasi gagal, jangan tinggalkan Donation/Shipment setengah tersimpan atau file foto tanpa referensi. Gunakan cleanup storage pada exception; jangan bocorkan stack trace kepada pengguna akhir.
- [ ] Rapikan flash message sukses agar jelas bahwa **data donasi tercatat dan menunggu konfirmasi Penerima**, bukan barang fisik sudah diterima.

**Tindakan Blade/UI:**

- [ ] Tetap pakai visual `layout.donatur` dan komponen Bootstrap existing; tampilkan nama campaign tujuan.
- [ ] Pisahkan input **"Jumlah barang"** (dengan satuan campaign) dari input **"Estimasi berat total (gram)"** (`name="berat_gram"`). Jangan menampilkan seolah jumlah = berat.
- [ ] Field foto, catatan, kategori, anonim, dan metode pengiriman tetap tersedia. Label, error per field, `old()` untuk validasi gagal, dan petunjuk unit diperjelas. Jangan membuang input yang sudah dibuat.
- [ ] Jika kategori kosong, berikan pesan dan jangan membiarkan submission memilih kategori tidak valid.
- [ ] **Hapus angka ongkir statis** `Rp25.000–Rp45.000` dan klaim "estimasi ongkir otomatis". Ganti dengan teks jujur semisal: *"Estimasi ongkir otomatis belum tersedia. Pengiriman dan ongkos kirim diatur sendiri oleh Donatur."* Ini placeholder yang akan diganti pada #56, **tanpa panggilan API**.

**Definition of Done #55:** Donatur bisa mengirim formulir valid, Donation baru tersimpan lengkap termasuk `berat_gram` dan foto, makanan tertolak pada UI maupun POST buatan manual, dan data invalid tidak menghasilkan record/file tersisa.

### T1.3 — Issue #57: Pilihan ekspedisi manual & Shipment

**Tindakan:**

- [ ] Pertahankan nilai metode existing: `dikirim_sendiri` dan `diantar_langsung`.
- [ ] Untuk `dikirim_sendiri`, `ekspedisi` **wajib** salah satu pilihan yang benar-benar didukung form saat ini (JNE, `J&T Express`, SiCepat) dan `no_resi` **wajib** non-kosong. Normalisasi whitespace resi bila perlu tanpa mengubah kode resi valid. Gunakan rule yang aman untuk nilai mengandung `&` (contoh `Rule::in([...])`), bukan string escaped yang berpotensi tidak cocok.
- [ ] Untuk `diantar_langsung`, ekspedisi dan resi tidak diperlukan dan **tidak disimpan** meski browser mengirim field tersembunyi/tertinggal dari pilihan sebelumnya. Gunakan validasi kondisional (`required_if`, `exclude_unless`, atau `Rule`) agar input milik metode lain tidak berpengaruh.
- [ ] Ketika `dikirim_sendiri`, buat **tepat satu Shipment** melalui relation `$donation->Shipment()` (`donation_id` sudah unique) dengan ekspedisi/resi dari request dan status `dikirim`. `estimasi_ongkir` serta `last_tracked_at` tetap `NULL`.
- [ ] Ketika `diantar_langsung`, jangan membuat Shipment untuk kurir yang tidak ada. Detail metode terlihat di halaman Penerima dan riwayat Donatur.
- [ ] UI menampilkan field ekspedisi dan resi hanya ketika metode dikirim sendiri. Gunakan mekanisme Bootstrap/minimal JavaScript jika diperlukan, tetapi **jangan** mengandalkan UI untuk keamanan: POST server tetap memvalidasi. Pastikan radio dapat berpindah dua arah dan state validasi lama dipulihkan.
- [ ] Pada halaman `penerima/donasi-masuk` dan `donatur/riwayat`, tampilkan metode pengiriman dan resi (untuk ekspedisi) secara jelas. Jangan menampilkan resi pada halaman campaign publik; hormati authorization.
- [ ] Jangan panggil Komship, jangan menambahkan status tracking otomatis, dan jangan menghitung ongkir sekarang.

**Definition of Done #57:** Dikirim sendiri → Shipment valid dengan resi; diantar langsung → tanpa Shipment; resi/kategori/ekspedisi invalid tertolak; riwayat menunjukkan metode dan status yang benar.

### T1.4 — Issue #59: Konfirmasi manual Penerima + progress idempotent

**Tindakan:**

- [ ] Pertahankan route existing `PUT /mitra/donasi-masuk/{donation}/konfirmasi` di group `CekRole:penerima` dan tombol di halaman Penerima.
- [ ] Hanya Donation dengan `jenis='barang'`, `status='menunggu_konfirmasi'`, dan campaign milik **Penerima login** yang boleh dikonfirmasi. User lain, role lain, record tidak ada, atau donasi uang harus tertolak tanpa perubahan.
- [ ] Gunakan **satu `DB::transaction()`** untuk keseluruhan perubahan. Cegah race condition dengan locking yang konsisten (`lockForUpdate()` pada Campaign dan/atau Donation sesuai urutan yang aman), re-check kepemilikan dan status di dalam transaksi, lalu ubah Donation menjadi `diterima` + set `tanggal_diterima=now()` **hanya jika sebelumnya pending**.
- [ ] Jika ada Shipment dari ekspedisi, set `shipments.status='diterima'` sebagai konfirmasi fisik oleh Penerima. Jangan mengisi `last_tracked_at` secara palsu karena kolom itu milik polling resi Komship pada tahap berikutnya. Jika antar langsung dan tidak ada Shipment, tetap bisa sukses.
- [ ] Integrasikan `CampaignProgressService::sync($campaign)` pada proses konfirmasi sehingga `Campaign.terkumpul_barang` mencerminkan total barang diterima. Jangan gunakan `$campaign->increment('terkumpul_barang')`; pastikan perubahan status dan hitung progress konsisten secara transaksional.
- [ ] Permintaan konfirmasi ulang tidak boleh menambah progress, mengubah `tanggal_diterima` sebelumnya, atau membuat Shipment duplikat. Boleh mempertahankan perilaku `404` pada repeat sesuai filter status existing, asalkan tidak ada side effect; dokumentasikan keputusan respons.
- [ ] Jika proses update Shipment / hitung progress gagal, rollback seluruh perubahan Donation dalam transaksi.
- [ ] Perjelas tampilan: pending → tombol konfirmasi; diterima → badge sukses dan tanggal penerimaan; status tracking/Shipment ditampilkan terpisah dari status Donation.
- [ ] Jangan menandai Donation otomatis diterima berdasarkan `Shipment.status`. Polling Komship (#58) belum diimplementasikan.

**Catatan concurrency:** `CampaignProgressService::sync()` saat ini membuat transaksi internal dan melakukan `Campaign::lockForUpdate()`. Jika dipanggil dari transaksi konfirmasi, Laravel memakai transaksi nested pada koneksi yang sama. Evaluasi **urutan lock Campaign lalu Donation** agar aman terhadap dua konfirmasi paralel; jangan mengganti service tanpa alasan. Buktikan idempotency melalui test serial dan evaluasi skenario concurrent secara masuk akal; jangan mengklaim sudah menguji parallel race bila belum melakukannya.

**Definition of Done #59:** Pemilik sah bisa mengonfirmasi sekali; Donation, tanggal, Shipment, dan progress tersinkron; pengulangan serta akses ilegal tidak menyebabkan double count atau data bocor.

### T1.5 — Integrasi tampilan dan regression check

- [ ] Cek flow dari Donatur: halaman campaign aktif → form barang → submit → riwayat Donatur.
- [ ] Cek flow dari Penerima: halaman Donasi Masuk → melihat donasi pending → konfirmasi → status dan progres campaign berubah.
- [ ] Pastikan form dan Blade tidak me-render field yang belum tersedia pada database lama; tulis langkah `php artisan migrate` sebelum menjalankan aplikasi.
- [ ] Pada `donatur/riwayat.blade.php`, perlakukan `menunggu_konfirmasi` sebagai status **pending normal** (mis. badge warning), bukan `danger`.
- [ ] Periksa `Campaign::progres_persen` pada campaign barang murni: kenaikan mengikuti jumlah yang telah diterima. Untuk campaign `keduanya`, jangan diam-diam mendesain ulang progress uang/barang; catat kebutuhan UI dual-progress ke anggota modul campaign jika belum tersedia.
- [ ] Lakukan smoke test tampilan dengan account role berbeda; tidak perlu redesign halaman atau menambah endpoint baru.

## 5. Pengujian wajib — T1.6

Gunakan **Pest / Laravel Feature Test** sesuai pola `tests/Feature/Stage0FoundationTest.php`. Buat file test khusus Tahap 1 (misalnya `tests/Feature/Stage1GoodsDonationTest.php`) dan pertahankan seluruh test lama. Gunakan `RefreshDatabase` pada SQLite `:memory:` yang sudah tersedia, `Storage::fake('public')`, dan `UploadedFile::fake()->image(...)`. Jika ada kendala berbeda pada MySQL, verifikasi di DB disposable tersendiri.

### Minimal test cases

**Form, campaign, dan kategori (#55)**

- [ ] Donatur login GET form barang campaign aktif `barang` → HTTP 200; campaign slug benar.
- [ ] Campaign `keduanya` diterima; campaign `uang` atau nonaktif ditolak (404) baik untuk GET maupun POST.
- [ ] Guest redirect login; user bukan donatur ditolak sesuai middleware.
- [ ] POST lengkap yang valid membuat Donation dengan jenis, user ID, campaign ID, jumlah, `berat_gram`, foto, status `menunggu_konfirmasi`, dan `tanggal_diterima=NULL` sesuai input.
- [ ] `makanan` **tidak tampil** pada dropdown; POST category makanan buatan manual ditolak; category soft-deleted ditolak.
- [ ] Jumlah kosong, 0, negatif/noninteger; berat kosong, 0, negatif/noninteger; foto invalid/terlalu besar → validation error, **tidak** menghasilkan Donation/Shipment.
- [ ] Foto valid ada di `Storage::disk('public')`; pada kegagalan DB setelah upload, foto yang sudah di-upload dibersihkan (test bila feasible dengan simulasi failure terkontrol).
- [ ] `is_anonim` true/false tersimpan tetapi `user_id` tetap sama; catatan opsional valid.
- [ ] Donasi baru **tidak** menambah `Campaign.terkumpul_barang`.

**Metode pengiriman (#57)**

- [ ] `dikirim_sendiri` + JNE dan resi valid → tepat satu Shipment; status `dikirim`; ongkir/tracking timestamp `NULL`.
- [ ] `dikirim_sendiri` + **J&T Express** dan resi valid → sukses (regresi untuk rule yang mengandung `&`).
- [ ] `dikirim_sendiri` tanpa ekspedisi/resi, dengan ekspedisi tidak dikenal → validation error.
- [ ] `diantar_langsung` tanpa ekspedisi/resi → sukses **tanpa Shipment**.
- [ ] `diantar_langsung` dengan ekspedisi/resi stale yang ikut terkirim → input tersebut diabaikan/tidak digunakan untuk membuat Shipment.

**Konfirmasi penerimaan (#59)**

- [ ] Penerima pemilik campaign melakukan PUT → Donation menjadi `diterima`, tanggal terisi, dan Shipment (bila ada) menjadi `diterima`.
- [ ] Untuk antar langsung (tanpa Shipment), konfirmasi tetap sukses.
- [ ] `terkumpul_barang` mula-mula 0 lalu bertambah sesuai `jumlah_barang` **setelah** konfirmasi, bukan sebelum.
- [ ] Dua Donation berbeda pada campaign sama: total sesuai jumlah keduanya; Donation pending tidak ikut dihitung; Donation uang tidak memengaruhi total barang.
- [ ] PUT kedua untuk Donation yang sama tidak mengubah timestamp/progress dan tidak membuat data baru.
- [ ] Penerima lain ditolak (404/403 sesuai aturan yang sudah digunakan), tidak ada perubahan record.
- [ ] Donatur/guest tidak dapat memanggil route konfirmasi. Donasi uang tidak bisa dikonfirmasi memakai route barang.
- [ ] Jika salah satu proses dalam transaksi gagal, perubahan status/tanggal/progress tidak tersimpan sebagian (bila feasible melalui simulasi exception).

### Command verification

```bash
php artisan route:list --path=donasi
php artisan test --filter=Stage0FoundationTest
php artisan test --filter=Stage1GoodsDonationTest
php artisan test
```

`--filter` bisa disesuaikan dengan nama class/test file yang benar. **Output akhir wajib menyebut angka test dan assertions aktual; jangan menulis PASS tanpa menjalankan perintahnya.** Jika MySQL disposable tersedia, uji migration `berat_gram` dan operasi INSERT di sana, jangan terhadap database asli.

## 6. Checklist sign-off per issue

| Issue | Acceptance criteria minimum | Penutupan |
|---|---|---|
| **#55 Form Donasi Barang** | Field jumlah & berat terpisah, non-makanan di UI dan backend, foto tersimpan aman, anonim/catatan, campaign validation, error state benar, tests PASS. | Siap ditandai selesai setelah test dan smoke test. |
| **#57 Pilihan Ekspedisi** | Validasi dan tampilan ekspedisi/resi untuk `dikirim_sendiri`, `diantar_langsung` tanpa Shipment palsu, data tampil sesuai role, tests PASS. | Siap ditandai selesai setelah test dan smoke test. |
| **#59 Konfirmasi Manual** | Hanya Penerima sah; status/tanggal Donation, status Shipment, progress tersinkron atomik; pengulangan aman; tests PASS. | Siap ditandai selesai setelah test dan smoke test. |
| **#29 Donasi Barang (parent)** | #55/#57/#59 selesai, **tetapi #56/#58 Komship masih pending**. | **JANGAN ditutup** pada Tahap 1. |

## 7. Format laporan Antigravity — WAJIB setelah selesai

Buat file `IMPLEMENTATION_REPORT_STAGE1.md` di root repository dengan susunan:

1. **Summary:** apakah #55, #57, #59 memenuhi acceptance criteria (Selesai/Sebagian/Blocked), apa yang belum dilakukan.
2. **Audit baseline:** kondisi code sebelum dan sesudah, perbedaan dengan plan jika ada.
3. **Daftar file dibuat/diubah** beserta tujuan tiap perubahan; sebutkan nama migration baru dan nama field berat final.
4. **Kontrak data final:** validasi form, enum Donation/Shipment, perilaku ekspedisi vs antar langsung, dan cara update progress.
5. **Hasil testing aktual:** command, jumlah test/assertions, PASS/FAIL, kasus yang gagal, hasil verifikasi MySQL disposable jika diuji; jangan sembunyikan kendala.
6. **Manual smoke test:** flow Donatur + Penerima dan hasil yang benar-benar dicoba.
7. **Security & regression check:** scoping kepemilikan, food category rejection, old input, upload cleanup, idempotency.
8. **Handoff & risiko tersisa:** terutama #56 (ongkir Komship), #58 (tracking), dan koordinasi dengan anggota tim lain.
9. **Git status:** file apa saja yang berubah dan apakah ada file sementara/artefak sensitif yang perlu dibersihkan (tanpa mencetak secret).

**Akhiri jawaban Antigravity dengan ringkasan status per issue dan laporan dalam file. Jangan membuat PR/commit/push.**

## 8. Koordinasi dengan tim

Dokumentasi tim juga menyebut **Grace** menangani sebagian form donasi barang/konfirmasi, sedangkan **Edo** menangani integrasi eksternal dan konfirmasi final. Karena issue #55/#57/#59 berada pada daftar assigned yang dikerjakan sekarang, usahakan perubahan tetap terisolasi pada file modul donasi barang. **Sebelum merge ke branch bersama, sinkronkan dengan Grace** agar tidak terjadi konflik implementasi. Jangan menyentuh modul Auth/Settings di luar scope hanya karena ada temuan tersendiri.

## 9. Batas berhenti (stop condition)

Tahap 1 boleh dinyatakan selesai setelah:

- [ ] Semua acceptance criteria #55, #57, #59 terpenuhi.
- [ ] Semua test lama dan test baru lulus (`php artisan test`).
- [ ] Schema upgrade aman dan tidak menggunakan database utama sebagai disposable testing.
- [ ] Tidak ada UI yang mengklaim ongkir/tracking realtime padahal belum terintegrasi.
- [ ] Laporan `IMPLEMENTATION_REPORT_STAGE1.md` dibuat dan mencantumkan status yang jujur.
- [ ] Tidak ada perubahan ke #47–#54, #56, #58 atau modul lain di luar scope, kecuali dependency minimal yang dijelaskan.

**BERHENTI setelah Tahap 1.** Jangan lanjut ke #56 (Komship ongkir), #58 (scheduled tracking), Midtrans, recurring, atau issue berikutnya tanpa plan/instruksi baru dari pemilik proyek.
