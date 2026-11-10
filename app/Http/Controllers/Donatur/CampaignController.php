<?php

namespace App\Http\Controllers\Donatur;

use App\Http\Controllers\Controller;
use App\Models\Campaign;

class CampaignController extends Controller
{
    public function index(Campaign $campaign)
    {
        abort_unless($campaign->status === 'aktif', 404);

        $campaign->load([
            'Category',
            'Penerima.PenerimaProfile',
            'Reports.Photos',
            'Donations.Donatur',
        ]);

        return view('donatur.campaign-detail', compact('campaign'));
    }


    public function showReport(Campaign $campaign, $report)
    {
        // Only allow access to reports from active campaigns
        abort_unless($campaign->status === 'aktif', 404);

        // Find the report belonging to this specific campaign
        $laporan = $campaign->Reports()
            ->with('Photos')
            ->findOrFail($report);

        return view('donatur.laporan-detail', compact('campaign', 'laporan'));
    }
}
