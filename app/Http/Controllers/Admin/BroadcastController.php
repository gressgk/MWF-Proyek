<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BroadcastNotification;
class BroadcastController extends Controller
{
    public function index()
    {
        $riwayatBroadcast = BroadcastNotification::with('Admin')->latest()->get();
        return view('admin.broadcast', compact('riwayatBroadcast'));
    }
}
