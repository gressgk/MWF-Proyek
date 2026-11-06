<?php

namespace App\Http\Controllers\Penerima;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignReport;
class LaporanController extends Controller
{
    public function index()
    {
        $campaign = Campaign::has('Reports')->first() ?? Campaign::first();
        $laporan = CampaignReport::where('campaign_id', $campaign->id ?? 0)->latest()->get();
        return view('penerima.laporan', compact('campaign', 'laporan'));
    }
}
