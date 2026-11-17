@extends('layout.admin')

@section('judul', 'Verifikasi Mitra')

@section('konten')

    <h5 class="fw-bold mb-3">Menunggu Verifikasi</h5>
    <div class="row g-3 mb-4">
        @forelse ($mitraPending as $mitra)
            <div class="col-md-6">
                <div class="wc-card p-3">
                    <div class="d-flex justify-content-between">
                        <div>
                            <p class="fw-bold mb-0">{{ $mitra->PenerimaProfile->nama_organisasi ?? '-' }}</p>
                            <p class="small text-secondary mb-2">PIC: {{ $mitra->name }} &middot; {{ $mitra->email }}</p>
                        </div>
                        <span class="badge text-bg-warning align-self-start">Pending</span>
                    </div>
                    <p class="small mb-1"><i class="bi bi-geo-alt me-1"></i>{{ $mitra->PenerimaProfile->alamat ?? '-' }}</p>
                    <p class="small mb-3">
                        <i class="bi bi-file-earmark-text me-1"></i>
                        <a
                            href="{{ route('admin.verifikasi-mitra.dokumen', ['path' => $mitra->PenerimaProfile->dokumen_ktp ?? '']) }}">Lihat
                            dokumen KTP</a>
                        @if ($mitra->PenerimaProfile->dokumen_legalitas ?? false)
                            &middot; <a
                                href="{{ route('admin.verifikasi-mitra.dokumen', ['path' => $mitra->PenerimaProfile->dokumen_legalitas]) }}">Lihat
                                dokumen legalitas</a>
                        @endif
                    </p>
                    <div class="d-flex gap-2">
                        <form action="{{ route('admin.verifikasi-mitra.approve', $mitra->id) }}" method="POST">
                            @csrf
                            @method('PUT')

                            <button type="submit" class="btn btn-sm wc-btn-primary"
                                onclick="return confirm('Approve this organization?')">
                                Approve
                            </button>
                        </form>
                        <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                            data-bs-target="#reject{{ $mitra->id }}">Reject</button>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="reject{{ $mitra->id }}" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form action="{{ route('admin.verifikasi-mitra.reject', $mitra->id) }}" method="POST">
                            @csrf
                            @method('PUT')

                            <div class="modal-body p-4">
                                <h6 class="fw-bold mb-3">Alasan Penolakan</h6>

                                <textarea name="alasan_ditolak" class="form-control mb-3" rows="3" placeholder="Jelaskan alasan penolakan"
                                    required></textarea>

                                <button type="submit" class="btn btn-danger w-100">
                                    Kirim Penolakan
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @empty
            <p class="text-secondary small">Tidak ada mitra yang menunggu verifikasi.</p>
        @endforelse
    </div>

    <h5 class="fw-bold mb-3">Riwayat Mitra Lain</h5>
    <div class="wc-card p-3">
        <table class="table align-middle mb-0">
            <thead>
                <tr class="text-secondary small text-uppercase">
                    <th>Organisasi</th>
                    <th>PIC</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($mitraLain as $mitra)
                    <tr>
                        <td>{{ $mitra->PenerimaProfile->nama_organisasi ?? '-' }}</td>
                        <td>{{ $mitra->name }}</td>
                        <td>
                            <span
                                class="badge text-bg-{{ $mitra->status_verifikasi == 'approved' ? 'success' : 'danger' }}">{{ ucfirst($mitra->status_verifikasi) }}</span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="text-center text-secondary py-3">Belum ada data.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

@endsection
