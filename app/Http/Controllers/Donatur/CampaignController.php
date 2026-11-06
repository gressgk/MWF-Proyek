<?php

namespace App\Http\Controllers\Donatur;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
class CampaignController extends Controller
{
    public function index()
    {
        $campaign = Campaign::with(['Category', 'Penerima.PenerimaProfile', 'Reports', 'Donations.Donatur'])->where('status', 'aktif')->firstOrFail();
        return view('donatur.campaign-detail', compact('campaign'));
    }
}
