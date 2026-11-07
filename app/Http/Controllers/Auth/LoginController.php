<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function index()
    {
        return view('auth.login');
    }

    public function doLogin(Request $request)
    {
        $credential = [
            'email' => $request->email,
            'password' => $request->password,
        ];

        $remember = $request->has('remember');

        if (Auth::attempt($credential, $remember)) {
            if (getAuthUser()->role == 'admin') {
                return redirect()->route('admin.dashboard');
            } elseif (getAuthUser()->role == 'penerima') {
                return redirect()->route('mitra.dashboard');
            } else {
                return redirect()->route('donatur.beranda');
            }
        } else {
            return redirect()->back()->with('pesan', 'Email atau password salah');
        }
    }

    public function logout()
    {
        Auth::logout();
        return redirect()->route('login');
    }
}
