@extends('layout.penerima')

@section('judul', 'Dashboard Mitra')

@section('konten')

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="wc-stat-card">
                <i class="bi bi-megaphone text-warning fs-4"></i>
                <h4 class="fw-bold mt-2 mb-0">{{ $totalCampaignAktif }}</h4>
                <p class="small text-secondary mb-0">Campaign Aktif</p>
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
                <i class="bi bi-box-seam text-warning fs-4"></i>
                <h4 class="fw-bold mt-2 mb-0">{{ $totalBarang }}</h4>
                <p class="small text-secondary mb-0">Total Barang Masuk</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="wc-stat-card">
                <i class="bi bi-bell text-warning fs-4"></i>
                <h4 class="fw-bold mt-2 mb-0">3</h4>
                <p class="small text-secondary mb-0">Perlu Ditindaklanjuti</p>
            </div>
        </div>
    </div>

    <div class="wc-card p-4">
        <h6 class="fw-bold mb-3">Campaign Terbaru</h6>
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
                    @foreach ($campaignTerbaru as $campaign)
                        <tr>
                            <td class="fw-semibold">{{ $campaign->judul }}</td>
                            <td><span class="wc-badge wc-badge-biasa">{{ str_replace('_', ' ', ucfirst($campaign->status)) }}</span></td>
                            <td style="width: 160px;">
                                <div class="wc-progress"><div style="width: {{ $campaign->progres_persen }}%"></div></div>
                            </td>
                            <td><a href="{{ route('mitra.campaign.index') }}" class="small">Kelola &rarr;</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

@endsection
