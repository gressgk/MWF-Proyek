<?php

namespace App\Http\Controllers\Donatur;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\ItemCategory;
class DonasiBarangController extends Controller
{
    public function index()
    {
        $campaign = Campaign::whereIn('tipe_bantuan', ['barang', 'keduanya'])->where('status', 'aktif')->firstOrFail();
        $itemKategori = ItemCategory::all();
        return view('donatur.donasi-barang', compact('campaign', 'itemKategori'));
    }
}
