<?php

namespace App\Http\Controllers\Penerima;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    public function index()
    {
        $user = getAuthUser();
        $profil = $user->PenerimaProfile;

        return view('penerima.settings', compact('user', 'profil'));
    }

    public function update(Request $request)
    {
        $user = getAuthUser();
        $profil = $user->PenerimaProfile;

        $validated = $request->validate([
            'nama_organisasi' => 'required|string|max:255',
            'alamat' => 'required|string',
            'nomor_rekening' => 'required|string|max:50',
            'dokumen_legalitas' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
        ]);

        $profil->update([
            'nama_organisasi' => $validated['nama_organisasi'],
            'alamat' => $validated['alamat'],
            'nomor_rekening' => $validated['nomor_rekening'],
        ]);

        if ($request->hasFile('dokumen_legalitas')) {
            $oldDocument = $profil->dokumen_legalitas;

            $newDocument = $request->file('dokumen_legalitas')
                ->store('dokumen-mitra', 'local');

            $profil->update([
                'dokumen_legalitas' => $newDocument,
            ]);

            if ($oldDocument) {
                Storage::disk('local')->delete($oldDocument);
            }

            // Updated documents require Admin re-verification.
            DB::table('users')
            ->where('id', $user->id)
            ->update([
                'status_verifikasi' => 'pending',
            ]);
        }

        return redirect()
            ->route('mitra.settings')
            ->with('pesan', 'Settings berhasil diperbarui.');
    }
    
    public function previewLegalitas()
    {
        $user = getAuthUser();
        $profil = $user->PenerimaProfile;

        if (!$profil || !$profil->dokumen_legalitas) {
            abort(404, 'Dokumen legalitas tidak ditemukan.');
        }

        $filePath = Storage::disk('local')->path($profil->dokumen_legalitas);

        if (!Storage::disk('local')->exists($profil->dokumen_legalitas)) {
            abort(404, 'File tidak ditemukan.');
        }

        return response()->file($filePath);
    }
}
