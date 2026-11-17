<?php

namespace App\Services;

use App\Models\Campaign;
use Illuminate\Support\Facades\DB;

/**
 * CampaignProgressService — T0.4
 *
 * Sumber kebenaran tunggal untuk menghitung dan menyinkronkan
 * terkumpul_nominal dan terkumpul_barang pada sebuah Campaign.
 *
 * KONTRAK (sesuai T0.2 & T0.4 plan.md):
 *   terkumpul_nominal = SUM(donations.nominal)
 *     WHERE campaign_id = $id
 *       AND jenis = 'uang'
 *       AND status = 'terverifikasi'
 *       AND deleted_at IS NULL
 *
 *   terkumpul_barang = SUM(donations.jumlah_barang)
 *     WHERE campaign_id = $id
 *       AND jenis = 'barang'
 *       AND status = 'diterima'   ← hanya setelah Penerima konfirmasi fisik
 *       AND deleted_at IS NULL
 *
 * Donasi pending, menunggu_konfirmasi, dan gagal TIDAK dihitung.
 * Nominal rupiah dan jumlah barang TIDAK dicampur.
 *
 * Idempotency:
 *   Pemanggilan berulang menghasilkan nilai yang sama karena
 *   dihitung ulang dari tabel donations (bukan diakumulasi).
 *
 * Concurrency:
 *   Gunakan DB transaction + SELECT ... FOR UPDATE pada baris Campaign
 *   agar tidak terjadi race condition saat webhook/konfirmasi dipanggil bersamaan.
 */
class CampaignProgressService
{
    /**
     * Hitung ulang dan simpan terkumpul_nominal dan terkumpul_barang
     * untuk Campaign yang diberikan.
     *
     * @param  Campaign|int  $campaign  Model atau ID campaign.
     * @return Campaign  Model yang sudah di-refresh dengan nilai terbaru.
     */
    public function sync(Campaign|int $campaign): Campaign
    {
        if (is_int($campaign)) {
            $campaign = Campaign::findOrFail($campaign);
        }

        $campaignId = $campaign->id;

        DB::transaction(function () use ($campaignId, &$campaign) {
            // Lock baris campaign agar tidak ada race condition dengan proses lain.
            $campaign = Campaign::lockForUpdate()->findOrFail($campaignId);

            $nominal = DB::table('donations')
                ->where('campaign_id', $campaignId)
                ->where('jenis', 'uang')
                ->where('status', 'terverifikasi')
                ->whereNull('deleted_at')
                ->sum('nominal');

            $barang = DB::table('donations')
                ->where('campaign_id', $campaignId)
                ->where('jenis', 'barang')
                ->where('status', 'diterima')
                ->whereNull('deleted_at')
                ->sum('jumlah_barang');

            $campaign->update([
                'terkumpul_nominal' => (float) $nominal,
                'terkumpul_barang'  => (int) $barang,
            ]);
        });

        return $campaign->fresh();
    }

    /**
     * Hitung ulang semua campaign aktif.
     * Dipakai untuk rekonsiliasi batch / data legacy.
     *
     * @return int  Jumlah campaign yang diproses.
     */
    public function syncAll(): int
    {
        $campaigns = Campaign::withoutTrashed()->get();

        foreach ($campaigns as $c) {
            $this->sync($c);
        }

        return $campaigns->count();
    }
}
