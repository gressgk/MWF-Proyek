<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use Illuminate\Http\Request;

class ApproveCampaignController extends Controller
{
    public function index()
    {
        $campaignPending = Campaign::with('Penerima', 'Category')->where('status', 'pending_approval')->get();
        return view('admin.approve-campaign', compact('campaignPending'));
    }

    public function approve(Campaign $campaign)
    {
        if ($campaign->status !== 'pending_approval') {
            return back()->with('error', 'Campaign is not awaiting approval.');
        }

        $campaign->update([
            'status' => 'aktif',
            'alasan_ditolak' => null,
        ]);

        return back()->with('success', 'Campaign approved successfully.');
    }

    public function reject(Request $request, Campaign $campaign)
    {
        if ($campaign->status !== 'pending_approval') {
            return back()->with('error', 'Campaign is not awaiting approval.');
        }

        $validated = $request->validate([
            'alasan_ditolak' => 'required|string|max:1000',
        ]);

        $campaign->update([
            'status' => 'ditolak',
            'alasan_ditolak' => $validated['alasan_ditolak'],
        ]);

        return back()->with('success', 'Campaign rejected successfully.');
    }
}
