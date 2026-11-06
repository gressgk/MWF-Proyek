<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
class HomeController extends Controller
{
    public function index()
    {
        $campaigns = Campaign::with('Category', 'Penerima')->where('status', 'aktif')->latest()->paginate(6);
        $kategoriList = \App\Models\Category::all();
        return view('home', compact('campaigns', 'kategoriList'));
    }
}
