<?php

namespace App\Http\Controllers\Penerima;

use App\Http\Controllers\Controller;
use App\Models\Donation;

class DonasiMasukController extends Controller
{
    public function index()
    {
        $user = getAuthUser();

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
        $user = getAuthUser();

        // Only allow donations belonging to this Penerima's campaigns.
        $donation = Donation::whereHas('Campaign', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
            ->where('id', $donation->id)
            ->where('jenis', 'barang')
            ->where('status', 'menunggu_konfirmasi')
            ->firstOrFail();

        // Update donation status.
        $donation->update([
            'status' => 'terverifikasi',
            'updated_at' => NOW(),
        ]);

        return redirect()
            ->back()
            ->with('success', 'Donasi barang berhasil dikonfirmasi!');
    }
}
