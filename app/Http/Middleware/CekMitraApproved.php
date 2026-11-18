<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CekMitraApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = getAuthUser();

        if (!$user) {
            return redirect()->route('login');
        }

        if ($user->role != 'penerima' || $user->status_verifikasi != 'approved') {
            abort(403, 'Akun mitra belum diverifikasi oleh Admin.');
        }

        return $next($request);
    }
}