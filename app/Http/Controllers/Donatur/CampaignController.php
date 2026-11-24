<?php

namespace App\Http\Controllers\Donatur;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use Barryvdh\DomPDF\Facade\Pdf;

class CampaignController extends Controller
{
    public function index(Campaign $campaign)
    {
        abort_unless($campaign->status === 'aktif', 404);

        $campaign->load([
            'Category',
            'Penerima.PenerimaProfile',
            'Reports.Photos',
            'Donations' => function ($query) {
                $query->with('Donatur')
                    ->where(function ($q) {
                        $q->where(function ($money) {
                            $money->where('jenis', 'uang')
                                ->where('status', 'terverifikasi');
                        })->orWhere(function ($goods) {
                            $goods->where('jenis', 'barang')
                                ->whereIn('status', ['terverifikasi', 'diterima']);
                        });
                    })
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->limit(6);
            },
        ]);

        return view('donatur.campaign-detail', compact('campaign'));
    }


    public function showReport(Campaign $campaign, $report)
    {
        // Only allow access to reports from active campaigns
        abort_unless($campaign->status === 'aktif', 404);

        // Find the report belonging to this specific campaign
        $laporan = $campaign->Reports()
            ->with('Photos')
            ->findOrFail($report);

        return view('donatur.laporan-detail', compact('campaign', 'laporan'));
    }

    public function exportLaporan(Campaign $campaign)
    {
        abort_unless($campaign->status === 'aktif', 404);

        $campaign->load([
            'Penerima.PenerimaProfile',
            'Reports.Photos',
        ]);

        $pdf = Pdf::loadView('donatur.laporan-pdf', compact('campaign'))
            ->setPaper('a4', 'portrait');

        return $pdf->download('Laporan-' . $campaign->slug . '.pdf');
    }
}
