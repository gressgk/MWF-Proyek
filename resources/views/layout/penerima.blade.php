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

    <div class="d-flex">
        <aside class="wc-sidebar p-3" style="width: 250px; flex-shrink: 0;">
            <a href="{{ route('mitra.dashboard') }}" class="wc-brand d-block mb-4 px-2">
                <img src="{{ asset('img/mascot/duck-heart.png') }}" alt="Maskot WeCare">
                WeCare
            </a>
            <div class="small text-uppercase text-secondary fw-bold px-2 mb-2">Mitra WeCare</div>
            <ul class="nav nav-pills flex-column mb-4">
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('mitra.dashboard*') ? 'active' : '' }}"
                        href="{{ route('mitra.dashboard') }}">
                        <i class="bi bi-grid me-2"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('mitra.campaign.index*') ? 'active' : '' }}"
                        href="{{ route('mitra.campaign.index') }}">
                        <i class="bi bi-megaphone me-2"></i>Campaign Saya
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('mitra.donasi-masuk*') ? 'active' : '' }}"
                        href="{{ route('mitra.donasi-masuk') }}">
                        <i class="bi bi-inbox me-2"></i>Donasi Masuk
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('mitra.laporan*') ? 'active' : '' }}"
                        href="{{ route('mitra.laporan') }}">
                        <i class="bi bi-file-earmark-text me-2"></i>Laporan Penggunaan
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('mitra.chat*') ? 'active' : '' }}"
                        href="{{ route('mitra.chat') }}">
                        <i class="bi bi-chat-dots me-2"></i>Chat
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('mitra.settings*') ? 'active' : '' }}"
                        href="{{ route('mitra.settings') }}">
                        <i class="bi bi-gear me-2"></i>Settings
                    </a>
                </li>
            </ul>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="nav-link text-danger border-0 bg-transparent w-100 text-start" type="submit">
                    <i class="bi bi-box-arrow-right me-2"></i>Keluar
                </button>
            </form>
        </aside>

        <div class="flex-grow-1 d-flex flex-column min-vh-100">
            <nav class="wc-navbar px-4 py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold">@yield('judul', 'Mitra WeCare')</h5>
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-person-circle fs-4 text-secondary"></i>
                    <span class="fw-semibold">{{ getAuthUser() ? getAuthUser()->name : 'Mitra WeCare' }}</span>
                </div>
            </nav>
            <main class="container-fluid p-4 flex-grow-1">
                @yield('konten')
            </main>
            <footer class="wc-footer py-3 px-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 small">
                    <span>&copy; {{ date('Y') }} WeCare. Berbagi kebaikan, bersama.</span>
                    <div class="d-flex gap-3">
                        <a href="#">Tentang</a>
                        <a href="#">Bantuan</a>
                        <a href="#">Kontak</a>
                    </div>
                </div>
            </footer>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
