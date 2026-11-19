@extends('layout.admin')

@section('judul', 'Dashboard Admin')

@section('konten')

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="wc-stat-card">
                <i class="bi bi-person-heart text-warning fs-4"></i>
                <h4 class="fw-bold mt-2 mb-0">{{ $totalDonatur }}</h4>
                <p class="small text-secondary mb-0">Total Donatur</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="wc-stat-card">
                <i class="bi bi-building text-warning fs-4"></i>
                <h4 class="fw-bold mt-2 mb-0">{{ $totalPenerima }}</h4>
                <p class="small text-secondary mb-0">Total Mitra Penerima</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="wc-stat-card">
                <i class="bi bi-cash-coin text-warning fs-4"></i>
                <h4 class="fw-bold mt-2 mb-0">Rp{{ number_format((float) $totalDana, 0, ',', '.') }}</h4>
                <p class="small text-secondary mb-0">Total Dana Terverifikasi</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="wc-stat-card">
                <i class="bi bi-megaphone text-warning fs-4"></i>
                <h4 class="fw-bold mt-2 mb-0">{{ $totalCampaignAktif }} / {{ $totalCampaignPending }}</h4>
                <p class="small text-secondary mb-0">Campaign Aktif / Pending</p>
            </div>
        </div>
    </div>

    <div class="wc-card p-4">
        <h6 class="fw-bold mb-1">Grafik Donasi Uang per Bulan</h6>
        <p class="small text-secondary mb-3">12 bulan terakhir, hanya transaksi terverifikasi</p>
        @if ((float) $totalDana <= 0)
            <p class="text-secondary small">Belum ada donasi uang terverifikasi.</p>
        @endif
        <div class="overflow-auto">
            <div class="d-flex align-items-end gap-2" style="min-width: 690px; height: 205px;">
                @foreach ($donasiPerBulan as $data)
                    <div class="d-flex flex-column align-items-center justify-content-end text-center" style="flex: 1; height: 100%;" title="{{ $data->label }} - Rp{{ number_format($data->total, 0, ',', '.') }}">
                        <small class="text-secondary mb-1" style="font-size: 10px;">{{ $data->total > 0 ? 'Rp' . number_format($data->total, 0, ',', '.') : '0' }}</small>
                        <div style="width: 100%; max-width: 34px; height: {{ $data->total > 0 ? max(5, round(($data->total / $maksGrafik) * 140)) : 0 }}px; background: var(--wc-orange, #e79d45); border-radius: 6px 6px 0 0;"></div>
                        <small class="text-secondary mt-1" style="font-size: 10px;">{{ $data->label }}</small>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

@endsection
