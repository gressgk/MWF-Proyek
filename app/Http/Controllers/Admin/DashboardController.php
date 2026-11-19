<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $totalDonatur = User::where('role', 'donatur')->count();
        $totalPenerima = User::where('role', 'penerima')->count();

        // Transaksi pending/gagal bukan dana yang sudah masuk.
        // Sesuai MidtransPaymentService/CampaignProgressService, hanya status terverifikasi.
        $totalDana = Donation::query()
            ->where('jenis', 'uang')
            ->where('status', 'terverifikasi')
            ->sum('nominal');

        $totalCampaignAktif = Campaign::where('status', 'aktif')->count();
        $totalCampaignPending = Campaign::where('status', 'pending_approval')->count();

        // Kelompokkan per TAHUN dan BULAN (bukan hanya nomor bulan).
        // Bulan tanpa transaksi tetap ditampilkan sebagai 0 di grafik.
        $dataBulanan = Donation::query()
            ->selectRaw('YEAR(created_at) AS tahun, MONTH(created_at) AS bulan, SUM(nominal) AS total')
            ->where('jenis', 'uang')
            ->where('status', 'terverifikasi')
            ->where('created_at', '>=', now()->startOfMonth()->subMonths(11))
            ->groupByRaw('YEAR(created_at), MONTH(created_at)')
            ->orderByRaw('YEAR(created_at), MONTH(created_at)')
            ->get()
            ->keyBy(fn ($item) => sprintf('%04d-%02d', $item->tahun, $item->bulan));

        $donasiPerBulan = collect(range(11, 0))->map(function (int $bulanLalu) use ($dataBulanan) {
            $tanggal = now()->startOfMonth()->subMonths($bulanLalu);
            $key = $tanggal->format('Y-m');

            return (object) [
                'label' => $tanggal->format('m/Y'),
                'total' => (float) ($dataBulanan->get($key)?->total ?? 0),
            ];
        });

        $maksGrafik = max(1, (float) $donasiPerBulan->max('total'));

        return view('admin.dashboard', compact(
            'totalDonatur',
            'totalPenerima',
            'totalDana',
            'totalCampaignAktif',
            'totalCampaignPending',
            'donasiPerBulan',
            'maksGrafik',
        ));
    }
}
