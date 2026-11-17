# WeCare

Platform donasi online berbasis Laravel. Menghubungkan Donatur dengan Penerima (mitra/organisasi) lewat campaign donasi, baik dalam bentuk uang maupun barang.

## Tentang WeCare

WeCare dibuat untuk mempermudah proses donasi yang transparan dan terpercaya. Setiap campaign punya laporan transparansi penggunaan dana/barang, sehingga donatur bisa memantau ke mana donasinya dipakai.

## Role Pengguna

- **Admin** — verifikasi mitra penerima, approve/reject campaign, kelola kategori, monitoring seluruh donasi, kirim broadcast notifikasi
- **Penerima (Mitra)** — organisasi/individu yang membuat campaign donasi, menerima donasi, dan mempublikasikan laporan penggunaan
- **Donatur** — user yang berdonasi ke campaign, bisa uang atau barang

## Fitur Utama

- Donasi **uang** (via Midtrans) dan **barang** (pengiriman manual via JNE/J&T/dll, non-makanan)
- Opsi donasi **recurring** (mingguan/bulanan) untuk donasi uang
- Opsi **donasi anonim**
- **Chat** langsung antara Donatur dan Penerima per campaign
- **Laporan transparansi** (live selama campaign berjalan + laporan akhir setelah selesai)
- **QR code** per campaign untuk akses cepat
- Estimasi ongkir & tracking resi pengiriman barang via **Komship API**
- Kategori campaign bebas (termasuk opsi "Lainnya")
- Level urgensi campaign: mendesak, biasa, terjadwal
- Verifikasi mitra oleh Admin sebelum bisa membuat campaign
- Moderasi campaign oleh Admin sebelum tayang

## Tech Stack

- Laravel 13 (PHP ^8.3)
- Blade templating
- Bootstrap 5 (CDN, tanpa Vite/Tailwind build)
- MySQL

## Instalasi

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env` kalau koneksi MySQL kamu beda dari default (`DB_DATABASE=wecare`, `DB_USERNAME=root`, `DB_PASSWORD` kosong).

Buat database-nya dulu di MySQL:

```sql
CREATE DATABASE wecare;
```

Import struktur tabel dari `database/wecare.sql` (bukan pakai migration Laravel):

```bash
mysql -u root -p wecare < database/wecare.sql
```

Atau lewat phpMyAdmin: pilih database `wecare` → tab **Import** → pilih file `database/wecare.sql`.

Isi data dummy:

```bash
php artisan db:seed
php artisan serve
```

Akses di `http://localhost:8000`

> Project ini tidak pakai migration Laravel — struktur tabel dibuat manual lewat `database/wecare.sql`. `php artisan db:seed` tetap jalan normal karena cuma butuh tabelnya sudah ada, bukan migration history.

## Akun Demo (password semua: `password`)

- Admin: `admin@wecare.id`
- Mitra Penerima: `kasihbunda@wecare.id`
- Donatur: `dewi@wecare.id`

## Tim

Project tugas kelompok mata kuliah — Grace (lead), Anastasia, Edo, Jefferson.