<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
class UserController extends Controller
{
    public function index()
    {
        $users = User::whereIn('role', ['donatur', 'penerima'])->latest()->paginate(15);
        return view('admin.user-index', compact('users'));
    }
}
