
@extends('layout.donatur')

@section('judul', 'Detail Laporan')

@section('konten')

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="fw-bold mb-0">Transparency Report</h5>

        <a href="{{ route('donatur.campaign.show', $campaign->slug) }}"
            class="btn btn-light border">
            <i class="bi bi-arrow-left me-1"></i> Back to Campaign
        </a>
    </div>

    <div class="wc-card p-4 mb-4">
        <h5 class="fw-bold mb-2">{{ $laporan->judul }}</h5>

        <p class="text-muted small mb-3">
            <i class="bi bi-megaphone me-1"></i>
            {{ $campaign->judul }}
            <span class="mx-2">•</span>
            <i class="bi bi-calendar me-1"></i>
            {{ $laporan->created_at->format('d M Y') }}
        </p>

        <hr>

        <h6 class="fw-bold">Description</h6>
        <p class="mb-4" style="white-space: pre-line;">{{ $laporan->isi }}</p>

        <h6 class="fw-bold">Amount Spent</h6>
        <p class="mb-0">
            Rp{{ number_format($laporan->nominal_terpakai ?? 0, 0, ',', '.') }}
        </p>
    </div>

    {{-- Supporting Photos --}}
    @if ($laporan->Photos->isNotEmpty() || $laporan->foto_bukti)
        <div class="wc-card p-4 mb-4">
            <h6 class="fw-bold mb-3">Supporting Photos</h6>

            <div class="row g-3">
                @foreach ($laporan->Photos as $photo)
                    <div class="col-md-4 col-sm-6">
                        <a href="{{ asset('storage/' . $photo->foto_path) }}" target="_blank">
                            <img
                                src="{{ asset('storage/' . $photo->foto_path) }}"
                                alt="Supporting photo"
                                class="img-fluid rounded"
                                style="width: 100%; height: 220px; object-fit: cover;">
                        </a>
                    </div>
                @endforeach

                {{-- Older reports using the original photo field --}}
                @if ($laporan->foto_bukti)
                    <div class="col-md-4 col-sm-6">
                        <a href="{{ asset('storage/' . $laporan->foto_bukti) }}" target="_blank">
                            <img
                                src="{{ asset('storage/' . $laporan->foto_bukti) }}"
                                alt="Supporting photo"
                                class="img-fluid rounded"
                                style="width: 100%; height: 220px; object-fit: cover;">
                        </a>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- PDF Document --}}
    @if ($laporan->dokumen_bukti)
        <div class="wc-card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0">Supporting Document</h6>

                <a href="{{ asset('storage/' . $laporan->dokumen_bukti) }}"
                    class="wc-btn-primary"
                    target="_blank"
                    download>
                    <i class="bi bi-download me-1"></i> Download PDF
                </a>
            </div>

            <iframe
                src="{{ asset('storage/' . $laporan->dokumen_bukti) }}"
                width="100%"
                height="600"
                style="border: 1px solid #dee2e6; border-radius: 8px;"
                title="Transparency report document">
            </iframe>
        </div>
    @endif

@endsection
