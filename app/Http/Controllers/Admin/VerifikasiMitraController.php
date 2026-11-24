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

    public function download(User $user, $jenis)
    {
        abort_unless($user->role == 'penerima', 404);

        $profil = $user->PenerimaProfile;

        abort_unless($profil, 404);

        if ($jenis == 'ktp') {
            $path = $profil->dokumen_ktp;
        } elseif ($jenis == 'legalitas') {
            $path = $profil->dokumen_legalitas;
        } else {
            abort(404);
        }

        if (!$path || !Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return Storage::disk('local')->download($path);
    }

    public function approve(User $user)
    {
        abort_unless($user->role == 'penerima', 404);
        abort_unless($user->PenerimaProfile, 404);
        abort_unless($user->status_verifikasi == 'pending', 409);

        DB::transaction(function () use ($user) {
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
        });

        return redirect()->back()
            ->with('pesan', 'Mitra berhasil disetujui.');
    }

    public function reject(Request $request, User $user)
    {
        abort_unless($user->role == 'penerima', 404);
        abort_unless($user->PenerimaProfile, 404);
        abort_unless($user->status_verifikasi == 'pending', 409);

        $validated = $request->validate([
            'alasan_ditolak' => 'required|string|max:1000',
        ]);

        DB::transaction(function () use ($user, $validated) {
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
        });

        return redirect()->back()
            ->with('pesan', 'Mitra berhasil ditolak.');
    }
}