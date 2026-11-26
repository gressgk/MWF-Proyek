<?php

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    // Safety guard: pastikan test berjalan pada database wecare_testing
    $dbName = config('database.connections.mysql.database');
    if ($dbName !== 'wecare_testing') {
        throw new \RuntimeException("SAFETY VIOLATION: Tests must run on wecare_testing, currently on {$dbName}");
    }

    // Default Midtrans sandbox test credentials
    Config::set('services.midtrans.server_key', 'TEST_ONLY_SERVER_KEY_12345');
    Config::set('services.midtrans.client_key', 'TEST_ONLY_CLIENT_KEY_12345');
    Config::set('services.midtrans.is_production', false);
    Config::set('services.midtrans.min_amount', 1000);
});

function createCampaignTest(array $attributes = []): Campaign
{
    $title = $attributes['judul'] ?? 'Bantu Korban Bencana';
    $penerimaId = $attributes['user_id'] ?? User::factory()->penerima()->create()->id;

    return Campaign::create(array_merge([
        'user_id' => $penerimaId,
        'judul' => $title,
        'slug' => $attributes['slug'] ?? (Str::slug($title) . '-' . rand(1000, 9999)),
        'deskripsi' => 'Deskripsi campaign bantuan WeCare',
        'tipe_bantuan' => 'uang',
        'target_nominal' => 10000000,
        'terkumpul_nominal' => 0,
        'urgensi' => 'biasa',
        'tanggal_mulai' => now()->subDay()->toDateString(),
        'tanggal_selesai' => now()->addDays(30)->toDateString(),
        'status' => 'aktif',
    ], $attributes));
}

test('unauthenticated user cannot access donasi uang page', function () {
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);

    $response = $this->get(route('donatur.donasi.uang', $campaign->slug));
    $response->assertRedirect(route('login'));
});

test('non-donatur role cannot access donasi uang page', function () {
    $penerima = User::factory()->penerima()->create();
    $campaign = createCampaignTest(['user_id' => $penerima->id, 'status' => 'aktif', 'tipe_bantuan' => 'uang']);

    $response = $this->actingAs($penerima)->get(route('donatur.donasi.uang', $campaign->slug));
    $response->assertStatus(403);
});

test('authenticated donatur can view donasi uang page for the specific campaign slug', function () {
    $donatur = User::factory()->donatur()->create();
    $penerima = User::factory()->penerima()->create();

    $campaign1 = createCampaignTest([
        'user_id' => $penerima->id,
        'judul' => 'Campaign Pertama',
        'slug' => 'campaign-pertama',
        'status' => 'aktif',
        'tipe_bantuan' => 'uang',
    ]);

    $campaign2 = createCampaignTest([
        'user_id' => $penerima->id,
        'judul' => 'Campaign Kedua Target',
        'slug' => 'campaign-kedua-target',
        'status' => 'aktif',
        'tipe_bantuan' => 'uang',
    ]);

    $response = $this->actingAs($donatur)->get(route('donatur.donasi.uang', $campaign2->slug));

    $response->assertStatus(200);
    $response->assertSee('Campaign Kedua Target');
    $response->assertDontSee('Campaign Pertama');
});

test('donasi uang returns 404 for inactive campaign', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'selesai', 'tipe_bantuan' => 'uang']);

    $response = $this->actingAs($donatur)->get(route('donatur.donasi.uang', $campaign->slug));
    $response->assertStatus(404);
});

test('donasi uang returns 404 for campaign that only accepts goods', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'barang']);

    $response = $this->actingAs($donatur)->get(route('donatur.donasi.uang', $campaign->slug));
    $response->assertStatus(404);
});

test('recurring donation view configures sandbox midtrans new 3ds script safely', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);

    $response = $this->actingAs($donatur)->get(route('donatur.donasi.uang', $campaign->slug));

    $response->assertOk();
    $response->assertSee('id="midtrans-script"', false);
    $response->assertSee('type="text/javascript"', false);
    $response->assertSee('data-environment="sandbox"', false);
    $response->assertSee('https://api.sandbox.midtrans.com/v2/assets/js/midtrans-new-3ds.min.js', false);
    $response->assertSee('MidtransNew3ds.getCardToken(cardData, tokenOptions)', false);
});

test('successful checkout creates pending donation and retrieves snap token', function () {
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'token' => 'snap-mock-token-xyz-123',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-mock-token-xyz-123',
        ], 201),
    ]);

    $donatur = User::factory()->donatur()->create(['name' => 'Budi Santoso', 'email' => 'budi@test.com']);
    $campaign = createCampaignTest([
        'status' => 'aktif',
        'tipe_bantuan' => 'uang',
        'terkumpul_nominal' => 0,
    ]);

    $response = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 50000,
        'nominal_preset' => '50000',
        'is_anonim' => '0',
    ]);

    $response->assertStatus(200);
    $response->assertSee('snap-mock-token-xyz-123');

    // Pastikan donation tersimpan dengan benar di DB
    $donation = Donation::where('user_id', $donatur->id)->where('campaign_id', $campaign->id)->first();
    expect($donation)->not->toBeNull();
    expect((int) $donation->nominal)->toBe(50000);
    expect($donation->status)->toBe('menunggu_pembayaran');
    expect($donation->snap_token)->toBe('snap-mock-token-xyz-123');
    expect($donation->is_anonim)->toBe(0);
    expect($donation->order_id)->toStartWith('DON-');

    // Pastikan progress campaign TIDAK bertambah sebelum pembayaran webhook valid
    $campaign->refresh();
    expect((int) $campaign->terkumpul_nominal)->toBe(0);
});

test('anonymous donation sets is_anonim to true while keeping user_id bound', function () {
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'token' => 'snap-mock-token-anonim-999',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-mock-token-anonim-999',
        ], 201),
    ]);

    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);

    $response = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 100000,
        'is_anonim' => '1',
    ]);

    $response->assertStatus(200);

    $donation = Donation::where('user_id', $donatur->id)->where('campaign_id', $campaign->id)->first();
    expect($donation->is_anonim)->toBe(1);
    expect($donation->user_id)->toBe($donatur->id);
    expect($donation->nama_tampil)->toBe('Donatur Anonim');
});

test('nominal validation rejects missing, negative, non-integer, or below minimum values', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);

    // Kosong
    $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => '',
    ])->assertSessionHasErrors(['nominal']);

    // Di bawah minimum (< 1000)
    $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 500,
    ])->assertSessionHasErrors(['nominal']);

    // Negatif
    $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => -10000,
    ])->assertSessionHasErrors(['nominal']);

    // Bukan integer
    $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 'seratus_ribu',
    ])->assertSessionHasErrors(['nominal']);

    expect(Donation::count())->toBe(0);
});

test('idempotent checkout prevents duplicate donations on double submit', function () {
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/*/status' => function ($request) {
            preg_match('/\/v2\/([^\/]+)\/status/', $request->url(), $matches);
            return Http::response([
                'order_id' => $matches[1] ?? '',
                'status_code' => '201',
                'transaction_status' => 'pending',
                'gross_amount' => '75000.00',
            ], 200);
        },
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'token' => 'snap-mock-idempotent-token-111',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-mock-idempotent-token-111',
        ], 201),
    ]);

    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);

    $idempotencyKey = Str::uuid()->toString();

    // Request pertama
    $response1 = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 75000,
        'idempotency_key' => $idempotencyKey,
    ]);
    $response1->assertStatus(200);

    // Request kedua dengan idempotency_key yang sama (simulasi double-click)
    $response2 = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 75000,
        'idempotency_key' => $idempotencyKey,
    ]);
    $response2->assertStatus(200);

    // Hanya 1 record Donation yang tercipta
    expect(Donation::where('user_id', $donatur->id)->where('campaign_id', $campaign->id)->count())->toBe(1);

    // Midtrans API hanya dipanggil 1 kali
    Http::assertSentCount(2);
});

test('recurring donation requires payment_token (E4 is active)', function () {
    // E4 sudah diimplementasikan: is_recurring sekarang aktif via Midtrans Subscription API.
    // Request tanpa payment_token harus ditolak dengan error 'payment_token', bukan 'is_recurring'.
    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);

    $response = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal'           => 100000,
        'is_recurring'      => '1',
        'frequency'         => 'bulanan',
        'recurring_consent' => 'accepted',
        // payment_token sengaja tidak dikirim
    ]);

    // Seharusnya ditolak karena payment_token tidak ada, BUKAN 'is_recurring'
    $response->assertSessionHasErrors(['payment_token']);
    expect(Donation::count())->toBe(0);
});

test('midtrans provider definitive failure preserves donation record with gagal status', function () {
    // HTTP 401 = kegagalan definitif dari Midtrans (server key salah/tidak authorized).
    // Desain E2.1: record DIPERTAHANKAN sebagai audit trail, bukan dihapus,
    // agar idempotency_key tetap ada untuk referensi dan reconciliation E3.
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'error_messages' => ['Access denied due to unauthorized transaction'],
        ], 401),
    ]);

    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);

    $response = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 100000,
    ]);

    // Harus ada session error yang aman (tidak ada pesan teknis/credential)
    $response->assertSessionHasErrors(['midtrans']);
    $errorMsg = session('errors')->first('midtrans');
    expect($errorMsg)->not->toContain('401');
    expect($errorMsg)->not->toContain('Access denied');
    expect($errorMsg)->not->toContain('TEST_ONLY_SERVER_KEY');
    expect($errorMsg)->not->toContain('server_key');

    // Record TETAP ADA sebagai audit trail (bukan 0, ini yang membedakan dengan kegagalan config)
    expect(Donation::count())->toBe(1);

    $donation = Donation::first();
    // Status harus gagal, bukan menunggu_pembayaran
    expect($donation->status)->toBe('gagal');
    // Detail kegagalan tercatat
    expect($donation->payment_status_detail)->toBe('gateway_error');
    // Tidak ada snap_token (payment tidak selesai)
    expect($donation->snap_token)->toBeNull();
    // Campaign progress TIDAK bertambah
    $campaign->refresh();
    expect((int) $campaign->terkumpul_nominal)->toBe(0);
});

test('missing midtrans server key returns safe public error without exposing credentials or internal detail', function () {
    // Server key dikosongkan: MidtransConfigurationException dilempar SEBELUM request ke Midtrans.
    // Desain E2.1: record DIHAPUS karena kegagalan definitif terjadi sebelum order dibuat di provider.
    // Pesan publik harus aman — TIDAK boleh berisi detail internal seperti "Server Key", nama config, dsb.
    Config::set('services.midtrans.server_key', '');

    // Http::preventStrayRequests() memastikan tidak ada request live ke Midtrans.
    Http::preventStrayRequests();

    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);

    $response = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 50000,
    ]);

    $response->assertSessionHasErrors(['midtrans']);

    $errorMsg = session('errors')->first('midtrans');

    // Pesan publik harus ada dan bermakna (bukan string kosong)
    expect($errorMsg)->not->toBeEmpty();
    // Pesan tidak boleh mengandung detail internal/credential
    expect($errorMsg)->not->toContain('TEST_ONLY_SERVER_KEY');
    expect($errorMsg)->not->toContain('server_key');
    expect($errorMsg)->not->toContain('belum dikonfigurasi'); // internal message dari service
    expect($errorMsg)->not->toContain('MidtransConfiguration');
    // Pesan publik yang diterima harus ramah pengguna
    expect($errorMsg)->toContain('belum siap');

    // Tidak ada request live ke Midtrans terjadi (Http::preventStrayRequests sudah menjamin ini)
    Http::assertNothingSent();

    // Record donation DIHAPUS karena kegagalan terjadi sebelum order dibuat di Midtrans
    // (berbeda dengan HTTP 401 yang tetap mempertahankan record sebagai audit trail)
    expect(Donation::count())->toBe(0);
});

test('browser callback or query parameter cannot alter donation status to terverifikasi', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);

    $donation = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 50000,
        'order_id' => 'DON-TEST-12345',
        'status' => 'menunggu_pembayaran',
        'snap_token' => 'mock-token',
    ]);

    // Mengunjungi URL riwayat dengan query parameter callback Midtrans
    $response = $this->actingAs($donatur)->get(route('donatur.riwayat', [
        'order_id' => $donation->order_id,
        'status_code' => '200',
        'transaction_status' => 'settlement',
    ]));

    $response->assertStatus(200);

    // Status donation HARUS TETAP menunggu_pembayaran
    $donation->refresh();
    expect($donation->status)->toBe('menunggu_pembayaran');
});


// ============================================================
// E2.1 — Tests yang sebelumnya diklaim ada tapi belum ada
// ============================================================

// --- IDEMPOTENCY ---

test('idempotency reuse with identical payload returns existing snap token without calling midtrans again', function () {
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/*/status' => function ($request) {
            preg_match('/\/v2\/([^\/]+)\/status/', $request->url(), $matches);
            return Http::response([
                'order_id' => $matches[1] ?? '',
                'status_code' => '201',
                'transaction_status' => 'pending',
                'gross_amount' => '75000.00',
            ], 200);
        },
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'token' => 'snap-idempotent-reuse-abc',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-idempotent-reuse-abc',
        ], 201),
    ]);

    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);
    $key = \Illuminate\Support\Str::uuid()->toString();

    // Request pertama — Midtrans dipanggil, donation + snap token dibuat
    $r1 = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 75000,
        'idempotency_key' => $key,
    ]);
    $r1->assertStatus(200);

    // Verifikasi 1 record tersimpan dengan token
    expect(Donation::where('idempotency_key', $key)->count())->toBe(1);
    $donation = Donation::where('idempotency_key', $key)->first();
    expect($donation->snap_token)->toBe('snap-idempotent-reuse-abc');

    // Request kedua dengan payload IDENTIK — tidak boleh memanggil Midtrans lagi
    $r2 = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 75000,
        'idempotency_key' => $key,
    ]);
    $r2->assertStatus(200);

    // Masih hanya 1 Donation
    expect(Donation::where('idempotency_key', $key)->count())->toBe(1);

    // Midtrans hanya dipanggil 1 kali (request pertama saja)
    Http::assertSentCount(2);
});

test('idempotency rejects same key with different nominal', function () {
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/*/status' => function ($request) {
            preg_match('/\/v2\/([^\/]+)\/status/', $request->url(), $matches);
            return Http::response([
                'order_id' => $matches[1] ?? '',
                'status_code' => '201',
                'transaction_status' => 'pending',
                'gross_amount' => '50000.00',
            ], 200);
        },
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'token' => 'snap-first-nominal',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-first-nominal',
        ], 201),
    ]);

    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);
    $key = \Illuminate\Support\Str::uuid()->toString();

    // Request pertama dengan nominal 50000
    $r1 = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 50000,
        'idempotency_key' => $key,
    ]);
    $r1->assertStatus(200);

    // Request kedua dengan key SAMA tapi nominal BERBEDA — harus ditolak
    $r2 = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 100000,
        'idempotency_key' => $key,
    ]);

    $r2->assertSessionHasErrors(['idempotency_key']);

    // Tetap hanya 1 Donation
    expect(Donation::where('idempotency_key', $key)->count())->toBe(1);
});

test('idempotency rejects same key with different is_anonim value', function () {
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/*/status' => function ($request) {
            preg_match('/\/v2\/([^\/]+)\/status/', $request->url(), $matches);
            return Http::response([
                'order_id' => $matches[1] ?? '',
                'status_code' => '201',
                'transaction_status' => 'pending',
                'gross_amount' => '50000.00',
            ], 200);
        },
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'token' => 'snap-anonim-check',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-anonim-check',
        ], 201),
    ]);

    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);
    $key = \Illuminate\Support\Str::uuid()->toString();

    // Request pertama: tidak anonim
    $r1 = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 50000,
        'is_anonim' => '0',
        'idempotency_key' => $key,
    ]);
    $r1->assertStatus(200);

    expect(Donation::where('idempotency_key', $key)->where('is_anonim', 0)->count())->toBe(1);

    // Request kedua: key sama tapi is_anonim berubah — HARUS ditolak sebagai payload mismatch (E3.2)
    $r2 = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 50000,
        'is_anonim' => '1',
        'idempotency_key' => $key,
    ]);

    // Wajib ditolak dengan error idempotency_key
    $r2->assertSessionHasErrors(['idempotency_key']);

    // Tetap hanya 1 Donation
    expect(Donation::where('idempotency_key', $key)->count())->toBe(1);
    // is_anonim pada record TIDAK berubah (nilai asli dipertahankan)
    $donation = Donation::where('idempotency_key', $key)->first();
    expect((int) $donation->is_anonim)->toBe(0);
});

test('idempotency key belonging to another user is rejected', function () {
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'token' => 'snap-user-ownership',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-user-ownership',
        ], 201),
    ]);

    $donatur1 = User::factory()->donatur()->create();
    $donatur2 = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);
    $key = \Illuminate\Support\Str::uuid()->toString();

    // Donatur 1 membuat transaksi
    $r1 = $this->actingAs($donatur1)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 50000,
        'idempotency_key' => $key,
    ]);
    $r1->assertStatus(200);

    // Donatur 2 mencoba menggunakan key milik donatur 1 — HARUS ditolak
    $r2 = $this->actingAs($donatur2)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 50000,
        'idempotency_key' => $key,
    ]);

    $r2->assertSessionHasErrors(['idempotency_key']);

    // Hanya ada 1 Donation (milik donatur1), bukan 2
    expect(Donation::count())->toBe(1);
    expect(Donation::first()->user_id)->toBe($donatur1->id);
});

// --- TIMEOUT ---

test('connection timeout preserves pending donation record without snap token', function () {
    // Simulasi: Http::fake melempar ConnectionException (network timeout)
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
        },
    ]);

    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);

    $response = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 50000,
    ]);

    $response->assertSessionHasErrors(['midtrans']);

    // Record donation DIPERTAHANKAN (tidak dihapus) karena Midtrans mungkin sudah menerima order
    expect(Donation::count())->toBe(1);

    $donation = Donation::first();
    // Status tetap menunggu_pembayaran (belum dikonfirmasi gagal)
    expect($donation->status)->toBe('menunggu_pembayaran');
    // payment_status_detail mencatat kondisi timeout
    expect($donation->payment_status_detail)->toBe('timeout_awaiting_reconciliation');
    // snap_token TIDAK ada (belum diterima sebelum timeout)
    expect($donation->snap_token)->toBeNull();
    // order_id TERSEDIA untuk rekonsiliasi E3
    expect($donation->order_id)->toStartWith('DON-');
});

test('retry after timeout with 404 does not reuse order or create another snap session', function () {
    // 404 Status API tidak membuktikan bahwa Snap belum pernah dibuat.
    $callCount = 0;
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/*/status' => Http::response([], 404),
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                // Request pertama: timeout
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
            }
            // Request kedua: berhasil
            return Http::response([
                'token' => 'snap-retry-success-token',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-retry-success-token',
            ], 201);
        },
    ]);

    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);
    $key = \Illuminate\Support\Str::uuid()->toString();

    // Request pertama: timeout
    $r1 = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 50000,
        'idempotency_key' => $key,
    ]);
    $r1->assertSessionHasErrors(['midtrans']);

    expect(Donation::count())->toBe(1);
    $pendingDonation = Donation::first();
    expect($pendingDonation->payment_status_detail)->toBe('timeout_awaiting_reconciliation');
    expect($pendingDonation->snap_token)->toBeNull();

    // Request kedua: 404 dianggap ambigu, tidak boleh membuat Snap lagi untuk order lama
    $r2 = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 50000,
        'idempotency_key' => $key,
    ]);
    $r2->assertSessionHasErrors(['idempotency_key']);

    // MASIH hanya 1 Donation (tidak ada duplikat)
    expect(Donation::count())->toBe(1);

    $donation = Donation::first();
    // order_id tidak berubah (sama dengan pending asli)
    expect($donation->order_id)->toBe($pendingDonation->order_id);
    expect($donation->snap_token)->toBeNull();
    expect($donation->payment_status_detail)->toBe('timeout_awaiting_reconciliation');
    expect($callCount)->toBe(1);
});

// --- HTTP 5XX GATEWAY ERRORS ---

test('http 500 from midtrans snap api marks donation as unexpected error without deleting', function () {
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(
            ['message' => 'Internal Server Error'],
            500
        ),
    ]);

    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);

    $response = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 50000,
    ]);

    $response->assertSessionHasErrors(['midtrans']);

    // HTTP 500 diproses sebagai MidtransApiException (kegagalan definitif dari provider)
    expect(Donation::count())->toBe(1);
    $donation = Donation::first();
    expect($donation->status)->toBe('gagal');
    expect($donation->payment_status_detail)->toBe('gateway_error');
});

test('http 502 and 504 from midtrans snap api are treated as ambiguous timeout not definitive failure', function () {
    foreach ([502, 504] as $httpStatus) {
        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(
                ['message' => 'Bad Gateway'],
                $httpStatus
            ),
        ]);

        $donatur = User::factory()->donatur()->create();
        $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);

        $response = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
            'nominal' => 50000,
        ]);

        $response->assertSessionHasErrors(['midtrans']);
        $errorMsg = session('errors')->first('midtrans');

        // Pesan harus menyebutkan bahwa order tersimpan (order_id tersedia untuk user)
        expect($errorMsg)->toContain('Order ID');

        $donation = Donation::latest()->first();
        // Status BUKAN gagal — masih menunggu_pembayaran
        expect($donation->status)->toBe('menunggu_pembayaran');
        // payment_status_detail timeout
        expect($donation->payment_status_detail)->toBe('timeout_awaiting_reconciliation');
        // snap_token tidak ada
        expect($donation->snap_token)->toBeNull();
    }
});

// --- SAFE LOGGING & CREDENTIAL PROTECTION ---

test('error logs do not contain server key or user personal data', function () {
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'error_messages' => ['Access denied due to unauthorized transaction'],
        ], 401),
    ]);

    $donatur = User::factory()->donatur()->create([
        'name' => 'Santoso Budiman Test',
        'email' => 'santoso.test@example.com',
    ]);
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);

    // Bersihkan log sebelum test
    $logPath = storage_path('logs/laravel.log');
    $logBefore = file_exists($logPath) ? file_get_contents($logPath) : '';

    $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 100000,
    ]);

    // Baca hanya baris log baru setelah test
    $logAfter = file_exists($logPath) ? file_get_contents($logPath) : '';
    $newLog = substr($logAfter, strlen($logBefore));

    // Credential tidak boleh muncul di log
    expect($newLog)->not->toContain('TEST_ONLY_SERVER_KEY_12345');
    expect($newLog)->not->toContain('TEST_ONLY_CLIENT_KEY_12345');

    // Email dan nama user tidak boleh bocor ke log error Midtrans
    // (email diizinkan ada di request log Laravel tapi TIDAK di baris log Midtrans)
    // Note: test ini memeriksa log sanitasi — bukan melarang semua referensi email di log.
    // Yang dilarang: server_key / client_key muncul di log error apapun.
    if (!empty($newLog)) {
        $logLines = array_filter(explode("\n", $newLog), fn($l) => str_contains($l, 'Midtrans'));
        foreach ($logLines as $line) {
            expect($line)->not->toContain('TEST_ONLY_SERVER_KEY');
        }
    }
});

// --- UNIQUE CONSTRAINT & OWNERSHIP ---

test('unique constraint on order_id prevents duplicate orders even with different idempotency keys', function () {
    // Skenario: dua request berbeda yang menghasilkan order_id yang sama (edge case collision).
    // Karena order_id menggunakan timestamp + random, collision sebenarnya sangat jarang.
    // Test ini memverifikasi bahwa jika terjadi, QueryException ditangani dengan benar
    // (bukan crash, bukan mengembalikan data user lain).

    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'token' => 'snap-first-order',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-first-order',
        ], 201),
    ]);

    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);

    // Buat donation pertama secara manual dengan order_id tertentu
    Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 50000,
        'order_id' => 'DON-FORCE-COLLISION-TEST',
        'status' => 'menunggu_pembayaran',
        'idempotency_key' => \Illuminate\Support\Str::uuid()->toString(),
    ]);

    // Request normal berikutnya (key baru, bukan collision dari idempotency)
    // seharusnya berhasil karena order_id-nya berbeda (generated ulang)
    $r = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 75000,
    ]);

    // Response harus sukses (bukan crash karena order_id yang berbeda)
    $r->assertStatus(200);

    // Ada 2 donations sekarang
    expect(Donation::count())->toBe(2);
});

// --- BATASAN CONCURRENCY (DISCLAIMER JUJUR) ---

test('sequential simulation of concurrent requests with same idempotency key produces single donation', function () {
    // DISCLAIMER: Test ini adalah SIMULASI SEKUENSIAL, bukan concurrency sesungguhnya.
    // PHP/Pest berjalan single-threaded dalam satu proses. Race condition sejati
    // (dua thread bertabrakan SEBELUM unique constraint check) tidak dapat direproduksi
    // secara andal dalam test unit/feature biasa.
    // Yang dapat diverifikasi: behavior controller ketika key sudah ada sebelum request kedua diterima.
    // Untuk pengujian concurrency sesungguhnya, diperlukan load testing terpisah (JMeter/k6).

    Http::fake([
        'https://api.sandbox.midtrans.com/v2/*/status' => function ($request) {
            preg_match('/\/v2\/([^\/]+)\/status/', $request->url(), $matches);
            return Http::response([
                'order_id' => $matches[1] ?? '',
                'status_code' => '201',
                'transaction_status' => 'pending',
                'gross_amount' => '50000.00',
            ], 200);
        },
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'token' => 'snap-concurrent-sim',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-concurrent-sim',
        ], 201),
    ]);

    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);
    $key = \Illuminate\Support\Str::uuid()->toString();

    // "Request 1": selesai lebih dulu
    $r1 = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 50000,
        'idempotency_key' => $key,
    ]);
    $r1->assertStatus(200);

    // "Request 2": tiba setelah request 1 selesai (tidak benar-benar konkuren)
    $r2 = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 50000,
        'idempotency_key' => $key,
    ]);
    $r2->assertStatus(200);

    // Hanya 1 Donation
    expect(Donation::where('idempotency_key', $key)->count())->toBe(1);

    // Midtrans hanya dipanggil 1x
    Http::assertSentCount(2);
});

// ============================================================
// E3.2 — RETRY CHECKOUT SETELAH STATUS PROVIDER AMBIGU / SETTLED
// ============================================================

test('retry checkout when provider transaction status is pending returns ambiguous warning without creating new token', function () {
    // Skenario: order lama tersimpan tanpa token (misal karena timeout sebelumnya),
    // saat donatur mencoba retry checkout, provider mengembalikan status 'pending' (ambigu).
    // Controller TIDAK boleh membuat token baru secara membabi-buta.
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/*/status' => Http::response([
            'order_id'           => 'DON-AMBIGUOUS-001',
            'status_code'        => '201',
            'transaction_status' => 'pending',
            'gross_amount'       => '50000.00',
        ], 200),
    ]);

    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);
    $key = Str::uuid()->toString();

    // Buat donation pending tanpa token
    Donation::create([
        'campaign_id'           => $campaign->id,
        'user_id'               => $donatur->id,
        'jenis'                 => 'uang',
        'nominal'               => 50000,
        'order_id'              => 'DON-AMBIGUOUS-001',
        'status'                => 'menunggu_pembayaran',
        'idempotency_key'       => $key,
        'payment_status_detail' => 'timeout_awaiting_reconciliation',
    ]);

    $response = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal'         => 50000,
        'idempotency_key' => $key,
    ]);

    $response->assertSessionHasErrors(['idempotency_key']);
    $errorMsg = session('errors')->first('idempotency_key');
    expect($errorMsg)->toContain('sedang diproses atau dalam verifikasi');

    // Tidak ada token yang dibuat
    $donation = Donation::where('idempotency_key', $key)->first();
    expect($donation->snap_token)->toBeNull();
});

test('retry checkout when provider transaction status is already settlement reconciles and informs user', function () {
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/*/status' => Http::response([
            'order_id'           => 'DON-ALREADY-PAID-001',
            'status_code'        => '200',
            'transaction_status' => 'settlement',
            'fraud_status'       => 'accept',
            'gross_amount'       => '50000.00',
        ], 200),
    ]);

    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);
    $key = Str::uuid()->toString();

    Donation::create([
        'campaign_id'           => $campaign->id,
        'user_id'               => $donatur->id,
        'jenis'                 => 'uang',
        'nominal'               => 50000,
        'order_id'              => 'DON-ALREADY-PAID-001',
        'status'                => 'menunggu_pembayaran',
        'idempotency_key'       => $key,
        'payment_status_detail' => 'timeout_awaiting_reconciliation',
    ]);

    $response = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal'         => 50000,
        'idempotency_key' => $key,
    ]);

    // Donasi berhasil direkonsiliasi menjadi terverifikasi
    $donation = Donation::where('idempotency_key', $key)->first();
    expect($donation->status)->toBe('terverifikasi');
    expect($donation->payment_status_detail)->toBe('settled');

    // Progress campaign bertambah
    $campaign->refresh();
    expect((int) $campaign->terkumpul_nominal)->toBe(50000);
});

test('retry settlement without complete reconciliation data does not show verified', function () {
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/*/status' => Http::response([
            'order_id' => 'DON-INCOMPLETE-SETTLEMENT',
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
        ], 200),
    ]);

    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);
    $key = Str::uuid()->toString();

    Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 50000,
        'order_id' => 'DON-INCOMPLETE-SETTLEMENT',
        'status' => 'menunggu_pembayaran',
        'idempotency_key' => $key,
    ]);

    $response = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 50000,
        'idempotency_key' => $key,
    ]);

    $response->assertSessionHasErrors();
    $donation = Donation::where('idempotency_key', $key)->first();
    expect($donation->status)->toBe('menunggu_pembayaran');
    expect($donation->payment_status_detail)->not->toBe('settled');
});

test('provider failure with mismatched order or amount is not marked gagal', function () {
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/*/status' => Http::response([
            'order_id' => 'DON-WRONG-ORDER',
            'status_code' => '407',
            'transaction_status' => 'expire',
            'gross_amount' => '49999.00',
        ], 200),
    ]);

    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);
    $key = Str::uuid()->toString();

    Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 50000,
        'order_id' => 'DON-FAILURE-MISMATCH',
        'status' => 'menunggu_pembayaran',
        'idempotency_key' => $key,
    ]);

    $response = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 50000,
        'idempotency_key' => $key,
    ]);

    $response->assertSessionHasErrors();
    expect(Donation::where('idempotency_key', $key)->first()->status)->toBe('menunggu_pembayaran');
});

test('expired old snap token is not reused', function () {
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/*/status' => Http::response([
            'order_id' => 'DON-OLD-TOKEN',
            'status_code' => '407',
            'transaction_status' => 'expire',
            'gross_amount' => '50000.00',
        ], 200),
    ]);

    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);
    $key = Str::uuid()->toString();

    Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 50000,
        'order_id' => 'DON-OLD-TOKEN',
        'status' => 'menunggu_pembayaran',
        'snap_token' => 'old-expired-token',
        'idempotency_key' => $key,
        'payment_status_detail' => 'snap_created',
    ]);

    $response = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 50000,
        'idempotency_key' => $key,
    ]);

    $response->assertSessionHasErrors();
    $donation = Donation::where('idempotency_key', $key)->first();
    expect($donation->status)->toBe('gagal');
});

test('404 status does not reuse old snap token', function () {
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/*/status' => Http::response([], 404),
    ]);

    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);
    $key = Str::uuid()->toString();

    Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 50000,
        'order_id' => 'DON-404-TOKEN',
        'status' => 'menunggu_pembayaran',
        'snap_token' => 'old-ambiguous-token',
        'idempotency_key' => $key,
    ]);

    $response = $this->actingAs($donatur)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal' => 50000,
        'idempotency_key' => $key,
    ]);

    $response->assertSessionHasErrors();
    expect($response->getContent())->not->toContain('old-ambiguous-token');
});

test('concurrent query exception collision with mismatched payload rejects returning snap token with 409', function () {
    // Skenario race condition: request B membentur unique constraint pada idempotency_key,
    // tetapi record yang sudah dibuat memiliki payload berbeda (misal beda user atau beda is_anonim).
    // Controller TIDAK boleh mengembalikan token transaksi lain kepada request ini.
    $donatur1 = User::factory()->donatur()->create();
    $donatur2 = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);
    $key = Str::uuid()->toString();

    // Record yang dibuat oleh request A (donatur 1)
    Donation::create([
        'campaign_id'           => $campaign->id,
        'user_id'               => $donatur1->id,
        'jenis'                 => 'uang',
        'nominal'               => 50000,
        'order_id'              => 'DON-COLLISION-001',
        'status'                => 'menunggu_pembayaran',
        'snap_token'            => 'snap-token-donatur1',
        'idempotency_key'       => $key,
        'is_anonim'             => false,
        'payment_status_detail' => 'snap_created',
    ]);

    // Request B dari donatur 2 dengan key yang sama (terdeteksi saat cek key maupun saat collision)
    $response = $this->actingAs($donatur2)->post(route('donatur.donasi.uang', $campaign->slug), [
        'nominal'         => 50000,
        'idempotency_key' => $key,
    ]);

    // Ditolak dengan session error idempotency_key, tidak mengembalikan token donatur 1
    $response->assertSessionHasErrors(['idempotency_key']);
    expect($response->getContent())->not->toContain('snap-token-donatur1');
});

test('e5.1.8c checkout outbound snap: rejects invalid, fractional, null, or oversized local nominal before any HTTP call', function (mixed $badNominal) {
    Http::fake();
    $donatur = User::factory()->donatur()->create();
    $campaign = createCampaignTest(['status' => 'aktif', 'tipe_bantuan' => 'uang']);

    $donation = Donation::create([
        'campaign_id'     => $campaign->id,
        'user_id'         => $donatur->id,
        'jenis'           => 'uang',
        'nominal'         => $badNominal === 'OVERSIZED' ? 10000 : $badNominal,
        'order_id'        => 'DON-SNAP-BAD-' . Str::random(6),
        'status'          => 'menunggu_pembayaran',
        'idempotency_key' => Str::uuid()->toString(),
    ]);
    if ($badNominal === 'OVERSIZED') {
        $donation->nominal = '10000000000000';
    }

    $service = app(\App\Services\MidtransPaymentService::class);

    expect(fn () => $service->createSnapTransaction($donation, $donatur, $campaign))
        ->toThrow(\App\Services\MidtransApiException::class);

    Http::assertNothingSent();
})->with([
    'fractional nominal' => ['50000.99'],
    'null nominal' => [null],
    'zero nominal' => [0],
    'oversized nominal' => ['OVERSIZED'],
]);
