<?php

/**
 * tests/Feature/Stage0FoundationTest.php
 *
 * T0.7 — Pest Feature Tests untuk fondasi Tahap 0.
 *
 * Test yang dicakup (sesuai plan.md T0.7):
 *   1. Skema menerima status 'menunggu_konfirmasi' untuk donasi barang.
 *   2. Donasi uang pending/gagal tidak menambah terkumpul_nominal.
 *   3. Donasi uang terverifikasi dihitung tepat sekali walaupun sync dipanggil ulang.
 *   4. Barang pending tidak menambah terkumpul_barang; barang 'diterima' baru dihitung.
 *   5. Campaign A dan B tetap terisolasi saat penghitungan progress.
 *   6. GET donasi uang memakai campaign sesuai slug dan menolak campaign nonaktif/tipe tidak sesuai.
 *   7. User dengan role yang salah ditolak, dan aksi Penerima untuk donation campaign orang lain
 *      tidak diizinkan (pada jalur yang tersedia).
 *
 * Strategi database:
 *   - Menggunakan SQLite :memory: (phpunit.xml default) + RefreshDatabase per test.
 *   - Migration foundation (2026_11_17_000001_create_foundation_schema_for_testing.php)
 *     membuat tabel minimal untuk SQLite.
 *   - TIDAK mengaktifkan RefreshDatabase secara global di Pest.php
 *     (agar test lama tidak terpengaruh).
 */

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\User;
use App\Services\CampaignProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * =========================================================================
 * Helper setup: semua test di file ini pakai RefreshDatabase + SQLite :memory:
 * =========================================================================
 */
uses(RefreshDatabase::class);

// Helper: buat user donatur
function makeDonatur(): User
{
    return User::factory()->donatur()->create();
}

// Helper: buat user penerima
function makePenerima(): User
{
    return User::factory()->penerima()->create();
}

// Helper: buat campaign aktif tipe uang
function makeCampaignUang(User $penerima, string $status = 'aktif', string $tipe = 'uang'): Campaign
{
    return Campaign::create([
        'user_id'          => $penerima->id,
        'judul'            => 'Campaign Uang ' . uniqid(),
        'slug'             => 'campaign-uang-' . uniqid(),
        'deskripsi'        => 'Test campaign',
        'tipe_bantuan'     => $tipe,
        'target_nominal'   => 10000000,
        'terkumpul_nominal' => 0,
        'target_barang'    => null,
        'terkumpul_barang'  => 0,
        'urgensi'          => 'biasa',
        'tanggal_mulai'    => now()->subDays(5),
        'tanggal_selesai'  => now()->addDays(30),
        'status'           => $status,
    ]);
}

// Helper: buat campaign aktif tipe barang
function makeCampaignBarang(User $penerima): Campaign
{
    return Campaign::create([
        'user_id'          => $penerima->id,
        'judul'            => 'Campaign Barang ' . uniqid(),
        'slug'             => 'campaign-barang-' . uniqid(),
        'deskripsi'        => 'Test campaign barang',
        'tipe_bantuan'     => 'barang',
        'target_nominal'   => null,
        'terkumpul_nominal' => 0,
        'target_barang'    => 100,
        'satuan_barang'    => 'pcs',
        'terkumpul_barang'  => 0,
        'urgensi'          => 'biasa',
        'tanggal_mulai'    => now()->subDays(5),
        'tanggal_selesai'  => now()->addDays(30),
        'status'           => 'aktif',
    ]);
}

/*
 * =========================================================================
 * TEST 1 — Schema menerima 'menunggu_konfirmasi' untuk donasi barang
 * =========================================================================
 */
it('dapat menyimpan donasi barang dengan status menunggu_konfirmasi', function () {
    $penerima = makePenerima();
    $donatur  = makeDonatur();
    $campaign = makeCampaignBarang($penerima);

    // SQLite :memory: menggunakan string column, bukan ENUM → nilai apapun diterima.
    // Test ini memverifikasi bahwa kode aplikasi (model) tidak menolak nilai tersebut.
    $donation = Donation::create([
        'campaign_id'  => $campaign->id,
        'user_id'      => $donatur->id,
        'jenis'        => 'barang',
        'jumlah_barang' => 10,
        'satuan_barang' => 'pcs',
        'status'       => 'menunggu_konfirmasi',
    ]);

    expect($donation->status)->toBe('menunggu_konfirmasi');
    expect($donation->exists)->toBeTrue();
});

/*
 * =========================================================================
 * TEST 2 — Donasi uang pending/gagal tidak menambah terkumpul_nominal
 * =========================================================================
 */
it('donasi uang pending dan gagal tidak dihitung dalam terkumpul_nominal', function () {
    $penerima = makePenerima();
    $donatur  = makeDonatur();
    $campaign = makeCampaignUang($penerima);
    $service  = new CampaignProgressService();

    // Buat donasi pending
    Donation::create([
        'campaign_id' => $campaign->id,
        'user_id'     => $donatur->id,
        'jenis'       => 'uang',
        'nominal'     => 500000,
        'status'      => 'menunggu_pembayaran',
    ]);

    // Buat donasi gagal
    Donation::create([
        'campaign_id' => $campaign->id,
        'user_id'     => $donatur->id,
        'jenis'       => 'uang',
        'nominal'     => 300000,
        'status'      => 'gagal',
    ]);

    $result = $service->sync($campaign);

    expect((float) $result->terkumpul_nominal)->toBe(0.0);
});

/*
 * =========================================================================
 * TEST 3 — Donasi uang terverifikasi dihitung tepat sekali walaupun sync berulang
 * =========================================================================
 */
it('donasi uang terverifikasi dihitung tepat sekali meski sync dipanggil berulang', function () {
    $penerima = makePenerima();
    $donatur  = makeDonatur();
    $campaign = makeCampaignUang($penerima);
    $service  = new CampaignProgressService();

    Donation::create([
        'campaign_id' => $campaign->id,
        'user_id'     => $donatur->id,
        'jenis'       => 'uang',
        'nominal'     => 100000,
        'status'      => 'terverifikasi',
    ]);

    Donation::create([
        'campaign_id' => $campaign->id,
        'user_id'     => $donatur->id,
        'jenis'       => 'uang',
        'nominal'     => 150000,
        'status'      => 'terverifikasi',
    ]);

    // Panggil sync 3 kali berturut-turut (idempotency test)
    $r1 = $service->sync($campaign);
    $r2 = $service->sync($campaign);
    $r3 = $service->sync($campaign);

    expect((float) $r1->terkumpul_nominal)->toBe(250000.0);
    expect((float) $r2->terkumpul_nominal)->toBe(250000.0);
    expect((float) $r3->terkumpul_nominal)->toBe(250000.0);
});

/*
 * =========================================================================
 * TEST 4 — Barang pending tidak dihitung; barang 'diterima' baru dihitung
 * =========================================================================
 */
it('barang menunggu_konfirmasi tidak dihitung, hanya yang diterima masuk terkumpul_barang', function () {
    $penerima = makePenerima();
    $donatur  = makeDonatur();
    $campaign = makeCampaignBarang($penerima);
    $service  = new CampaignProgressService();

    // Barang sudah diterima — HARUS dihitung
    Donation::create([
        'campaign_id'   => $campaign->id,
        'user_id'       => $donatur->id,
        'jenis'         => 'barang',
        'jumlah_barang' => 20,
        'satuan_barang' => 'pcs',
        'status'        => 'diterima',
    ]);

    // Barang menunggu konfirmasi — TIDAK boleh dihitung
    Donation::create([
        'campaign_id'   => $campaign->id,
        'user_id'       => $donatur->id,
        'jenis'         => 'barang',
        'jumlah_barang' => 50,
        'satuan_barang' => 'pcs',
        'status'        => 'menunggu_konfirmasi',
    ]);

    $result = $service->sync($campaign);

    expect($result->terkumpul_barang)->toBe(20);
});

/*
 * =========================================================================
 * TEST 5 — Campaign A dan B terisolasi (donasi A tidak mempengaruhi B)
 * =========================================================================
 */
it('donasi campaign A tidak memengaruhi terkumpul campaign B', function () {
    $penerima = makePenerima();
    $donatur  = makeDonatur();

    $campaignA = makeCampaignUang($penerima);
    $campaignB = makeCampaignUang($penerima);

    $service = new CampaignProgressService();

    // Donasi ke campaign A
    Donation::create([
        'campaign_id' => $campaignA->id,
        'user_id'     => $donatur->id,
        'jenis'       => 'uang',
        'nominal'     => 999999,
        'status'      => 'terverifikasi',
    ]);

    // Sync keduanya
    $resultA = $service->sync($campaignA);
    $resultB = $service->sync($campaignB);

    expect((float) $resultA->terkumpul_nominal)->toBe(999999.0);
    expect((float) $resultB->terkumpul_nominal)->toBe(0.0);
});

/*
 * =========================================================================
 * TEST 6a — GET donasi uang memakai campaign sesuai slug
 * =========================================================================
 */
it('GET donasi uang menampilkan halaman berdasarkan slug yang benar', function () {
    $penerima = makePenerima();
    $donatur  = makeDonatur();
    $campaign = makeCampaignUang($penerima, 'aktif', 'uang');

    // Login sebagai donatur
    $this->actingAs($donatur);

    $response = $this->get("/donatur/donasi/{$campaign->slug}/uang");

    // Mewajibkan HTTP 200 dan memverifikasi campaign sesuai ID dan slug yang benar
    $response->assertOk();
    $response->assertViewIs('donatur.donasi-uang');
    $response->assertViewHas('campaign', function ($viewCampaign) use ($campaign) {
        return $viewCampaign->id === $campaign->id && $viewCampaign->slug === $campaign->slug;
    });
    $response->assertSee($campaign->judul);
});

/*
 * =========================================================================
 * TEST 6b — GET donasi uang menolak campaign nonaktif
 * =========================================================================
 */
it('GET donasi uang menolak campaign berstatus bukan aktif', function () {
    $penerima = makePenerima();
    $donatur  = makeDonatur();
    $campaign = makeCampaignUang($penerima, 'pending_approval', 'uang');

    $this->actingAs($donatur);

    $response = $this->get("/donatur/donasi/{$campaign->slug}/uang");

    expect($response->status())->toBe(404);
});

/*
 * =========================================================================
 * TEST 6c — GET donasi uang menolak campaign tipe barang
 * =========================================================================
 */
it('GET donasi uang menolak campaign tipe barang', function () {
    $penerima = makePenerima();
    $donatur  = makeDonatur();
    $campaign = makeCampaignBarang($penerima); // tipe_bantuan = 'barang'

    $this->actingAs($donatur);

    $response = $this->get("/donatur/donasi/{$campaign->slug}/uang");

    expect($response->status())->toBe(404);
});

/*
 * =========================================================================
 * TEST 7a — Penerima tidak bisa akses route donatur
 * =========================================================================
 */
it('penerima tidak bisa mengakses halaman donasi uang (route donatur)', function () {
    $penerima = makePenerima();
    $campaign = makeCampaignUang($penerima, 'aktif', 'uang');

    $this->actingAs($penerima);

    $response = $this->get("/donatur/donasi/{$campaign->slug}/uang");

    expect($response->status())->toBe(403);
});

/*
 * =========================================================================
 * TEST 7b — Penerima hanya bisa konfirmasi donasi campaign miliknya sendiri
 * =========================================================================
 */
it('penerima pemilik campaign dapat mengonfirmasi donasi barang yang menunggu konfirmasi', function () {
    $penerima = makePenerima();
    $donatur  = makeDonatur();

    $campaign = makeCampaignBarang($penerima);

    $donation = Donation::create([
        'campaign_id'   => $campaign->id,
        'user_id'       => $donatur->id,
        'jenis'         => 'barang',
        'jumlah_barang' => 10,
        'satuan_barang' => 'pcs',
        'status'        => 'menunggu_konfirmasi',
    ]);

    expect($donation->status)->toBe('menunggu_konfirmasi');
    expect($donation->tanggal_diterima)->toBeNull();

    $this->actingAs($penerima);

    $response = $this->from('/mitra/donasi-masuk')
        ->put("/mitra/donasi-masuk/{$donation->id}/konfirmasi");

    $response->assertRedirect('/mitra/donasi-masuk');
    $response->assertSessionHas('success', 'Donasi barang berhasil dikonfirmasi!');

    $donation->refresh();
    expect($donation->status)->toBe('diterima');
    expect($donation->tanggal_diterima)->not->toBeNull();
});

it('penerima tidak bisa konfirmasi donasi dari campaign milik penerima lain', function () {
    $penerimaA = makePenerima();
    $penerimaB = makePenerima();
    $donatur   = makeDonatur();

    // Campaign milik penerima A
    $campaign = makeCampaignBarang($penerimaA);

    // Donasi ke campaign A
    $donation = Donation::create([
        'campaign_id'  => $campaign->id,
        'user_id'      => $donatur->id,
        'jenis'        => 'barang',
        'jumlah_barang' => 5,
        'satuan_barang' => 'pcs',
        'status'       => 'menunggu_konfirmasi',
    ]);

    // Penerima B coba konfirmasi donasi milik campaign A
    $this->actingAs($penerimaB);

    $response = $this->put("/mitra/donasi-masuk/{$donation->id}/konfirmasi");

    // Harus 404 (not found — campaign bukan milik penerima B)
    expect($response->status())->toBe(404);
});

/*
 * =========================================================================
 * TEST 7c — Guest tidak bisa akses route donatur
 * =========================================================================
 */
it('guest tidak bisa mengakses halaman donasi', function () {
    $penerima = makePenerima();
    $campaign = makeCampaignUang($penerima, 'aktif', 'uang');

    $response = $this->get("/donatur/donasi/{$campaign->slug}/uang");

    // Redirect ke login
    expect($response->status())->toBe(302);
});
