<?php

namespace App\Http\Controllers\Penerima;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

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

        abort_unless($profil, 404);

        $validated = $request->validate([
            'nama_organisasi' => 'required|string|max:255',
            'alamat' => 'required|string',
            'nomor_rekening' => 'required|string|max:50',
            'dokumen_legalitas' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
        ]);

        $dokumenLama = $profil->dokumen_legalitas;
        $dokumenBaru = null;

        try {
            // Upload dokumen
            if ($request->hasFile('dokumen_legalitas')) {
                $dokumenBaru = $request->file('dokumen_legalitas')
                    ->store('dokumen-mitra', 'local');

                if (!$dokumenBaru) {
                    throw new RuntimeException('Upload dokumen gagal.');
                }
            }

            // Update profil
            DB::transaction(function () use ($user, $profil, $validated, $dokumenBaru) {
                $profil->update([
                    'nama_organisasi' => $validated['nama_organisasi'],
                    'alamat' => $validated['alamat'],
                    'nomor_rekening' => $validated['nomor_rekening'],
                ]);

                if ($dokumenBaru) {
                    $profil->update([
                        'dokumen_legalitas' => $dokumenBaru,
                    ]);

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update([
                            'status_verifikasi' => 'pending',
                        ]);
                }
            });
        } catch (\Throwable $e) {
            if ($dokumenBaru) {
                Storage::disk('local')->delete($dokumenBaru);
            }

            report($e);

            return back()->withInput()
                ->with('pesan', 'Settings gagal diperbarui.');
        }

        // Hapus dokumen lama
        if ($dokumenBaru && $dokumenLama) {
            try {
                Storage::disk('local')->delete($dokumenLama);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return redirect()->route('mitra.settings')
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


    // Ganti password
    public function gantiPassword(Request $request)
    {
        $user = getAuthUser();

        $validated = $request->validate([
            'password_lama' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if (!Hash::check($validated['password_lama'], $user->password)) {
            return back()->withErrors([
                'password_lama' => 'Password lama tidak sesuai.',
            ]);
        }

        if (Hash::check($validated['password'], $user->password)) {
            return back()->withErrors([
                'password' => 'Password baru harus berbeda dari password lama.',
            ]);
        }

        $user->password = Hash::make($validated['password']);
        $user->remember_token = Str::random(60);
        $user->save();

        return redirect()->route('mitra.settings')
            ->with('pesan', 'Password berhasil diubah.');
    }
}
