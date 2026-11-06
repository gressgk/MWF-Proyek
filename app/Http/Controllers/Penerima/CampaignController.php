<?php

namespace App\Http\Controllers\Penerima;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
class CampaignController extends Controller
{
    public function index()
    {
        $user = getAuthUser();
        $campaigns = Campaign::with('Category')->where('user_id', $user ? $user->id : 1)->latest()->paginate(10);
        if ($campaigns->isEmpty()) { $campaigns = Campaign::with('Category')->latest()->paginate(10); }
        return view('penerima.campaign-index', compact('campaigns'));
    }
}
