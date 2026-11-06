<?php

namespace App\Http\Controllers\Donatur;

use App\Http\Controllers\Controller;
use App\Models\Donation;
class SettingsController extends Controller
{
    public function index()
    {
        $user = getAuthUser();
        $donasiRutin = Donation::where('is_recurring', true)->with('Campaign')->get();
        return view('donatur.settings', compact('user', 'donasiRutin'));
    }
}
