<?php

namespace App\Http\Controllers\Donatur;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Category;
class BerandaController extends Controller
{
    public function index()
    {
        $campaigns = Campaign::with('Category', 'Penerima')->where('status', 'aktif')->latest()->paginate(6);
        $kategoriList = Category::all();
        return view('donatur.beranda', compact('campaigns', 'kategoriList'));
    }
}
