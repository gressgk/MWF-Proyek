<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Campaign;
use App\Models\Donation;
class DashboardController extends Controller
{
    public function index()
    {
        $totalDonatur = User::where('role', 'donatur')->count();
        $totalPenerima = User::where('role', 'penerima')->count();
        $totalDana = Donation::where('jenis', 'uang')->sum('nominal');
        $totalCampaignAktif = Campaign::where('status', 'aktif')->count();
        $totalCampaignPending = Campaign::where('status', 'pending_approval')->count();
        $donasiPerBulan = Donation::selectRaw("strftime('%m', created_at) as bulan, SUM(nominal) as total")->where('jenis', 'uang')->groupBy('bulan')->get();
        return view('admin.dashboard', compact('totalDonatur', 'totalPenerima', 'totalDana', 'totalCampaignAktif', 'totalCampaignPending', 'donasiPerBulan'));
    }
}
