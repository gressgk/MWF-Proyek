<?php

namespace App\Http\Controllers\Penerima;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Donation;
use App\Services\CampaignProgressService;
use Illuminate\Support\Facades\DB;

class DonasiMasukController extends Controller
{
    public function index()
    {
        $user = getAuthUser();

        $donasiMasuk = Donation::with([
            'Campaign',
            'Donatur',
            'Shipment',
            'ItemCategory',
        ])
            ->whereHas('Campaign', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->latest()
            ->paginate(10);

        return view('penerima.donasi-masuk', compact('donasiMasuk'));
    }

    /**
     * T1.4 (#59) — Konfirmasi manual penerimaan barang oleh Penerima pemilik campaign.
     * Mengubah status donation menjadi 'diterima', mengisi tanggal_diterima,
     * mengupdate shipment (bila ada) menjadi 'diterima', dan menyinkronkan
     * progress campaign secara atomik dan idempotent.
     */
    public function konfirmasiDiterima(Donation $donation)
    {
        $user = getAuthUser();

        DB::transaction(function () use ($donation, $user) {
            // 1. Lock dan verifikasi Campaign milik Penerima yang sedang login
            $campaign = Campaign::where('user_id', $user->id)
                ->where('id', $donation->campaign_id)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Lock dan verifikasi Donation barang berstatus menunggu_konfirmasi
            $lockedDonation = Donation::where('id', $donation->id)
                ->where('campaign_id', $campaign->id)
                ->where('jenis', 'barang')
                ->where('status', 'menunggu_konfirmasi')
                ->lockForUpdate()
                ->firstOrFail();

            // 3. Update status donasi barang ke 'diterima' dan catat tanggal diterima
            $lockedDonation->update([
                'status'          => 'diterima',
                'tanggal_diterima' => now(),
            ]);

            // 4. Update status shipment terkait (jika ada) menjadi 'diterima'
            if ($lockedDonation->Shipment) {
                $lockedDonation->Shipment->update([
                    'status' => 'diterima',
                ]);
            }

            // 5. Sinkronisasi progress campaign secara atomik menggunakan service
            app(CampaignProgressService::class)->sync($campaign);
        });

        return redirect()
            ->back()
            ->with('success', 'Donasi barang berhasil dikonfirmasi!');
    }
}
