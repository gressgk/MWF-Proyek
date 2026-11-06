<?php

namespace App\Http\Controllers\Penerima;

use App\Http\Controllers\Controller;
use App\Models\Donation;
class DonasiMasukController extends Controller
{
    public function index()
    {
        $donasiMasuk = Donation::with('Campaign', 'Donatur', 'Shipment')->latest()->paginate(10);
        return view('penerima.donasi-masuk', compact('donasiMasuk'));
    }
}
