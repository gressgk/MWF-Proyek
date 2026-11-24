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
                            <label class="form-check-label fw-semibold" for="rutin">Jadikan donasi rutin <span class="badge bg-primary text-white ms-1">Midtrans Subscription</span></label>
                        </div>
                        <div class="collapse mb-4 {{ old('is_recurring') ? 'show' : '' }}" id="frekuensiBox">
                            <div class="p-3 bg-light rounded border">
                                <label class="form-label fw-semibold small">Frekuensi Penagihan Otomatis</label>
                                <select name="frequency" class="form-select mb-3">
                                    <option value="mingguan" {{ old('frequency') == 'mingguan' ? 'selected' : '' }}>Mingguan (setiap 1 minggu)</option>
                                    <option value="bulanan" {{ old('frequency', 'bulanan') == 'bulanan' ? 'selected' : '' }}>Bulanan (setiap 1 bulan)</option>
                                </select>

                                {{-- Peringatan Metode Pembayaran --}}
                                <div class="alert alert-secondary py-2 px-3 small mb-3">
                                    <i class="bi bi-info-circle me-1"></i> Donasi rutin saat ini didukung melalui <strong>Kartu Kredit / Debit</strong> dengan tokenisasi aman Midtrans. Donasi rutin GoPay memerlukan <em>GoPay Account Linking</em> yang belum tersedia.
                                </div>

                                {{-- Input Kartu Tokenisasi Frontend (DILARANG menggunakan name attribute agar data kartu tidak terkirim ke backend WeCare) --}}
                                <div class="border rounded p-3 bg-white mb-3" id="cardTokenizationSection">
                                    <h6 class="fw-semibold small mb-2"><i class="bi bi-shield-lock me-1 text-primary"></i> Data Kartu (Tokenisasi Langsung ke Midtrans)</h6>
                                    <div class="mb-2">
                                        <label class="form-label small text-secondary mb-1">Nomor Kartu</label>
                                        <input type="text" id="card_number_input" class="form-control form-control-sm font-monospace" placeholder="4111 2222 3333 4444" maxlength="19" autocomplete="cc-number">
                                    </div>
                                    <div class="row g-2 mb-2">
                                        <div class="col-4">
                                            <label class="form-label small text-secondary mb-1">Bulan (MM)</label>
                                            <input type="text" id="card_exp_month_input" class="form-control form-control-sm text-center" placeholder="MM" maxlength="2">
                                        </div>
                                        <div class="col-4">
                                            <label class="form-label small text-secondary mb-1">Tahun (YY)</label>
                                            <input type="text" id="card_exp_year_input" class="form-control form-control-sm text-center" placeholder="YY" maxlength="2">
                                        </div>
                                        <div class="col-4">
                                            <label class="form-label small text-secondary mb-1">CVV</label>
                                            <input type="password" id="card_cvv_input" class="form-control form-control-sm text-center" placeholder="123" maxlength="4" autocomplete="cc-csc">
                                        </div>
                                    </div>
                                    <div id="cardTokenError" class="text-danger small mt-1 d-none"></div>
                                </div>

                                {{-- Hidden fields untuk payload backend yang aman --}}
                                <input type="hidden" name="payment_type" value="credit_card">
                                <input type="hidden" name="payment_token" id="payment_token" value="{{ old('payment_token') }}">

                                <div class="form-check mb-2">
                                    {{-- Consent tidak boleh default dicentang --}}
                                    <input class="form-check-input" type="checkbox" name="recurring_consent" id="recurringConsent" value="accepted" {{ old('recurring_consent') === 'accepted' ? 'checked' : '' }}>
                                    <label class="form-check-label small" for="recurringConsent">
                                        Saya menyetujui penagihan otomatis berkala sesuai nominal dan frekuensi yang dipilih melalui Midtrans Subscription API.
                                    </label>
                                </div>

                                <p class="small text-muted mb-0">
                                    <i class="bi bi-shield-check me-1"></i> Data kartu Anda diproses langsung oleh Midtrans dan tidak pernah disimpan di server WeCare.
                                </p>
                            </div>
                        </div>

                        <button type="submit" id="submitBtn" class="wc-btn-primary w-100 py-2">
                            Lanjut ke Pembayaran (Midtrans)
                        </button>
                    </form>

                    <script id="midtrans-script" type="text/javascript" src="{{ config('services.midtrans.is_production') ? 'https://api.midtrans.com/v2/assets/js/midtrans-new-3ds.min.js' : 'https://api.sandbox.midtrans.com/v2/assets/js/midtrans-new-3ds.min.js' }}"
                            data-environment="{{ config('services.midtrans.is_production') ? 'production' : 'sandbox' }}"></script>
                    <script>
                        document.addEventListener('DOMContentLoaded', function () {
                            const nominalInput = document.getElementById('nominalInput');
                            const radios = document.querySelectorAll('.nominal-preset-radio');
                            const form = document.getElementById('formDonasiUang');
                            const submitBtn = document.getElementById('submitBtn');
                            const rutinCheck = document.getElementById('rutin');
                            const tokenField = document.getElementById('payment_token');
                            const tokenError = document.getElementById('cardTokenError');
                            const clientKey = {{ \Illuminate\Support\Js::from(config('services.midtrans.client_key')) }};

                            function resetSubmitLoading() {
                                submitBtn.disabled = false;
                                submitBtn.innerHTML = 'Lanjut ke Pembayaran (Midtrans)';
                            }

                            function showTokenError(message) {
                                if (tokenError) {
                                    tokenError.textContent = message;
                                    tokenError.classList.remove('d-none');
                                } else {
                                    alert(message);
                                }
                            }

                            // Konfigurasi object library secara langsung agar context MidtransNew3ds tetap utuh.
                            if (typeof MidtransNew3ds !== 'undefined') {
                                if (clientKey) {
                                    MidtransNew3ds.clientKey = clientKey;
                                }
                            } else if (typeof Midtrans !== 'undefined') {
                                if (clientKey) {
                                    Midtrans.clientKey = clientKey;
                                }
                            }

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
                                form.addEventListener('submit', function (e) {
                                    // Jika donasi rutin dipilih
                                    if (rutinCheck && rutinCheck.checked) {
                                        const consent = document.getElementById('recurringConsent');
                                        if (!consent || !consent.checked) {
                                            alert('Persetujuan donasi rutin wajib disetujui.');
                                            e.preventDefault();
                                            return;
                                        }

                                        const cardNum = document.getElementById('card_number_input');
                                        const expM = document.getElementById('card_exp_month_input');
                                        const expY = document.getElementById('card_exp_year_input');
                                        const cvv = document.getElementById('card_cvv_input');

                                        // Jika Midtrans JS library tersedia dan nomor kartu diisi, tokenisasi kartu
                                        if (clientKey && cardNum && cardNum.value) {
                                            e.preventDefault();
                                            submitBtn.disabled = true;
                                            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Tokenisasi kartu Midtrans...';

                                            const cardData = {
                                                card_number: cardNum.value.replace(/\s+/g, ''),
                                                card_exp_month: expM ? expM.value : '',
                                                card_exp_year: expY ? (expY.value.length === 2 ? '20' + expY.value : expY.value) : '',
                                                card_cvv: cvv ? cvv.value : ''
                                            };

                                            let tokenCallbackReceived = false;
                                            let tokenTimedOut = false;
                                            const tokenTimeout = window.setTimeout(function () {
                                                if (!tokenCallbackReceived) {
                                                    tokenTimedOut = true;
                                                    resetSubmitLoading();
                                                    showTokenError('Midtrans tidak merespons dalam waktu yang ditentukan. Silakan coba lagi.');
                                                }
                                            }, 15000);

                                            try {
                                                const tokenOptions = {
                                                    onSuccess: function (res) {
                                                        tokenCallbackReceived = true;
                                                        window.clearTimeout(tokenTimeout);
                                                        if (tokenTimedOut) {
                                                            return;
                                                        }
                                                        if (!res || !res.token_id) {
                                                            resetSubmitLoading();
                                                            showTokenError('Midtrans tidak mengembalikan token kartu yang valid.');
                                                            return;
                                                        }
                                                        tokenField.value = res.token_id;
                                                        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Memproses otorisasi awal kartu...';

                                                        // Kirim form via fetch JSON untuk menangani potensi challenge 3DS
                                                        const formData = new FormData(form);
                                                        fetch(form.action, {
                                                            method: 'POST',
                                                            body: formData,
                                                            headers: {
                                                                'Accept': 'application/json',
                                                                'X-Requested-With': 'XMLHttpRequest'
                                                            }
                                                        })
                                                        .then(async response => {
                                                            const data = await response.json().catch(() => ({}));
                                                            if (!response.ok) {
                                                                throw new Error(data.message || 'Terjadi kesalahan saat memproses donasi.');
                                                            }
                                                            return data;
                                                        })
                                                        .then(data => {
                                                            // Jika memerlukan 3DS authentication flow resmi
                                                            if (data.requires_3ds && data.redirect_url) {
                                                                if (typeof MidtransNew3ds !== 'undefined' && typeof MidtransNew3ds.authenticate === 'function') {
                                                                    MidtransNew3ds.authenticate(data.redirect_url, {
                                                                        performAuthentication: function (redirectUrl) {
                                                                            window.location.href = redirectUrl;
                                                                        },
                                                                        onSuccess: function () {
                                                                            // Jangan gunakan frontend callback sebagai bukti pembayaran; arahkan ke riwayat
                                                                            window.location.href = "{{ route('donatur.riwayat') }}?status=verifying";
                                                                        },
                                                                        onFailure: function () {
                                                                            resetSubmitLoading();
                                                                            alert('Otorisasi 3DS ditolak atau dibatalkan.');
                                                                        },
                                                                        onPending: function () {
                                                                            window.location.href = "{{ route('donatur.riwayat') }}?status=waiting_payment";
                                                                        }
                                                                    });
                                                                } else {
                                                                    window.location.href = data.redirect_url;
                                                                }
                                                            } else {
                                                                // Aktivasi sukses tanpa 3DS
                                                                window.location.href = "{{ route('donatur.riwayat') }}?status=subscription_created";
                                                            }
                                                        })
                                                        .catch(err => {
                                                            resetSubmitLoading();
                                                            showTokenError(err.message);
                                                        });
                                                    },
                                                    onFailure: function (res) {
                                                        tokenCallbackReceived = true;
                                                        window.clearTimeout(tokenTimeout);
                                                        if (tokenTimedOut) {
                                                            return;
                                                        }
                                                        resetSubmitLoading();
                                                        showTokenError(res && res.validation_messages
                                                            ? res.validation_messages.join(', ')
                                                            : 'Gagal memproses kartu melalui Midtrans.');
                                                    }
                                                };

                                                if (typeof MidtransNew3ds !== 'undefined' && typeof MidtransNew3ds.getCardToken === 'function') {
                                                    MidtransNew3ds.getCardToken(cardData, tokenOptions);
                                                } else if (typeof Midtrans !== 'undefined'
                                                    && typeof Midtrans.card !== 'undefined'
                                                    && typeof Midtrans.card.getCardToken === 'function'
                                                ) {
                                                    Midtrans.card.getCardToken(cardData, tokenOptions);
                                                } else {
                                                    window.clearTimeout(tokenTimeout);
                                                    resetSubmitLoading();
                                                    showTokenError('Library Midtrans belum siap. Muat ulang halaman lalu coba kembali.');
                                                }
                                            } catch (error) {
                                                window.clearTimeout(tokenTimeout);
                                                resetSubmitLoading();
                                                showTokenError('Tokenisasi kartu gagal karena library Midtrans tidak dapat dijalankan.');
                                            }
                                            return;
                                        }

                                        if (!clientKey) {
                                            e.preventDefault();
                                            resetSubmitLoading();
                                            showTokenError('Konfigurasi Midtrans belum tersedia. Silakan hubungi pengelola.');
                                            return;
                                        }
                                    }

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
