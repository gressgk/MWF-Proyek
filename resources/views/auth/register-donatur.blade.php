@extends('layout.guest')

@section('judul', 'Daftar Donatur - WeCare')

@section('konten')
    <section class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-7 col-lg-6">
                <div class="wc-card p-4 p-md-5">
                    <div class="text-center mb-4">
                        <img src="{{ asset('img/mascot/duck-box.png') }}" style="height: 70px;" alt="Maskot WeCare">
                        <h4 class="fw-bold mt-3">Daftar Sebagai Donatur</h4>
                        <p class="text-secondary small">Mulai perjalanan berbagimu bersama WeCare</p>
                    </div>

                    <form method="POST" action="{{ route('register') }}">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Nama Lengkap</label>
                            <input type="text" name="name" class="form-control" placeholder="Nama lengkap kamu" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="email" name="email" class="form-control" placeholder="nama@email.com" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">No. HP</label>
                            <input type="text" name="phone" class="form-control" placeholder="08xxxxxxxxxx" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Password</label>
                                <input type="password" name="password" class="form-control" placeholder="Minimal 8 karakter" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Konfirmasi Password</label>
                                <input type="password" name="password_confirmation" class="form-control" placeholder="Ulangi password" required>
                            </div>
                        </div>
                        <div class="form-check mb-4">
                            <input class="form-check-input" type="checkbox" name="setuju" id="setuju" required>
                            <label class="form-check-label small" for="setuju">
                                Saya menyetujui syarat & ketentuan serta kebijakan privasi WeCare
                            </label>
                        </div>
                        <button type="submit" class="wc-btn-primary w-100">Daftar Sekarang</button>
                    </form>

                    <p class="text-center small mt-4 mb-0">
                        Sudah punya akun? <a href="{{ route('login') }}">Masuk di sini</a>
                    </p>
                </div>
            </div>
        </div>
    </section>
@endsection
