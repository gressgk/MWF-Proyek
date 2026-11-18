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

                @if ($errors->any())
                    <div class="alert alert-danger mb-4">
                        <strong class="d-block mb-1"><i class="bi bi-exclamation-triangle me-1"></i> Perhatian:</strong>
                        <ul class="mb-0 ps-3">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (isset($snapToken) && isset($donation))
                    {{-- Tampilan Transaksi Menunggu Pembayaran (Snap Checkout) --}}
                    <div class="alert alert-warning border-0 bg-light-warning p-4 rounded-3 mb-4" style="background-color: #FFF9E6; border-left: 4px solid #F59E0B !important;">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-secondary small">Order ID</span>
                            <span class="fw-bold font-monospace">{{ $donation->order_id }}</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-secondary small">Total Donasi</span>
                            <span class="fw-bold fs-5 text-primary">Rp{{ number_format($donation->nominal, 0, ',', '.') }}</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-secondary small">Status Pembayaran</span>
                            <span class="badge bg-warning text-dark px-2 py-1">Menunggu Pembayaran</span>
                        </div>
                        @if ($donation->is_anonim)
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <span class="text-secondary small">Tampilan Publik</span>
                                <span class="badge bg-secondary">Anonim</span>
                            </div>
                        @endif
                    </div>

                    <div class="d-grid gap-2">
                        <button type="button" id="payButton" class="wc-btn-primary w-100 py-3 fs-6">
                            <i class="bi bi-credit-card me-2"></i> Lanjutkan Pembayaran (Midtrans)
                        </button>
                        <a href="{{ route('donatur.donasi.uang', $campaign->slug) }}" class="btn wc-btn-outline w-100">
                            Batal & Buat Donasi Baru
                        </a>
                    </div>

                    {{-- Midtrans Snap JS --}}
                    <script src="{{ config('services.midtrans.snap_url') }}" data-client-key="{{ config('services.midtrans.client_key') }}"></script>
                    <script>
                        document.addEventListener('DOMContentLoaded', function () {
                            const snapToken = {{ \Illuminate\Support\Js::from($snapToken) }};
                            function openSnapPopup() {
                                if (typeof snap !== 'undefined' && snapToken) {
                                    snap.pay(snapToken, {
                                        onSuccess: function (result) {
                                            window.location.href = "{{ route('donatur.riwayat') }}?status=pending_verification";
                                        },
                                        onPending: function (result) {
                                            window.location.href = "{{ route('donatur.riwayat') }}?status=waiting_payment";
                                        },
                                        onError: function (result) {
                                            alert("Pembayaran tidak dapat diselesaikan atau gagal.");
                                        },
                                        onClose: function () {
                                            // Donatur menutup popup: biarkan tombol payButton tetap tersedia untuk dibuka kembali
                                        }
                                    });
                                }
                            }

                            const payBtn = document.getElementById('payButton');
                            if (payBtn) {
                                payBtn.addEventListener('click', openSnapPopup);
                            }

                            // Otomatis buka popup saat pertama kali halaman selesai dimuat
                            setTimeout(openSnapPopup, 500);
                        });
                    </script>
                @else
                    {{-- Form Input Donasi Uang --}}
                    <form method="POST" action="{{ route('donatur.donasi.uang', $campaign->slug) }}" id="formDonasiUang">
                        @csrf
                        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey ?? '') }}">

                        <label class="form-label fw-semibold">Pilih Nominal</label>
                        <div class="row g-2 mb-3">
                            @foreach ([50000, 100000, 250000, 500000, 1000000] as $preset)
                                <div class="col-4">
                                    <input type="radio" class="btn-check nominal-preset-radio" name="nominal_preset" id="nom{{ $preset }}" value="{{ $preset }}" {{ old('nominal_preset') == $preset ? 'checked' : '' }}>
                                    <label class="btn wc-btn-outline w-100" for="nom{{ $preset }}">Rp{{ number_format($preset, 0, ',', '.') }}</label>
                                </div>
                            @endforeach
                            <div class="col-4">
                                <input type="radio" class="btn-check nominal-preset-radio" name="nominal_preset" id="nomCustom" value="custom" {{ old('nominal_preset') == 'custom' ? 'checked' : '' }}>
                                <label class="btn wc-btn-outline w-100" for="nomCustom">Lainnya</label>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold small text-secondary">Nominal Donasi (Rp)</label>
                            <input type="number" name="nominal" id="nominalInput" class="form-control" placeholder="Contoh: 100000" min="{{ config('services.midtrans.min_amount', 1000) }}" max="100000000" value="{{ old('nominal') }}" required>
                            <div class="form-text">Minimal donasi Rp{{ number_format(config('services.midtrans.min_amount', 1000), 0, ',', '.') }}.</div>
                        </div>

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="is_anonim" id="anonim" value="1" {{ old('is_anonim') ? 'checked' : '' }}>
                            <label class="form-check-label" for="anonim">Donasi sebagai anonim</label>
                        </div>

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="is_recurring" id="rutin" value="1" data-bs-toggle="collapse" data-bs-target="#frekuensiBox" {{ old('is_recurring') ? 'checked' : '' }}>
                            <label class="form-check-label" for="rutin">Jadikan donasi rutin <span class="badge bg-light text-muted ms-1">Fase E4</span></label>
                        </div>
                        <div class="collapse mb-4 {{ old('is_recurring') ? 'show' : '' }}" id="frekuensiBox">
                            <div class="p-3 bg-light rounded">
                                <label class="form-label fw-semibold small">Frekuensi donasi rutin</label>
                                <select name="frequency" class="form-select mb-2">
                                    <option value="mingguan" {{ old('frequency') == 'mingguan' ? 'selected' : '' }}>Mingguan</option>
                                    <option value="bulanan" {{ old('frequency') == 'bulanan' ? 'selected' : '' }}>Bulanan</option>
                                </select>
                                <p class="small text-muted mb-0">
                                    <i class="bi bi-info-circle me-1"></i> Donasi rutin menggunakan Midtrans Subscription API akan segera aktif pada Fase E4.
                                </p>
                            </div>
                        </div>

                        <button type="submit" id="submitBtn" class="wc-btn-primary w-100 py-2">
                            Lanjut ke Pembayaran (Midtrans)
                        </button>
                    </form>

                    <script>
                        document.addEventListener('DOMContentLoaded', function () {
                            const nominalInput = document.getElementById('nominalInput');
                            const radios = document.querySelectorAll('.nominal-preset-radio');
                            const form = document.getElementById('formDonasiUang');
                            const submitBtn = document.getElementById('submitBtn');

                            radios.forEach(radio => {
                                radio.addEventListener('change', function () {
                                    if (this.value !== 'custom') {
                                        nominalInput.value = this.value;
                                    } else {
                                        nominalInput.value = '';
                                        nominalInput.focus();
                                    }
                                });
                            });

                            if (form && submitBtn) {
                                form.addEventListener('submit', function () {
                                    submitBtn.disabled = true;
                                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Menghubungkan ke Midtrans...';
                                });
                            }
                        });
                    </script>
                @endif
            </div>
        </div>
    </div>

@endsection
