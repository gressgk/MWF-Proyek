<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Donation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CampaignProgressService — Sumber kebenaran tunggal untuk perhitungan progress campaign.
 *
 * ATURAN BISNIS:
 *   - terkumpul_nominal  : SUM dari Donation.nominal di mana jenis='uang', status='terverifikasi',
 *                          dan deleted_at IS NULL.
 *   - terkumpul_barang   : TIDAK diubah oleh service ini (domain modul Penerima/konfirmasi barang).
 *   - Webhook berulang tidak menyebabkan double-counting karena SUM dihitung ulang setiap saat.
 *   - Transaksi pending, gagal, dan soft-deleted diabaikan.
 *
 * PENGGUNAAN:
 *   app(CampaignProgressService::class)->recalculate($campaign);
 */
class CampaignProgressService
{
    /**
     * Hitung ulang terkumpul_nominal berdasarkan donasi uang yang telah terverifikasi.
     * Tidak menyentuh terkumpul_barang.
     *
     * @param Campaign $campaign
     * @return int Nilai terkumpul_nominal yang baru (dalam rupiah, integer)
     */
    public function recalculate(Campaign $campaign): int
    {
        return DB::transaction(function () use ($campaign) {
            // Row lock pada record campaign untuk mencegah lost update jika dua donasi
            // pada campaign yang sama diverifikasi secara bersamaan
            DB::table('campaigns')->where('id', $campaign->id)->lockForUpdate()->first();

            $total = (int) Donation::where('campaign_id', $campaign->id)
                ->where('jenis', 'uang')
                ->where('status', 'terverifikasi')
                ->whereNull('deleted_at')
                ->sum('nominal');

            // Update atomik — hanya ubah terkumpul_nominal, bukan terkumpul_barang
            DB::table('campaigns')
                ->where('id', $campaign->id)
                ->update(['terkumpul_nominal' => $total]);

            Log::info('CampaignProgressService: recalculated with row lock', [
                'campaign_id' => $campaign->id,
                'terkumpul_nominal' => $total,
            ]);

            return $total;
        });
    }
}
