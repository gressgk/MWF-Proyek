@extends('layout.guest')

@section('judul', 'Lupa Password - WeCare')

@section('konten')
    <section class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="wc-card p-4 p-md-5 text-center">
                    <img src="{{ asset('img/mascot/duck-umbrella.png') }}" style="height: 70px;" alt="Maskot WeCare">
                    <h4 class="fw-bold mt-3">Lupa Password?</h4>
                    <p class="text-secondary small mb-4">Masukkan email kamu, kami akan kirim link reset password.</p>

                    @if (session('pesan'))
                        <div class="alert alert-info small text-start">
                            {{ session('pesan') }}
                        </div>
                    @endif

                    <form method="POST" action="{{ route('password.request') }}" class="text-start">
                        @csrf
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="email" name="email" class="form-control" placeholder="nama@email.com" required>
                        </div>
                        <button type="submit" class="wc-btn-primary w-100">Kirim Link Reset</button>
                    </form>

                    <p class="small mt-4 mb-0">
                        <a href="{{ route('login') }}"><i class="bi bi-arrow-left"></i> Kembali ke halaman masuk</a>
                    </p>
                </div>
            </div>
        </div>
    </section>
@endsection
