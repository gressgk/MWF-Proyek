<?php

namespace App\Http\Controllers\Donatur;

use App\Http\Controllers\Controller;
use App\Models\Donation;
class RiwayatController extends Controller
{
    public function index()
    {
        $user = getAuthUser();
        $riwayat = Donation::with('Campaign', 'Shipment')->where('user_id', $user ? $user->id : 1)->latest()->paginate(10);
        return view('donatur.riwayat', compact('riwayat'));
    }
}
