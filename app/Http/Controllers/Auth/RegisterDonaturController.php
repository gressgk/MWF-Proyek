<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class RegisterDonaturController extends Controller
{
    public function index()
    {
        return view('auth.register-donatur');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|max:20',
            'password' => 'required|min:8|confirmed',
            'setuju' => 'required',
            'password_confirmation' => 'required'
        ]);

        if ($request->password != $request->password_confirmation) {
            return back()->withErrors(['password' => 'Password dan konfirmasi password tidak sama']);
        }

        User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'role' => 'donatur'
        ]);

        return redirect()->route('login')->with('pesan', 'Akun berhasil dibuat! Silakan login.');
    }
}
