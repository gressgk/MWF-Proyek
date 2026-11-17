<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E3 migration — menambahkan kolom webhook idempotency pada tabel donations.
 *
 * Kolom yang ditambahkan:
 *   - webhook_processed_at  : timestamp terakhir webhook berhasil diproses (null = belum ada)
 *   - webhook_transaction_status : status mentah terakhir dari Midtrans notification
 *   - webhook_fraud_status   : fraud_status mentah dari Midtrans (untuk jenis capture)
 *
 * Semua kolom nullable — tidak merusak record lama.
 * Migration bersifat additive dan aman dijalankan pada database yang sudah berisi data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            if (!Schema::hasColumn('donations', 'webhook_processed_at')) {
                $column = $table->timestamp('webhook_processed_at')->nullable();

                // Kolom payment_status_detail baru dibuat pada migrasi 2026_11_18_000002.
                // Jika migrasi ini dijalankan lebih awal (urutan kronologis 2026_11_17),
                // posisikan setelah status agar tidak error 'Column not found'.
                if (Schema::hasColumn('donations', 'payment_status_detail')) {
                    $column->after('payment_status_detail');
                } elseif (Schema::hasColumn('donations', 'status')) {
                    $column->after('status');
                }
            }
            if (!Schema::hasColumn('donations', 'webhook_transaction_status')) {
                $table->string('webhook_transaction_status', 50)->nullable()->after('webhook_processed_at');
            }
            if (!Schema::hasColumn('donations', 'webhook_fraud_status')) {
                $table->string('webhook_fraud_status', 50)->nullable()->after('webhook_transaction_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropColumnIfExists('webhook_processed_at');
            $table->dropColumnIfExists('webhook_transaction_status');
            $table->dropColumnIfExists('webhook_fraud_status');
        });
    }
};
