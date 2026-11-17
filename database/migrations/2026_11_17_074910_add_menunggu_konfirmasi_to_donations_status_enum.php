<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T0.3 — Tambah nilai 'menunggu_konfirmasi' ke ENUM donations.status.
 *
 * Latar belakang:
 *   wecare.sql mendefinisikan donations.status sebagai:
 *   ENUM('menunggu_pembayaran','terverifikasi','diterima','gagal')
 *   Namun DonasiBarangController@store menyimpan status='menunggu_konfirmasi'
 *   sehingga INSERT bisa gagal pada MySQL strict-mode.
 *
 * Strategi:
 *   - Additive/non-destructive: nilai lama dipertahankan.
 *   - Urutan nilai baru: menunggu_pembayaran, menunggu_konfirmasi,
 *     terverifikasi, diterima, gagal.
 *   - Hanya berlaku pada DB driver MySQL/MariaDB; SQLite (testing) melewati.
 *
 * Rollback:
 *   down() mengembalikan kolom ke ENUM tanpa menunggu_konfirmasi.
 *   PERINGATAN: rollback TIDAK aman jika sudah ada baris dengan
 *   status='menunggu_konfirmasi'. Jalankan validasi dulu sebelum migrate:rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if (!in_array($driver, ['mysql', 'mariadb'])) {
            // SQLite (testing :memory:) tidak mendukung ALTER COLUMN ENUM.
            // Test fondasi akan menguji via model/factory langsung.
            return;
        }

        DB::statement("
            ALTER TABLE `donations`
            MODIFY COLUMN `status`
            ENUM(
                'menunggu_pembayaran',
                'menunggu_konfirmasi',
                'terverifikasi',
                'diterima',
                'gagal'
            )
            NOT NULL
            DEFAULT 'menunggu_pembayaran'
        ");
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if (!in_array($driver, ['mysql', 'mariadb'])) {
            return;
        }

        // Periksa apakah ada data dengan nilai yang akan dihapus.
        $count = DB::table('donations')
            ->where('status', 'menunggu_konfirmasi')
            ->count();

        if ($count > 0) {
            // Jangan rollback jika data sudah ada; lempar exception agar rollback gagal
            // dengan pesan yang jelas.
            throw new \RuntimeException(
                "Rollback dibatalkan: terdapat {$count} baris donations dengan " .
                "status='menunggu_konfirmasi'. Pastikan data dimigrasi dulu " .
                "sebelum menjalankan migrate:rollback."
            );
        }

        DB::statement("
            ALTER TABLE `donations`
            MODIFY COLUMN `status`
            ENUM(
                'menunggu_pembayaran',
                'terverifikasi',
                'diterima',
                'gagal'
            )
            NOT NULL
            DEFAULT 'menunggu_pembayaran'
        ");
    }
};
