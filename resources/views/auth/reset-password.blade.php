@extends('layout.guest')

@section('judul', 'Reset Password - WeCare')

@section('konten')
    <section class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="wc-card p-4 p-md-5">
                    <div class="text-center mb-4">
                        <img src="{{ asset('img/mascot/duck-umbrella.png') }}" style="height: 70px;" alt="Maskot WeCare">
                        <h4 class="fw-bold mt-3">Buat Password Baru</h4>
                    </div>

                    @if (session('pesan'))
                        <div class="alert alert-info small">
                            {{ session('pesan') }}
                        </div>
                    @endif

                    <form method="POST" action="{{ route('password.update') }}">
                        @csrf
                        <input type="hidden" name="token" value="{{ $token ?? '' }}">
                        <input type="hidden" name="email" value="{{ $email ?? '' }}">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Password Baru</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Konfirmasi Password Baru</label>
                            <input type="password" name="password_confirmation" class="form-control" required>
                        </div>
                        <button type="submit" class="wc-btn-primary w-100">Simpan Password Baru</button>
                    </form>
                </div>
            </div>
        </div>
    </section>
@endsection
