<?php

namespace App\Http\Controllers\Penerima;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use Illuminate\Support\Facades\DB;

class DonasiMasukController extends Controller
{
    public function index()
    {
        $user = getAuthUser();
        $this->authorize('viewAny', Donation::class);
        $donasiMasuk = Donation::with([
            'Campaign',
            'Donatur',
            'Shipment'
        ])
            ->whereHas('Campaign', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->latest()
            ->paginate(10);

        return view('penerima.donasi-masuk', compact('donasiMasuk'));
    }

    public function konfirmasiDiterima(Donation $donation)
    {
        $this->authorize('konfirmasiDiterima', $donation);

        abort_unless($donation->status === 'menunggu_konfirmasi', 404);

        DB::transaction(function () use ($donation) {
            DB::table('campaigns')->where('id', $donation->campaign_id)->lockForUpdate()->first();

            // Update donation status.
            $donation->update([
                'status' => 'terverifikasi',
                'tanggal_diterima' => now(),
            ]);

            DB::table('campaigns')
                ->where('id', $donation->campaign_id)
                ->increment('terkumpul_barang', $donation->jumlah_barang);
        });

        return redirect()
            ->back()
            ->with('success', 'Donasi barang berhasil dikonfirmasi!');
    }
}