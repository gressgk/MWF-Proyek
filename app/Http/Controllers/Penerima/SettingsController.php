<?php

namespace App\Http\Controllers\Penerima;

use App\Http\Controllers\Controller;
use App\Models\PenerimaProfile;
class SettingsController extends Controller
{
    public function index()
    {
        $user = getAuthUser();
        $profil = PenerimaProfile::first();
        return view('penerima.settings', compact('user', 'profil'));
    }
}
