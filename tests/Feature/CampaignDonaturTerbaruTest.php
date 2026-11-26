<?php

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

beforeEach(function () {
    $dbName = config('database.connections.mysql.database');
    if ($dbName !== 'wecare_testing') {
        throw new \RuntimeException("SAFETY VIOLATION: Tests must run on wecare_testing, currently on {$dbName}");
    }
});

function createDonaturTerbaruCampaign(): array
{
    $penerima = User::factory()->penerima()->create();
    $campaign = Campaign::create([
        'user_id'           => $penerima->id,
        'judul'             => 'Campaign Donatur Terbaru',
        'slug'              => 'campaign-donatur-terbaru-' . Str::random(8),
        'deskripsi'         => 'Campaign untuk pengujian Donatur Terbaru',
        'tipe_bantuan'      => 'uang',
        'target_nominal'     => 1000000,
        'terkumpul_nominal' => 100000,
        'urgensi'           => 'biasa',
        'tanggal_mulai'     => now()->subDay()->toDateString(),
        'tanggal_selesai'   => now()->addMonth()->toDateString(),
        'status'            => 'aktif',
    ]);

    return [$penerima, $campaign];
}

test('campaign detail only shows successful money donations and confirmed goods donations', function () {
    [, $campaign] = createDonaturTerbaruCampaign();
    $donatur = User::factory()->donatur()->create(['name' => 'Donatur Sah']);
    $anonymous = User::factory()->donatur()->create(['name' => 'Nama Disembunyikan']);

    Donation::create([
        'campaign_id' => $campaign->id,
        'user_id'     => $donatur->id,
        'jenis'       => 'uang',
        'nominal'     => 50000,
        'status'      => 'terverifikasi',
        'is_anonim'   => false,
        'order_id'    => 'SUCCESS-MONEY-' . Str::random(6),
    ]);
    Donation::create([
        'campaign_id' => $campaign->id,
        'user_id'     => $anonymous->id,
        'jenis'       => 'uang',
        'nominal'     => 50000,
        'status'      => 'gagal',
        'is_anonim'   => true,
        'order_id'    => 'FAILED-MONEY-' . Str::random(6),
    ]);
    Donation::create([
        'campaign_id'  => $campaign->id,
        'user_id'      => $donatur->id,
        'jenis'        => 'uang',
        'nominal'      => 50000,
        'status'       => 'menunggu_pembayaran',
        'is_anonim'    => false,
        'order_id'     => 'PENDING-MONEY-' . Str::random(6),
    ]);
    Donation::create([
        'campaign_id' => $campaign->id,
        'user_id'     => $anonymous->id,
        'jenis'       => 'barang',
        'nama_barang' => 'Berasku',
        'jumlah_barang' => 10,
        'satuan_barang' => 'kg',
        'status'      => 'menunggu_konfirmasi',
        'is_anonim'   => true,
    ]);
    Donation::create([
        'campaign_id' => $campaign->id,
        'user_id'     => $anonymous->id,
        'jenis'       => 'barang',
        'nama_barang' => 'Selimut',
        'jumlah_barang' => 3,
        'satuan_barang' => 'pcs',
        'status'      => 'terverifikasi',
        'is_anonim'   => true,
    ]);

    $response = $this->get(route('donatur.campaign.show', $campaign->slug));

    $response->assertOk();
    $response->assertSee('Donatur Sah');
    $response->assertSee('Rp50.000');
    $response->assertSee('Donatur Anonim');
    $response->assertSee('3 pcs Selimut');
    $response->assertDontSee('Berasku');
    $response->assertDontSee('Rp50.000, no');
});

test('campaign detail keeps two successful donations from the same donor as separate entries', function () {
    [, $campaign] = createDonaturTerbaruCampaign();
    $donatur = User::factory()->donatur()->create(['name' => 'Donatur Ulang']);

    for ($i = 1; $i <= 2; $i++) {
        Donation::create([
            'campaign_id' => $campaign->id,
            'user_id'     => $donatur->id,
            'jenis'       => 'uang',
            'nominal'     => 50000,
            'status'      => 'terverifikasi',
            'is_anonim'   => false,
            'order_id'    => 'DUPLICATE-NAME-' . $i,
        ]);
    }

    $response = $this->get(route('donatur.campaign.show', $campaign->slug));

    $response->assertOk();
    $response->assertSeeText('Donatur Ulang');
    expect(substr_count($response->getContent(), 'Donatur Ulang'))->toBeGreaterThanOrEqual(2);
});

test('campaign detail limits successful newest entries to six after filtering', function () {
    [, $campaign] = createDonaturTerbaruCampaign();
    $donatur = User::factory()->donatur()->create();

    for ($i = 1; $i <= 8; $i++) {
        Donation::create([
            'campaign_id' => $campaign->id,
            'user_id'     => $donatur->id,
            'jenis'       => 'uang',
            'nominal'     => 10000,
            'status'      => 'gagal',
            'is_anonim'   => false,
            'order_id'     => 'FAILED-LIMIT-' . $i,
        ]);
    }
    for ($i = 1; $i <= 7; $i++) {
        $donation = Donation::create([
            'campaign_id' => $campaign->id,
            'user_id'     => $donatur->id,
            'jenis'       => 'uang',
            'nominal'     => 10000,
            'status'      => 'terverifikasi',
            'is_anonim'   => false,
            'order_id'     => 'SUCCESS-LIMIT-' . $i,
        ]);
        $donation->timestamps = false;
        $donation->created_at = now()->subMinutes(8 - $i);
        $donation->save();
    }

    $response = $this->get(route('donatur.campaign.show', $campaign->slug));

    $response->assertOk();
    $campaignResponse = $response->viewData('campaign');
    expect($campaignResponse->Donations)->toHaveCount(6);
});
