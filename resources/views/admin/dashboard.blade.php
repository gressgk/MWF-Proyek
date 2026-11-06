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
                <h4 class="fw-bold mt-2 mb-0">Rp{{ number_format($totalDana, 0, ',', '.') }}</h4>
                <p class="small text-secondary mb-0">Total Dana Terkumpul</p>
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
        <h6 class="fw-bold mb-3">Grafik Donasi per Bulan</h6>
        <div class="d-flex align-items-end gap-3" style="height: 180px;">
            @forelse ($donasiPerBulan as $d)
                <div class="d-flex flex-column align-items-center justify-content-end" style="flex:1; height:100%;">
                    <div style="width: 100%; max-width: 40px; height: {{ max(10, min(150, $d->total / 100000)) }}px; background-color: var(--wc-orange); border-radius: 8px 8px 0 0;"></div>
                    <small class="text-secondary mt-1">{{ $d->bulan }}</small>
                </div>
            @empty
                <p class="text-secondary small">Belum ada data donasi.</p>
            @endforelse
        </div>
    </div>

@endsection
