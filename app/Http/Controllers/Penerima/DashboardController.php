<?php

namespace App\Http\Controllers\Penerima;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Donation;
class DashboardController extends Controller
{
    public function index()
    {
        $user = getAuthUser();
        $totalCampaignAktif = Campaign::where('status', 'aktif')->count();
        $totalDana = Donation::where('jenis', 'uang')->sum('nominal');
        $totalBarang = Donation::where('jenis', 'barang')->sum('jumlah_barang');
        $campaignTerbaru = Campaign::latest()->take(5)->get();
        return view('penerima.dashboard', compact('user', 'totalCampaignAktif', 'totalDana', 'totalBarang', 'campaignTerbaru'));
    }
}
