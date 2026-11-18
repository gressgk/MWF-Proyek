<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE donations MODIFY status ENUM('menunggu_pembayaran','menunggu_konfirmasi','terverifikasi','diterima','gagal') NOT NULL DEFAULT 'menunggu_pembayaran'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE donations MODIFY status ENUM('menunggu_pembayaran','terverifikasi','diterima','gagal') NOT NULL DEFAULT 'menunggu_pembayaran'");
    }
};