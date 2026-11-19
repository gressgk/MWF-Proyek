<?php

namespace App\Http\Controllers\Penerima;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Donation;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        // Route /mitra/dashboard sudah dilindungi middleware CekRole:penerima.
        $user = getAuthUser();
        abort_unless($user && $user->role === 'penerima', 403);

        // Batasi SEMUA statistik ke campaign milik user yang login.
        $milikSaya = fn ($query) => $query->where('user_id', $user->id);

        $totalCampaignAktif = Campaign::query()
            ->where('user_id', $user->id)
            ->where('status', 'aktif')
            ->count();

        $totalDana = Donation::query()
            ->where('jenis', 'uang')
            ->where('status', 'terverifikasi')
            ->whereHas('Campaign', $milikSaya)
            ->sum('nominal');

        // Proses konfirmasi barang pada main saat ini memakai status 'terverifikasi'.
        // 'diterima' juga diterima bila dipakai pada pengembangan berikutnya.
        $totalBarang = Donation::query()
            ->where('jenis', 'barang')
            ->whereIn('status', ['terverifikasi', 'diterima'])
            ->whereHas('Campaign', $milikSaya)
            ->sum('jumlah_barang');

        $barangMenunggu = Donation::query()
            ->where('jenis', 'barang')
            ->where('status', 'menunggu_konfirmasi')
            ->whereHas('Campaign', $milikSaya)
            ->count();

        $campaignDitolak = Campaign::query()
            ->where('user_id', $user->id)
            ->where('status', 'ditolak')
            ->count();

        $perluDitindaklanjuti = $barangMenunggu + $campaignDitolak;

        $campaignTerbaru = Campaign::query()
            ->where('user_id', $user->id)
            ->latest()
            ->take(5)
            ->get();

        return view('penerima.dashboard', compact(
            'user',
            'totalCampaignAktif',
            'totalDana',
            'totalBarang',
            'perluDitindaklanjuti',
            'campaignTerbaru',
        ));
    }
}
