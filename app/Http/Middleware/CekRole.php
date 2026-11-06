<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CekRole
{
    public function handle(Request $request, Closure $next, ...$boleh): Response
    {
        if (getAuthUser() == false) {
            return redirect('login');
        }

        foreach ($boleh as $b) {
            if (getAuthUser()->role == $b) {
                return $next($request);
            }
        }

        abort(403);
    }
}
