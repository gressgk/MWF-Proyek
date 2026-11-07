<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PenerimaProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class RegisterMitraController extends Controller
{
    public function index()
    {
        return view('auth.register-mitra');
    }

    public function store(Request $request)
    {
        $request->validate([
            'nama_pj' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|max:20',
            'password' => 'required|min:8',
            'nama_organisasi' => 'required|string|max:255',
            'jenis_organisasi' => 'required|in:yayasan,individu,komunitas',
            'alamat' => 'required|string',
            'nomor_rekening' => 'required|string|max:50',
            'dokumen_ktp' => 'required|file|mimes:pdf,jpg,jpeg,png|max:2048',
            'dokumen_legalitas' => 'required_if:jenis_organisasi,yayasan|nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
        ]);

        $user = User::create([
            'name' => $request->nama_pj,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'role' => 'penerima',
            'status_verifikasi' => 'pending',
        ]);

        $pathKtp = $request->file('dokumen_ktp')->store('dokumen-mitra', 'local');
        $pathLegalitas = $request->hasFile('dokumen_legalitas') ? $request->file('dokumen_legalitas')->store('dokumen-mitra', 'local') : null;

        PenerimaProfile::create([
            'user_id' => $user->id,
            'nama_organisasi' => $request->nama_organisasi,
            'jenis_organisasi' => $request->jenis_organisasi,
            'alamat' => $request->alamat,
            'nomor_rekening' => $request->nomor_rekening,
            'dokumen_ktp' => $pathKtp,
            'dokumen_legalitas' => $pathLegalitas,
        ]);

        return redirect()->route('login')->with('pesan', 'Pendaftaran berhasil dikirim, tunggu verifikasi Admin');
    }
}
