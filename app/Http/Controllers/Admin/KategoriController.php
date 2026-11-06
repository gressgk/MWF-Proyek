<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ItemCategory;
class KategoriController extends Controller
{
    public function index()
    {
        $kategoriCampaign = Category::withCount('Campaigns')->get();
        $kategoriBarang = ItemCategory::withCount('Donations')->get();
        return view('admin.kategori', compact('kategoriCampaign', 'kategoriBarang'));
    }
}
