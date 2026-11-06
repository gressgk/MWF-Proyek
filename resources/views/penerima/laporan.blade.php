@extends('layout.penerima')

@section('judul', 'Laporan Penggunaan')

@section('konten')

    <h5 class="fw-bold mb-4">Laporan Penggunaan &mdash; {{ $campaign->judul ?? '' }}</h5>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="wc-card p-4">
                <h6 class="fw-bold mb-3">Tambah Update Laporan</h6>
                <form method="POST" action="{{ route('mitra.laporan') }}" enctype="multipart/form-data">
                    @csrf
                    <input type="hidden" name="campaign_id" value="{{ $campaign->id ?? '' }}">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Judul</label>
                        <input type="text" name="judul" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Isi Laporan</label>
                        <textarea name="isi" class="form-control" rows="4" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Foto Bukti</label>
                        <input type="file" name="foto_bukti" class="form-control">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold small">Nominal yang Dipakai (jika donasi uang)</label>
                        <input type="number" name="nominal_terpakai" class="form-control" placeholder="Rp">
                    </div>
                    <button type="submit" class="wc-btn-primary w-100">Publikasikan Update</button>
                </form>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="wc-card p-4">
                <h6 class="fw-bold mb-3">Riwayat Update</h6>
                @forelse ($laporan as $update)
                    <div class="border-bottom pb-3 mb-3">
                        <p class="fw-semibold mb-1">{{ $update->judul }}</p>
                        <p class="small text-secondary mb-1">{{ $update->isi }}</p>
                        @if ($update->nominal_terpakai)
                            <p class="small mb-0">Dana dipakai: Rp{{ number_format($update->nominal_terpakai, 0, ',', '.') }}</p>
                        @endif
                    </div>
                @empty
                    <p class="small text-secondary mb-0">Belum ada update laporan untuk campaign ini.</p>
                @endforelse
            </div>
        </div>
    </div>

@endsection
