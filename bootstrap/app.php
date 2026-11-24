<?php

use App\Http\Middleware\CekRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // tambahkan middleware yang dibuat secara manual
        $middleware->alias([
            'CekRole' => CekRole::class
        ]);

        // Kecualikan CSRF hanya untuk endpoint webhook Midtrans.
        // Midtrans mengirim POST dari server mereka — tidak ada session/cookie browser.
        // Pengecualian DIBUAT SPESIFIK untuk path ini saja, tidak global.
        $middleware->validateCsrfTokens(except: [
            'midtrans/webhook',
            'midtrans/webhook/subscription',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // File upload kegedean (melebihi post_max_size PHP) -> balik dengan pesan jelas,
        // bukan error page mentah.
        $exceptions->render(function (\Illuminate\Http\Exceptions\PostTooLargeException $e, Request $request) {
            return back()
                ->withErrors(['foto_barang' => 'Ukuran file yang diupload terlalu besar. Maksimal 2MB.'])
                ->withInput();
        });
    })->create();