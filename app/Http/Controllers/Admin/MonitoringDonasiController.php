<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MonitoringDonasiController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'jenis' => ['nullable', 'in:uang,barang'],
            'status' => ['nullable', 'in:menunggu_pembayaran,menunggu_konfirmasi,terverifikasi,diterima,gagal'],
            'cari' => ['nullable', 'string', 'max:100'],
        ]);

        $donasi = Donation::query()
            ->with(['Campaign', 'Donatur', 'Shipment'])
            ->when($filters['jenis'] ?? null, fn ($query, $jenis) => $query->where('jenis', $jenis))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['cari'] ?? null, function ($query, $cari) {
                $query->where(function ($subQuery) use ($cari) {
                    $subQuery->whereHas('Campaign', fn ($campaign) => $campaign->where('judul', 'like', "%{$cari}%"))
                        ->orWhereHas('Donatur', fn ($donatur) => $donatur->where('name', 'like', "%{$cari}%"))
                        ->orWhere('order_id', 'like', "%{$cari}%");
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.monitoring-donasi', compact('donasi', 'filters'));
    }
}
