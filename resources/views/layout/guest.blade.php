<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('judul', 'WeCare')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="{{ asset('css/wecare.css') }}" rel="stylesheet">
</head>

<body>

    <nav class="navbar navbar-expand-lg wc-navbar py-3">
        <div class="container">
            <a class="navbar-brand wc-brand" href="{{ route('home') }}">
                <img src="{{ asset('img/mascot/duck-heart.png') }}" alt="Maskot WeCare">
                WeCare
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navGuest">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse justify-content-end" id="navGuest">
                <div class="d-flex gap-2 mt-3 mt-lg-0">
                    <a href="{{ route('login') }}" class="wc-btn-outline">Masuk</a>
                    <a href="{{ route('register') }}" class="wc-btn-primary">Mulai Donasi</a>
                </div>
            </div>
        </div>
    </nav>

    <main>
        @yield('konten')
    </main>

    <footer class="wc-footer py-4 mt-5">
        <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
            <span>&copy; {{ date('Y') }} WeCare. Berbagi kebaikan, bersama.</span>
            <div class="d-flex gap-3">
                <a href="#">Tentang</a>
                <a href="#">Bantuan</a>
                <a href="#">Kontak</a>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
