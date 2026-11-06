<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
class VerifikasiMitraController extends Controller
{
    public function index()
    {
        $mitraPending = User::with('PenerimaProfile')->where('role', 'penerima')->where('status_verifikasi', 'pending')->get();
        $mitraLain = User::with('PenerimaProfile')->where('role', 'penerima')->where('status_verifikasi', '!=', 'pending')->get();
        return view('admin.verifikasi-mitra', compact('mitraPending', 'mitraLain'));
    }
}
