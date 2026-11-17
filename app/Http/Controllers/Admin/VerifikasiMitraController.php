<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

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

    public function approve(User $user)
    {
        DB::table('users')
            ->where('id', $user->id)
            ->update([
                'status_verifikasi' => 'approved',
            ]);

        DB::table('penerima_profiles')
            ->where('user_id', $user->id)
            ->update([
                'alasan_ditolak' => null,
            ]);

        return redirect()->back()->with('pesan', 'Mitra berhasil disetujui.');
    }

    public function reject(Request $request, User $user)
    {
        $validated = $request->validate([
            'alasan_ditolak' => 'required|string|max:1000',
        ]);

        DB::table('users')
            ->where('id', $user->id)
            ->update([
                'status_verifikasi' => 'rejected',
            ]);

        DB::table('penerima_profiles')
            ->where('user_id', $user->id)
            ->update([
                'alasan_ditolak' => $validated['alasan_ditolak'],
            ]);

        return redirect()->back()->with('pesan', 'Mitra berhasil ditolak.');
    }
}