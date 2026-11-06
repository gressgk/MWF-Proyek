<?php

namespace App\Http\Controllers\Penerima;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Campaign;
class CampaignFormController extends Controller
{
    public function index()
    {
        $kategoriList = Category::all();
        $campaign = Campaign::first();
        return view('penerima.campaign-form', compact('kategoriList', 'campaign'));
    }
}
