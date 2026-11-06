@extends('layout.admin')

@section('judul', 'Broadcast Notifikasi')

@section('konten')

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="wc-card p-4">
                <h6 class="fw-bold mb-3">Kirim Broadcast Baru</h6>
                <form method="POST" action="{{ route('admin.broadcast') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Judul</label>
                        <input type="text" name="judul" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Isi Pesan</label>
                        <textarea name="isi" class="form-control" rows="4" required></textarea>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold small">Target Penerima</label>
                        <select name="target_role" class="form-select">
                            <option value="semua">Semua User</option>
                            <option value="donatur">Donatur</option>
                            <option value="penerima">Mitra Penerima</option>
                        </select>
                    </div>
                    <button type="submit" class="wc-btn-primary w-100">Kirim Broadcast</button>
                </form>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="wc-card p-4">
                <h6 class="fw-bold mb-3">Riwayat Broadcast</h6>
                @forelse ($riwayatBroadcast as $item)
                    <div class="border-bottom pb-3 mb-3">
                        <div class="d-flex justify-content-between">
                            <p class="fw-semibold mb-1">{{ $item->judul }}</p>
                            <span class="wc-badge wc-badge-biasa">{{ ucfirst($item->target_role) }}</span>
                        </div>
                        <p class="small text-secondary mb-1">{{ $item->isi }}</p>
                        <p class="small text-secondary mb-0">{{ $item->created_at->format('d M Y, H:i') }}</p>
                    </div>
                @empty
                    <p class="small text-secondary mb-0">Belum ada broadcast yang dikirim.</p>
                @endforelse
            </div>
        </div>
    </div>

@endsection
