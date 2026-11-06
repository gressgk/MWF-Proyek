<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Donation;
class MonitoringDonasiController extends Controller
{
    public function index()
    {
        $donasi = Donation::with('Campaign', 'Donatur')->latest()->paginate(15);
        return view('admin.monitoring-donasi', compact('donasi'));
    }
}
