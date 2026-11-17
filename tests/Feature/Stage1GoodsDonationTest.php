<?php

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\ItemCategory;
use App\Models\Shipment;
use App\Models\User;
use App\Services\CampaignProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Setup Helpers
|--------------------------------------------------------------------------
*/

function createDonaturUser(): User
{
    return User::factory()->donatur()->create();
}

function createPenerimaUser(): User
{
    return User::factory()->penerima()->create();
}

function createItemCategory(string $nama = 'Pakaian'): ItemCategory
{
    return ItemCategory::create([
        'nama' => $nama,
        'slug' => strtolower(str_replace(' ', '-', $nama)),
    ]);
}

function createCampaignGoods(User $penerima, string $status = 'aktif', string $tipe = 'barang'): Campaign
{
    return Campaign::create([
        'user_id'          => $penerima->id,
        'judul'            => 'Campaign Barang ' . uniqid(),
        'slug'             => 'campaign-barang-' . uniqid(),
        'deskripsi'        => 'Deskripsi campaign barang pengujian',
        'tipe_bantuan'     => $tipe,
        'target_nominal'   => in_array($tipe, ['uang', 'keduanya']) ? 50000000 : null,
        'terkumpul_nominal' => 0,
        'target_barang'    => in_array($tipe, ['barang', 'keduanya']) ? 100 : null,
        'satuan_barang'    => in_array($tipe, ['barang', 'keduanya']) ? 'pcs' : null,
        'terkumpul_barang' => 0,
        'urgensi'          => 'biasa',
        'tanggal_mulai'    => now()->subDays(5),
        'tanggal_selesai'  => now()->addDays(25),
        'status'           => $status,
    ]);
}

/*
|--------------------------------------------------------------------------
| 1. Form, Campaign, dan Kategori (#55)
|--------------------------------------------------------------------------
*/

it('donatur login dapat mengakses GET form barang pada campaign aktif tipe barang', function () {
    $donatur  = createDonaturUser();
    $penerima = createPenerimaUser();
    $campaign = createCampaignGoods($penerima, 'aktif', 'barang');
    $kategori = createItemCategory('Pakaian');

    $this->actingAs($donatur)
        ->get(route('donatur.donasi.barang', $campaign->slug))
        ->assertOk()
        ->assertViewIs('donatur.donasi-barang')
        ->assertViewHas('campaign', fn ($c) => $c->id === $campaign->id)
        ->assertSee($campaign->judul);
});

it('GET dan POST donasi barang menerima campaign tipe keduanya, namun menolak campaign tipe uang atau nonaktif', function () {
    $donatur  = createDonaturUser();
    $penerima = createPenerimaUser();
    $kategori = createItemCategory('Pakaian');

    // Campaign keduanya -> OK
    $campaignKeduanya = createCampaignGoods($penerima, 'aktif', 'keduanya');
    $this->actingAs($donatur)
        ->get(route('donatur.donasi.barang', $campaignKeduanya->slug))
        ->assertOk();

    // Campaign tipe uang murni -> 404
    $campaignUang = createCampaignGoods($penerima, 'aktif', 'uang');
    $this->actingAs($donatur)
        ->get(route('donatur.donasi.barang', $campaignUang->slug))
        ->assertNotFound();

    // Campaign barang nonaktif (draft / pending_approval) -> 404
    $campaignPending = createCampaignGoods($penerima, 'pending_approval', 'barang');
    $this->actingAs($donatur)
        ->get(route('donatur.donasi.barang', $campaignPending->slug))
        ->assertNotFound();

    // POST ke campaign tipe uang -> 404
    $this->actingAs($donatur)
        ->post(route('donatur.donasi.barang', $campaignUang->slug), [
            'nama_barang' => 'Barang',
        ])
        ->assertNotFound();
});

it('guest dialihkan ke login dan user penerima/admin ditolak dari route donasi barang', function () {
    $penerima = createPenerimaUser();
    $campaign = createCampaignGoods($penerima, 'aktif', 'barang');

    // Guest -> redirect login
    $this->get(route('donatur.donasi.barang', $campaign->slug))
        ->assertRedirect('/login');

    // Penerima -> 403 Forbidden
    $this->actingAs($penerima)
        ->get(route('donatur.donasi.barang', $campaign->slug))
        ->assertForbidden();
});

it('kategori makanan tidak tampil pada dropdown dan ditolak bila di-POST secara manual', function () {
    Storage::fake('public');
    $donatur  = createDonaturUser();
    $penerima = createPenerimaUser();
    $campaign = createCampaignGoods($penerima, 'aktif', 'barang');

    $katBuku    = createItemCategory('Buku');
    $katMakanan = createItemCategory('Makanan');

    // 1. Dropdown form tidak memuat kategori makanan
    $response = $this->actingAs($donatur)
        ->get(route('donatur.donasi.barang', $campaign->slug));
    $response->assertOk();
    $response->assertSee($katBuku->nama);
    $response->assertDontSee('value="' . $katMakanan->id . '"', false);

    // 2. POST dengan category ID makanan ditolak server-side
    $postResponse = $this->actingAs($donatur)
        ->post(route('donatur.donasi.barang', $campaign->slug), [
            'nama_barang'       => 'Nasi Kotak',
            'item_category_id'  => $katMakanan->id,
            'jumlah_barang'     => 10,
            'berat_gram'        => 2000,
            'foto_barang'       => UploadedFile::fake()->image('makanan.jpg'),
            'metode_pengiriman' => 'diantar_langsung',
        ]);

    $postResponse->assertSessionHasErrors('item_category_id');
    expect(Donation::count())->toBe(0);
});

it('kategori yang di-soft-delete ditolak oleh validasi backend', function () {
    Storage::fake('public');
    $donatur  = createDonaturUser();
    $penerima = createPenerimaUser();
    $campaign = createCampaignGoods($penerima, 'aktif', 'barang');

    $kategori = createItemCategory('Elektronik');
    $kategori->delete(); // Soft delete

    $response = $this->actingAs($donatur)
        ->post(route('donatur.donasi.barang', $campaign->slug), [
            'nama_barang'       => 'Radio Bekas',
            'item_category_id'  => $kategori->id,
            'jumlah_barang'     => 1,
            'berat_gram'        => 500,
            'foto_barang'       => UploadedFile::fake()->image('radio.jpg'),
            'metode_pengiriman' => 'diantar_langsung',
        ]);

    $response->assertSessionHasErrors('item_category_id');
    expect(Donation::count())->toBe(0);
});

it('POST donasi barang yang valid menyimpan berat_gram, foto, status menunggu_konfirmasi, dan tanggal_diterima null', function () {
    Storage::fake('public');
    $donatur  = createDonaturUser();
    $penerima = createPenerimaUser();
    $campaign = createCampaignGoods($penerima, 'aktif', 'barang');
    $kategori = createItemCategory('Pakaian');

    $foto = UploadedFile::fake()->image('selimut.jpg', 600, 600);

    $response = $this->actingAs($donatur)
        ->post(route('donatur.donasi.barang', $campaign->slug), [
            'nama_barang'       => 'Selimut Tebal',
            'item_category_id'  => $kategori->id,
            'jumlah_barang'     => 15,
            'berat_gram'        => 4500,
            'foto_barang'       => $foto,
            'catatan'           => 'Barang baru dan higienis',
            'is_anonim'         => '1',
            'metode_pengiriman' => 'diantar_langsung',
        ]);

    $response->assertRedirect(route('donatur.campaign.show', $campaign->slug));
    $response->assertSessionHas('success');

    $donation = Donation::first();
    expect($donation)->not->toBeNull();
    expect($donation->campaign_id)->toBe($campaign->id);
    expect($donation->user_id)->toBe($donatur->id);
    expect($donation->nama_barang)->toBe('Selimut Tebal');
    expect($donation->jumlah_barang)->toBe(15);
    expect($donation->berat_gram)->toBe(4500);
    expect($donation->is_anonim)->toBeTrue();
    expect($donation->catatan)->toBe('Barang baru dan higienis');
    expect($donation->status)->toBe('menunggu_konfirmasi');
    expect($donation->tanggal_diterima)->toBeNull();

    // Verifikasi foto tersimpan di disk public
    Storage::disk('public')->assertExists($donation->foto_barang);

    // Donasi baru TIDAK menambah terkumpul_barang
    $campaign->refresh();
    expect($campaign->terkumpul_barang)->toBe(0);
});

it('validasi menolak jumlah/berat tidak valid dan foto tidak valid tanpa menyimpan record atau file yatim', function () {
    Storage::fake('public');
    $donatur  = createDonaturUser();
    $penerima = createPenerimaUser();
    $campaign = createCampaignGoods($penerima, 'aktif', 'barang');
    $kategori = createItemCategory('Mainan');

    // Jumlah <= 0, berat kosong, foto file teks palsu
    $response = $this->actingAs($donatur)
        ->post(route('donatur.donasi.barang', $campaign->slug), [
            'nama_barang'       => 'Boneka',
            'item_category_id'  => $kategori->id,
            'jumlah_barang'     => 0,
            'berat_gram'        => -50,
            'foto_barang'       => UploadedFile::fake()->create('dokumen.pdf', 100),
            'metode_pengiriman' => 'diantar_langsung',
        ]);

    $response->assertSessionHasErrors(['jumlah_barang', 'berat_gram', 'foto_barang']);
    expect(Donation::count())->toBe(0);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| 2. Pilihan Ekspedisi dan Shipment (#57)
|--------------------------------------------------------------------------
*/

it('metode dikirim_sendiri dengan ekspedisi JNE dan resi valid membuat tepat satu Shipment berstatus dikirim', function () {
    Storage::fake('public');
    $donatur  = createDonaturUser();
    $penerima = createPenerimaUser();
    $campaign = createCampaignGoods($penerima, 'aktif', 'barang');
    $kategori = createItemCategory('Buku');

    $response = $this->actingAs($donatur)
        ->post(route('donatur.donasi.barang', $campaign->slug), [
            'nama_barang'       => 'Buku Pelajaran',
            'item_category_id'  => $kategori->id,
            'jumlah_barang'     => 20,
            'berat_gram'        => 3000,
            'foto_barang'       => UploadedFile::fake()->image('buku.jpg'),
            'metode_pengiriman' => 'dikirim_sendiri',
            'ekspedisi'         => 'JNE',
            'no_resi'           => ' JNE123456789 ',
        ]);

    $response->assertRedirect(route('donatur.campaign.show', $campaign->slug));

    $donation = Donation::with('Shipment')->first();
    expect($donation->Shipment)->not->toBeNull();
    expect($donation->Shipment->ekspedisi)->toBe('JNE');
    expect($donation->Shipment->no_resi)->toBe('JNE123456789'); // di-trim
    expect($donation->Shipment->status)->toBe('dikirim');
    expect($donation->Shipment->estimasi_ongkir)->toBeNull();
    expect($donation->Shipment->last_tracked_at)->toBeNull();
});

it('metode dikirim_sendiri mendukung kurir J&T Express dengan karakter ampersand', function () {
    Storage::fake('public');
    $donatur  = createDonaturUser();
    $penerima = createPenerimaUser();
    $campaign = createCampaignGoods($penerima, 'aktif', 'barang');
    $kategori = createItemCategory('Buku');

    $response = $this->actingAs($donatur)
        ->post(route('donatur.donasi.barang', $campaign->slug), [
            'nama_barang'       => 'Alat Tulis',
            'item_category_id'  => $kategori->id,
            'jumlah_barang'     => 5,
            'berat_gram'        => 1000,
            'foto_barang'       => UploadedFile::fake()->image('alattulis.jpg'),
            'metode_pengiriman' => 'dikirim_sendiri',
            'ekspedisi'         => 'J&T Express',
            'no_resi'           => 'JT987654321',
        ]);

    $response->assertRedirect(route('donatur.campaign.show', $campaign->slug));

    $donation = Donation::with('Shipment')->first();
    expect($donation->Shipment->ekspedisi)->toBe('J&T Express');
    expect($donation->Shipment->no_resi)->toBe('JT987654321');
});

it('metode dikirim_sendiri mewajibkan ekspedisi terdaftar dan nomor resi', function () {
    Storage::fake('public');
    $donatur  = createDonaturUser();
    $penerima = createPenerimaUser();
    $campaign = createCampaignGoods($penerima, 'aktif', 'barang');
    $kategori = createItemCategory('Buku');

    // Ekspedisi fiktif dan resi kosong
    $response = $this->actingAs($donatur)
        ->post(route('donatur.donasi.barang', $campaign->slug), [
            'nama_barang'       => 'Buku',
            'item_category_id'  => $kategori->id,
            'jumlah_barang'     => 5,
            'berat_gram'        => 1000,
            'foto_barang'       => UploadedFile::fake()->image('buku.jpg'),
            'metode_pengiriman' => 'dikirim_sendiri',
            'ekspedisi'         => 'WahanaEkspres', // Tidak ada di whitelist
            'no_resi'           => '',
        ]);

    $response->assertSessionHasErrors(['ekspedisi', 'no_resi']);
    expect(Shipment::count())->toBe(0);
    expect(Donation::count())->toBe(0);
});

it('metode diantar_langsung tidak membuat record Shipment meskipun form mengirim resi/ekspedisi stale', function () {
    Storage::fake('public');
    $donatur  = createDonaturUser();
    $penerima = createPenerimaUser();
    $campaign = createCampaignGoods($penerima, 'aktif', 'barang');
    $kategori = createItemCategory('Pakaian');

    $response = $this->actingAs($donatur)
        ->post(route('donatur.donasi.barang', $campaign->slug), [
            'nama_barang'       => 'Pakaian Hangat',
            'item_category_id'  => $kategori->id,
            'jumlah_barang'     => 12,
            'berat_gram'        => 2500,
            'foto_barang'       => UploadedFile::fake()->image('pakaian.jpg'),
            'metode_pengiriman' => 'diantar_langsung',
            'ekspedisi'         => 'SiCepat', // stale input dari UI
            'no_resi'           => 'SICEPAT12345', // stale input dari UI
        ]);

    $response->assertRedirect(route('donatur.campaign.show', $campaign->slug));

    $donation = Donation::with('Shipment')->first();
    expect($donation->metode_pengiriman)->toBe('diantar_langsung');
    expect($donation->Shipment)->toBeNull(); // Tidak ada shipment
    expect(Shipment::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| 3. Konfirmasi Manual Penerima dan Progress Idempotent (#59)
|--------------------------------------------------------------------------
*/

it('penerima pemilik campaign berhasil konfirmasi barang: status donation dan shipment menjadi diterima serta progress terhitung', function () {
    $penerima = createPenerimaUser();
    $donatur  = createDonaturUser();
    $campaign = createCampaignGoods($penerima, 'aktif', 'barang');
    $kategori = createItemCategory('Pakaian');

    $donation = Donation::create([
        'campaign_id'       => $campaign->id,
        'user_id'           => $donatur->id,
        'jenis'             => 'barang',
        'item_category_id'  => $kategori->id,
        'nama_barang'       => 'Jaket Tebal',
        'jumlah_barang'     => 25,
        'satuan_barang'     => 'pcs',
        'berat_gram'        => 7500,
        'foto_barang'       => 'donations/items/dummy.jpg',
        'metode_pengiriman' => 'dikirim_sendiri',
        'status'            => 'menunggu_konfirmasi',
    ]);

    $shipment = $donation->Shipment()->create([
        'ekspedisi' => 'JNE',
        'no_resi'   => 'JNE00112233',
        'status'    => 'dikirim',
    ]);

    expect($campaign->fresh()->terkumpul_barang)->toBe(0);

    // Penerima konfirmasi donasi
    $response = $this->actingAs($penerima)
        ->from(route('mitra.donasi-masuk'))
        ->put("/mitra/donasi-masuk/{$donation->id}/konfirmasi");

    $response->assertRedirect(route('mitra.donasi-masuk'));
    $response->assertSessionHas('success', 'Donasi barang berhasil dikonfirmasi!');

    // Verifikasi status Donation dan tanggal_diterima
    $donation->refresh();
    expect($donation->status)->toBe('diterima');
    expect($donation->tanggal_diterima)->not->toBeNull();

    // Verifikasi status Shipment berubah ke diterima
    $shipment->refresh();
    expect($shipment->status)->toBe('diterima');

    // Verifikasi progress campaign disinkronkan via service
    expect($campaign->fresh()->terkumpul_barang)->toBe(25);
});

it('konfirmasi barang dengan metode diantar_langsung (tanpa shipment) tetap sukses dan progress terhitung', function () {
    $penerima = createPenerimaUser();
    $donatur  = createDonaturUser();
    $campaign = createCampaignGoods($penerima, 'aktif', 'barang');
    $kategori = createItemCategory('Sembako');

    $donation = Donation::create([
        'campaign_id'       => $campaign->id,
        'user_id'           => $donatur->id,
        'jenis'             => 'barang',
        'item_category_id'  => $kategori->id,
        'nama_barang'       => 'Paket Perlengkapan Mandi',
        'jumlah_barang'     => 10,
        'satuan_barang'     => 'paket',
        'berat_gram'        => 5000,
        'foto_barang'       => 'donations/items/dummy.jpg',
        'metode_pengiriman' => 'diantar_langsung',
        'status'            => 'menunggu_konfirmasi',
    ]);

    $response = $this->actingAs($penerima)
        ->put("/mitra/donasi-masuk/{$donation->id}/konfirmasi");

    $response->assertRedirect();
    $donation->refresh();
    expect($donation->status)->toBe('diterima');
    expect($campaign->fresh()->terkumpul_barang)->toBe(10);
});

it('konfirmasi berulang (repeat request) bersifat idempotent dan tidak menambah progress ganda', function () {
    $penerima = createPenerimaUser();
    $donatur  = createDonaturUser();
    $campaign = createCampaignGoods($penerima, 'aktif', 'barang');
    $kategori = createItemCategory('Pakaian');

    $donation = Donation::create([
        'campaign_id'       => $campaign->id,
        'user_id'           => $donatur->id,
        'jenis'             => 'barang',
        'item_category_id'  => $kategori->id,
        'nama_barang'       => 'Kaos',
        'jumlah_barang'     => 30,
        'status'            => 'menunggu_konfirmasi',
    ]);

    // Konfirmasi pertama -> Berhasil
    $this->actingAs($penerima)
        ->put("/mitra/donasi-masuk/{$donation->id}/konfirmasi")
        ->assertRedirect();

    $donation->refresh();
    $firstTimestamp = $donation->tanggal_diterima;
    expect($campaign->fresh()->terkumpul_barang)->toBe(30);

    // Konfirmasi kedua untuk record yang sama -> ditolak 404 (karena status sudah bukan menunggu_konfirmasi)
    $this->actingAs($penerima)
        ->put("/mitra/donasi-masuk/{$donation->id}/konfirmasi")
        ->assertNotFound();

    // Data tidak berubah, tidak ada double count
    $donation->refresh();
    expect($donation->status)->toBe('diterima');
    expect($donation->tanggal_diterima->toDateTimeString())->toBe($firstTimestamp->toDateTimeString());
    expect($campaign->fresh()->terkumpul_barang)->toBe(30);
});

it('penerima lain tidak dapat mengonfirmasi donasi milik campaign orang lain', function () {
    $penerimaA = createPenerimaUser();
    $penerimaB = createPenerimaUser();
    $donatur   = createDonaturUser();
    $campaignA = createCampaignGoods($penerimaA, 'aktif', 'barang');
    $kategori  = createItemCategory('Pakaian');

    $donation = Donation::create([
        'campaign_id'      => $campaignA->id,
        'user_id'          => $donatur->id,
        'jenis'            => 'barang',
        'item_category_id' => $kategori->id,
        'nama_barang'      => 'Selimut',
        'jumlah_barang'    => 10,
        'status'           => 'menunggu_konfirmasi',
    ]);

    // Penerima B mencoba konfirmasi -> 404 Not Found
    $this->actingAs($penerimaB)
        ->put("/mitra/donasi-masuk/{$donation->id}/konfirmasi")
        ->assertNotFound();

    $donation->refresh();
    expect($donation->status)->toBe('menunggu_konfirmasi');
    expect($campaignA->fresh()->terkumpul_barang)->toBe(0);
});

it('donatur atau guest tidak dapat memanggil endpoint konfirmasi donasi barang', function () {
    $penerima = createPenerimaUser();
    $donatur  = createDonaturUser();
    $campaign = createCampaignGoods($penerima, 'aktif', 'barang');

    $donation = Donation::create([
        'campaign_id'   => $campaign->id,
        'user_id'       => $donatur->id,
        'jenis'         => 'barang',
        'nama_barang'   => 'Baju',
        'jumlah_barang' => 5,
        'status'        => 'menunggu_konfirmasi',
    ]);

    // Guest -> redirect login
    $this->put("/mitra/donasi-masuk/{$donation->id}/konfirmasi")
        ->assertRedirect('/login');

    // Donatur -> 403 Forbidden
    $this->actingAs($donatur)
        ->put("/mitra/donasi-masuk/{$donation->id}/konfirmasi")
        ->assertForbidden();

    expect($donation->fresh()->status)->toBe('menunggu_konfirmasi');
});

it('donasi uang tidak dapat dikonfirmasi lewat endpoint konfirmasi donasi barang', function () {
    $penerima = createPenerimaUser();
    $donatur  = createDonaturUser();
    $campaign = createCampaignGoods($penerima, 'aktif', 'keduanya');

    $donasiUang = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id'     => $donatur->id,
        'jenis'       => 'uang',
        'nominal'     => 100000,
        'status'      => 'menunggu_pembayaran',
    ]);

    $this->actingAs($penerima)
        ->put("/mitra/donasi-masuk/{$donasiUang->id}/konfirmasi")
        ->assertNotFound();

    expect($donasiUang->fresh()->status)->toBe('menunggu_pembayaran');
});

it('beberapa donasi pada campaign yang sama terakumulasi secara tepat dan terisolasi dari donasi uang', function () {
    $penerima = createPenerimaUser();
    $donatur  = createDonaturUser();
    $campaign = createCampaignGoods($penerima, 'aktif', 'keduanya');
    $kategori = createItemCategory('Pakaian');

    // Donasi barang 1: 15 pcs
    $d1 = Donation::create([
        'campaign_id'       => $campaign->id,
        'user_id'           => $donatur->id,
        'jenis'             => 'barang',
        'item_category_id'  => $kategori->id,
        'nama_barang'       => 'Kemeja',
        'jumlah_barang'     => 15,
        'status'            => 'menunggu_konfirmasi',
    ]);

    // Donasi barang 2: 25 pcs
    $d2 = Donation::create([
        'campaign_id'       => $campaign->id,
        'user_id'           => $donatur->id,
        'jenis'             => 'barang',
        'item_category_id'  => $kategori->id,
        'nama_barang'       => 'Celana',
        'jumlah_barang'     => 25,
        'status'            => 'menunggu_konfirmasi',
    ]);

    // Donasi uang: Rp 500.000
    $dUang = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id'     => $donatur->id,
        'jenis'       => 'uang',
        'nominal'     => 500000,
        'status'      => 'terverifikasi',
    ]);

    // Konfirmasi hanya d1
    $this->actingAs($penerima)
        ->put("/mitra/donasi-masuk/{$d1->id}/konfirmasi");

    $campaign->refresh();
    expect($campaign->terkumpul_barang)->toBe(15);

    // Konfirmasi d2
    $this->actingAs($penerima)
        ->put("/mitra/donasi-masuk/{$d2->id}/konfirmasi");

    $campaign->refresh();
    expect($campaign->terkumpul_barang)->toBe(40); // 15 + 25

    // Terkumpul nominal tetap mandiri (dihitung saat sync progress uang)
    (new CampaignProgressService())->sync($campaign);
    $campaign->refresh();
    expect((float) $campaign->terkumpul_nominal)->toBe(500000.0);
    expect($campaign->terkumpul_barang)->toBe(40);
});
