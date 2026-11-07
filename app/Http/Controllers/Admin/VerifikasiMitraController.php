<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VerifikasiMitraController extends Controller
{
    public function index()
    {
        $mitraPending = User::with('PenerimaProfile')->where('role', 'penerima')->where('status_verifikasi', 'pending')->get();
        $mitraLain = User::with('PenerimaProfile')->where('role', 'penerima')->where('status_verifikasi', '!=', 'pending')->get();
        return view('admin.verifikasi-mitra', compact('mitraPending', 'mitraLain'));
    }

    public function download(Request $request)
    {
        if (getAuthUser()->role != 'admin') {
            return redirect()->back()->with('pesan', 'Kamu tidak punya akses ke dokumen ini');
        }

        return Storage::disk('local')->download($request->path);
    }
}