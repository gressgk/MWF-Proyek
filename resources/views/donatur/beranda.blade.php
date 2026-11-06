@extends('layout.donatur')

@section('judul', 'Beranda - WeCare')

@section('konten')

    <div class="wc-card p-4 mb-4 d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
        <div>
            <h4 class="fw-bold mb-1">Halo, {{ getAuthUser() ? getAuthUser()->name : 'Donatur' }} 👋</h4>
            <p class="text-secondary mb-0">Yuk cari campaign yang ingin kamu bantu hari ini.</p>
        </div>
        <img src="{{ asset('img/mascot/duck-donationbox.png') }}" style="height: 80px;" alt="Maskot WeCare">
    </div>

    <form class="wc-card p-3 mb-4">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-semibold">Cari campaign</label>
                <input type="text" class="form-control" placeholder="Cari berdasarkan judul...">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Kategori</label>
                <select class="form-select">
                    <option value="">Semua kategori</option>
                    @foreach ($kategoriList as $kat)
                        <option value="{{ $kat->slug }}">{{ $kat->nama }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Tipe Bantuan</label>
                <select class="form-select">
                    <option value="">Semua tipe</option>
                    <option value="uang">Uang</option>
                    <option value="barang">Barang</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="wc-btn-primary w-100">Cari</button>
            </div>
        </div>
    </form>

    <div class="row g-4">
        @forelse ($campaigns as $campaign)
            @include('partials.campaign-card', ['campaign' => $campaign])
        @empty
            <p class="text-secondary">Belum ada campaign aktif saat ini.</p>
        @endforelse
    </div>

    <div class="d-flex justify-content-center mt-4">
        {{ $campaigns->links() }}
    </div>

@endsection
