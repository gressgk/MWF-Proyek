<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T0.7 — Foundation schema migration untuk SQLite testing.
 *
 * File ini membuat seluruh tabel minimum yang dibutuhkan oleh test Tahap 0.
 * Dibuat karena base schema proyek ada di wecare.sql (bukan migration Laravel),
 * sedangkan phpunit.xml menggunakan SQLite :memory: yang tidak bisa import SQL.
 *
 * Strategi:
 *   - Migration ini dijalankan otomatis oleh RefreshDatabase pada test Tahap 0.
 *   - Pada MySQL (development/production), migration ini harus DILEWATI karena
 *     tabel sudah ada dari wecare.sql. Gunakan kondisi driver check jika perlu.
 *   - donations.status pada migration ini sudah mencakup 'menunggu_konfirmasi'
 *     (hasil fix T0.3) agar test bisa memverifikasi nilai tersebut.
 *   - SQLite tidak mendukung ENUM; gunakan string() sebagai pengganti.
 *
 * PENTING: Jangan jalankan migrate pada DB MySQL production/development
 *          jika tabel sudah ada dari wecare.sql. File ini aman untuk
 *          SQLite testing karena menggunakan :memory: yang disposable.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Keamanan: HANYA boleh berjalan pada environment 'testing' dengan driver SQLite (:memory:).
        // Jangan pernah berjalan di environment non-testing (local, production, staging, dll).
        if (!app()->environment('testing') || DB::getDriverName() !== 'sqlite') {
            return;
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('role')->default('donatur'); // admin, penerima, donatur
            $table->string('status_verifikasi')->nullable(); // pending, approved, rejected
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('item_categories', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('slug')->unique();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kategori_lainnya')->nullable();
            $table->string('judul');
            $table->string('slug')->unique();
            $table->text('deskripsi');
            $table->string('tipe_bantuan'); // uang, barang, keduanya
            $table->decimal('target_nominal', 15, 2)->nullable();
            $table->decimal('terkumpul_nominal', 15, 2)->default(0);
            $table->string('target_barang')->nullable();
            $table->string('satuan_barang')->nullable();
            $table->unsignedInteger('terkumpul_barang')->default(0);
            $table->string('urgensi')->default('biasa'); // mendesak, biasa, terjadwal
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->string('foto_sampul')->nullable();
            $table->string('qr_code_path')->nullable();
            $table->string('status')->default('draft'); // draft, pending_approval, aktif, selesai, ditolak
            $table->text('alasan_ditolak')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('jenis'); // uang, barang
            $table->boolean('is_anonim')->default(false);
            $table->decimal('nominal', 15, 2)->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->string('frequency')->nullable(); // mingguan, bulanan
            $table->string('order_id')->nullable();
            $table->foreignId('item_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nama_barang')->nullable();
            $table->unsignedInteger('jumlah_barang')->nullable();
            $table->string('satuan_barang')->nullable();
            $table->unsignedInteger('berat_gram')->nullable();
            $table->string('foto_barang')->nullable();
            $table->text('catatan')->nullable();
            $table->string('metode_pengiriman')->nullable(); // dikirim_sendiri, diantar_langsung
            // T0.3: Sudah mencakup 'menunggu_konfirmasi'
            $table->string('status')->default('menunggu_pembayaran');
            // menunggu_pembayaran, menunggu_konfirmasi, terverifikasi, diterima, gagal
            $table->timestamp('tanggal_diterima')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donation_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('ekspedisi')->nullable();
            $table->string('no_resi')->nullable();
            $table->decimal('estimasi_ongkir', 12, 2)->nullable();
            $table->string('status')->default('dikirim'); // dikirim, dalam_perjalanan, diterima
            $table->timestamp('last_tracked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('campaign_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('judul');
            $table->text('isi');
            $table->decimal('nominal_terpakai', 15, 2)->nullable();
            // CATATAN: dokumen_bukti dan campaign_report_photos ditambahkan oleh
            // migration 2026_11_10_014137 (milik tim/Anastasia). Jangan dobel di sini.
            $table->timestamps();
        });

        Schema::create('penerima_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nama_organisasi');
            $table->string('jenis_organisasi'); // yayasan, individu, komunitas
            $table->text('alamat')->nullable();
            $table->string('nomor_rekening')->nullable();
            $table->string('dokumen_ktp')->nullable();
            $table->string('dokumen_legalitas')->nullable();
            $table->text('alasan_ditolak')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        // Keamanan: HANYA boleh berjalan pada environment 'testing' dengan driver SQLite (:memory:).
        if (!app()->environment('testing') || DB::getDriverName() !== 'sqlite') {
            return;
        }

        // campaign_report_photos dibuat oleh migration 2026_11_10, bukan di sini.
        Schema::dropIfExists('campaign_reports');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('donations');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('item_categories');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('penerima_profiles');
        Schema::dropIfExists('users');
    }
};
