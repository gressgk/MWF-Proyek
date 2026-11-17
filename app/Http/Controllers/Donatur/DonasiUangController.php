<?php

namespace App\Http\Controllers\Donatur;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
class DonasiUangController extends Controller
{
    public function index()
    {
        $campaign = Campaign::whereIn('tipe_bantuan', ['uang', 'keduanya'])->where('status', 'aktif')->firstOrFail();
        return view('donatur.donasi-uang', compact('campaign'));
    }
}
