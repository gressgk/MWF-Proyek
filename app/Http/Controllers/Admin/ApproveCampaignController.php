<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
class ApproveCampaignController extends Controller
{
    public function index()
    {
        $campaignPending = Campaign::with('Penerima', 'Category')->where('status', 'pending_approval')->get();
        return view('admin.approve-campaign', compact('campaignPending'));
    }
}
