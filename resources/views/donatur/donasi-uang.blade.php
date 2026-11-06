@extends('layout.donatur')

@section('judul', 'Donasi Uang - WeCare')

@section('konten')

    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="wc-card p-4 p-md-5">
                <div class="d-flex align-items-center gap-3 mb-4">
                    <img src="{{ asset('img/mascot/duck-gift.png') }}" style="height: 60px;" alt="Maskot WeCare">
                    <div>
                        <h5 class="fw-bold mb-0">Donasi Uang</h5>
                        <p class="text-secondary small mb-0">untuk campaign "{{ $campaign->judul }}"</p>
                    </div>
                </div>

                <form method="POST" action="{{ route('donatur.donasi.uang', $campaign->slug) }}">
                    @csrf
                    <label class="form-label fw-semibold">Pilih Nominal</label>
                    <div class="row g-2 mb-3">
                        @foreach ([50000, 100000, 250000, 500000, 1000000] as $preset)
                            <div class="col-4">
                                <input type="radio" class="btn-check" name="nominal_preset" id="nom{{ $preset }}" value="{{ $preset }}">
                                <label class="btn wc-btn-outline w-100" for="nom{{ $preset }}">Rp{{ number_format($preset, 0, ',', '.') }}</label>
                            </div>
                        @endforeach
                        <div class="col-4">
                            <input type="radio" class="btn-check" name="nominal_preset" id="nomCustom" value="custom">
                            <label class="btn wc-btn-outline w-100" for="nomCustom">Lainnya</label>
                        </div>
                    </div>
                    <div class="mb-4">
                        <input type="number" name="nominal" class="form-control" placeholder="Masukkan nominal donasi (Rp)" required>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_anonim" id="anonim">
                        <label class="form-check-label" for="anonim">Donasi sebagai anonim</label>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_recurring" id="rutin" data-bs-toggle="collapse" data-bs-target="#frekuensiBox">
                        <label class="form-check-label" for="rutin">Jadikan donasi rutin</label>
                    </div>
                    <div class="collapse mb-4" id="frekuensiBox">
                        <label class="form-label fw-semibold small">Frekuensi donasi rutin</label>
                        <select name="frequency" class="form-select">
                            <option value="mingguan">Mingguan</option>
                            <option value="bulanan">Bulanan</option>
                        </select>
                        <p class="small text-secondary mt-2 mb-0">
                            Kami akan kirim pengingat email H-1 sebelum jatuh tempo, dan konfirmasi setiap transaksi rutin berhasil.
                        </p>
                    </div>

                    <button type="submit" class="wc-btn-primary w-100">Lanjut ke Pembayaran (Midtrans)</button>
                </form>
            </div>
        </div>
    </div>

@endsection
