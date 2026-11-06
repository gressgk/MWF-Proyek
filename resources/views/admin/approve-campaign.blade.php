@extends('layout.admin')

@section('judul', 'Approve Campaign')

@section('konten')

    <h5 class="fw-bold mb-4">Campaign Menunggu Persetujuan</h5>

    <div class="row g-3">
        @forelse ($campaignPending as $campaign)
            <div class="col-md-6">
                <div class="wc-card p-3">
                    <div class="d-flex gap-2 mb-2">
                        <span class="wc-badge wc-badge-{{ $campaign->urgensi }}">{{ ucfirst($campaign->urgensi) }}</span>
                        <span class="wc-badge wc-badge-biasa">{{ $campaign->Category->nama ?? '-' }}</span>
                    </div>
                    <p class="fw-bold mb-1">{{ $campaign->judul }}</p>
                    <p class="small text-secondary mb-3">oleh {{ $campaign->Penerima->name ?? '-' }}</p>
                    <p class="small mb-3">{{ \Illuminate\Support\Str::limit($campaign->deskripsi, 120) }}</p>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm wc-btn-primary">Approve</button>
                        <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectC{{ $campaign->id }}">Reject</button>
                        <a href="#" class="btn btn-sm btn-light border">Preview</a>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="rejectC{{ $campaign->id }}">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-body p-4">
                            <h6 class="fw-bold mb-3">Alasan Penolakan Campaign</h6>
                            <textarea class="form-control mb-3" rows="3"></textarea>
                            <button class="btn btn-danger w-100">Tolak Campaign</button>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <p class="text-secondary small">Tidak ada campaign yang menunggu persetujuan saat ini.</p>
        @endforelse
    </div>

@endsection
