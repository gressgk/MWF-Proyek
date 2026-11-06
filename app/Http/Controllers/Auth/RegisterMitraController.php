<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;

class RegisterMitraController extends Controller
{
    public function index()
    {

        return view('auth.register-mitra', compact());
    }
}
