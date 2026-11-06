<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;

class RegisterDonaturController extends Controller
{
    public function index()
    {

        return view('auth.register-donatur', compact());
    }
}
