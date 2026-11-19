@extends('layout.penerima')

@section('judul', 'Dashboard Mitra')

@section('konten')

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="wc-stat-card">
                <i class="bi bi-megaphone text-warning fs-4"></i>
                <h4 class="fw-bold mt-2 mb-0">{{ $totalCampaignAktif }}</h4>
                <p class="small text-secondary mb-0">Campaign Aktif Saya</p>
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
                <i class="bi bi-box-seam text-warning fs-4"></i>
                <h4 class="fw-bold mt-2 mb-0">{{ number_format((int) $totalBarang, 0, ',', '.') }}</h4>
                <p class="small text-secondary mb-0">Barang Sudah Dikonfirmasi</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="wc-stat-card">
                <i class="bi bi-bell text-warning fs-4"></i>
                <h4 class="fw-bold mt-2 mb-0">{{ $perluDitindaklanjuti }}</h4>
                <p class="small text-secondary mb-0">Perlu Ditindaklanjuti</p>
            </div>
        </div>
    </div>

    <div class="wc-card p-4">
        <h6 class="fw-bold mb-3">Campaign Terbaru Saya</h6>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr class="text-secondary small text-uppercase">
                        <th>Judul</th>
                        <th>Status</th>
                        <th>Progres</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($campaignTerbaru as $campaign)
                        <tr>
                            <td class="fw-semibold">{{ $campaign->judul }}</td>
                            <td><span class="wc-badge wc-badge-biasa">{{ ucfirst(str_replace('_', ' ', $campaign->status)) }}</span></td>
                            <td style="width: 160px;">
                                <div class="wc-progress"><div style="width: {{ $campaign->progres_persen }}%"></div></div>
                            </td>
                            <td><a href="{{ route('mitra.campaign.index') }}" class="small">Kelola →</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-secondary py-4">Belum ada campaign milik akun ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

@endsection
