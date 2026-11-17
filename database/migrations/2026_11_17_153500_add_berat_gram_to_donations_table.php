<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T1.1 — Tambah kolom berat_gram pada tabel donations (Issue #55 / #56).
 *
 * Kolom ini menyimpan estimasi berat total barang dalam gram (unsigned integer).
 * Nullable di database agar kompatibel dengan data legacy dan donasi jenis uang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            if (!Schema::hasColumn('donations', 'berat_gram')) {
                $table->unsignedInteger('berat_gram')->nullable()->after('satuan_barang');
            }
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            if (Schema::hasColumn('donations', 'berat_gram')) {
                $table->dropColumn('berat_gram');
            }
        });
    }
};
