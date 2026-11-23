<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PenerimaProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RegisterMitraController extends Controller
{
    public function index(Request $request)
    {
        $dataAkun = Session::get('register_mitra', []);

        $step = 1;

        if ($request->query('step') == 2 && !empty($dataAkun)) {
            $step = 2;
        }

        unset($dataAkun['password']);

        return view('auth.register-mitra', compact('step', 'dataAkun'));
    }

    // Step 1 - Data Akun
    public function simpanAkun(Request $request)
    {
        $request->validate([
            'nama_pj' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|max:20',
            'password' => 'required|string|min:8',
        ]);

        $dataAkun = [
            'nama_pj' => $request->nama_pj,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
        ];

        Session::put('register_mitra', $dataAkun);

        return redirect()->route('register.mitra', ['step' => 2]);
    }

    // Step 2 - Data Organisasi
    public function store(Request $request)
    {
        $dataAkun = Session::get('register_mitra');

        if (!$dataAkun) {
            return redirect()->route('register.mitra')
                ->with('pesan', 'Data akun belum diisi. Silakan isi kembali.');
        }

        if (DB::table('users')->where('email', $dataAkun['email'])->exists()) {
            Session::forget('register_mitra');

            return redirect()->route('register.mitra')
                ->withErrors(['email' => 'Email sudah terdaftar.']);
        }

        $request->validate([
            'nama_organisasi' => 'required|string|max:255',
            'jenis_organisasi' => 'required|in:yayasan,individu,komunitas',
            'alamat' => 'required|string',
            'nomor_rekening' => 'required|string|max:50',
            'dokumen_ktp' => 'required|file|mimes:pdf,jpg,jpeg,png|max:2048',
            'dokumen_legalitas' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
        ]);

        $pathKtp = null;
        $pathLegalitas = null;

        try {
            $pathKtp = $request->file('dokumen_ktp')->store('dokumen-mitra', 'local');

            if ($request->hasFile('dokumen_legalitas')) {
                $pathLegalitas = $request->file('dokumen_legalitas')->store('dokumen-mitra', 'local');

                if (!$pathLegalitas) {
                    throw new RuntimeException('Upload legalitas gagal.');
                }
            }

            DB::transaction(function () use ($request, $dataAkun, $pathKtp, $pathLegalitas) {
                $user = User::create([
                    'name' => $dataAkun['nama_pj'],
                    'email' => $dataAkun['email'],
                    'phone' => $dataAkun['phone'],
                    'password' => $dataAkun['password'],
                    'role' => 'penerima',
                    'status_verifikasi' => 'pending',
                ]);

                PenerimaProfile::create([
                    'user_id' => $user->id,
                    'nama_organisasi' => $request->nama_organisasi,
                    'jenis_organisasi' => $request->jenis_organisasi,
                    'alamat' => $request->alamat,
                    'nomor_rekening' => $request->nomor_rekening,
                    'dokumen_ktp' => $pathKtp,
                    'dokumen_legalitas' => $pathLegalitas,
                ]);
            });
        } catch (\Throwable $e) {
            if ($pathKtp) {
                Storage::disk('local')->delete($pathKtp);
            }

            if ($pathLegalitas) {
                Storage::disk('local')->delete($pathLegalitas);
            }

            throw $e;
        }

        Session::forget('register_mitra');

        return redirect()->route('login')->with('pesan', 'Pendaftaran berhasil dikirim, tunggu verifikasi Admin');
    }
}
