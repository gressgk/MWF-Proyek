<?php

namespace App\Http\Controllers\Donatur;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use Illuminate\Http\Request;

class DonasiUangController extends Controller
{
    /**
     * T0.5 — GET donasi uang.
     * Sebelumnya: memilih campaign aktif PERTAMA secara global (firstOrFail),
     * sehingga donatur bisa melihat campaign yang berbeda dari yang diklik.
     * Sekarang: menggunakan route-model binding Campaign $campaign via slug,
     * dan memvalidasi bahwa campaign aktif dan menerima donasi uang.
     */
    public function index(Campaign $campaign)
    {
        abort_unless(
            $campaign->status === 'aktif' &&
                in_array($campaign->tipe_bantuan, ['uang', 'keduanya']),
            404
        );

        return view('donatur.donasi-uang', compact('campaign'));
    }

    /**
     * T0.5 — POST donasi uang (stub).
     * Implementasi penuh (checkout Midtrans) BUKAN scope Tahap 0.
     * Stub ini disediakan agar route POST tidak 500 (method not found)
     * dan sebagai titik masuk yang jelas untuk Tahap 1 (#48).
     *
     * @see issue #48 — implementasi checkout Midtrans
     */
    public function store(Request $request, Campaign $campaign)
    {
        abort_unless(
            $campaign->status === 'aktif' &&
                in_array($campaign->tipe_bantuan, ['uang', 'keduanya']),
            404
        );

        // TODO (Tahap 1 / issue #48): implementasi checkout Midtrans di sini.
        // Jangan tambahkan logika pembayaran atau pemanggilan API Midtrans sebelum
        // issue #48 disetujui oleh pemilik proyek.
        abort(501, 'Pembayaran belum diimplementasikan. Dikerjakan di Tahap 1 (#48).');
    }
}
