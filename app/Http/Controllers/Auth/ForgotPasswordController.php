<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    public function index()
    {

        return view('auth.forgot-password');
    }

    public function kirimLink(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        Password::sendResetLink($request->only('email'));

        return back()->with(
            'pesan',
            'Jika email terdaftar, link reset password akan dikirim.'
        );
    }
}
