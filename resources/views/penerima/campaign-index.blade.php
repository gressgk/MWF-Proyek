@extends('layout.penerima')

@section('judul', 'Campaign Saya')

@section('konten')

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="fw-bold mb-0">Campaign Saya</h5>
        <a href="{{ route('mitra.campaign.create') }}" class="wc-btn-primary"><i class="bi bi-plus-lg me-1"></i>Buat
            Campaign</a>
    </div>

    <div class="wc-card p-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr class="text-secondary small text-uppercase">
                        <th>Judul</th>
                        <th>Tipe Bantuan</th>
                        <th>Urgensi</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($campaigns as $campaign)
                        <tr>
                            <td class="fw-semibold">{{ $campaign->judul }}</td>
                            <td class="text-capitalize">{{ $campaign->tipe_bantuan }}</td>
                            <td><span
                                    class="wc-badge wc-badge-{{ $campaign->urgensi }}">{{ ucfirst($campaign->urgensi) }}</span>
                            </td>
                            <td>
                                @php
                                    $warna = match ($campaign->status) {
                                        'aktif' => 'success',
                                        'pending_approval', 'draft' => 'warning',
                                        'selesai' => 'secondary',
                                        default => 'danger',
                                    };
                                @endphp
                                <span class="badge text-bg-{{ $warna }}">
                                    {{ str_replace('_', ' ', ucfirst($campaign->status)) }}
                                </span>

                                @if ($campaign->status === 'ditolak' && $campaign->alasan_ditolak)
                                    <div class="small text-danger mt-2">
                                        <strong>Alasan penolakan:</strong>
                                        {{ $campaign->alasan_ditolak }}
                                    </div>
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('mitra.campaign.edit', $campaign->id) }}" class="small">
                                    Edit
                                </a>

                                &middot;

                                <form action="{{ route('mitra.campaign.destroy', $campaign->id) }}" method="POST"
                                    class="d-inline"
                                    onsubmit="return confirm('Are you sure you want to delete this campaign?')">
                                    @csrf
                                    @method('DELETE')

                                    <button type="submit" class="btn btn-link p-0 small text-danger text-decoration-none">
                                        Hapus
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-secondary py-4">Belum ada campaign. Yuk buat campaign
                                pertamamu.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex justify-content-center mt-4">
        {{ $campaigns->links() }}
    </div>

@endsection
