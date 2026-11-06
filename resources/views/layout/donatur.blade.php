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

    <nav class="navbar navbar-expand-lg wc-navbar py-3 sticky-top">
        <div class="container">
            <a class="navbar-brand wc-brand" href="{{ route('donatur.beranda') }}">
                <img src="{{ asset('img/mascot/duck-heart.png') }}" alt="Maskot WeCare">
                WeCare
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navDonatur">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navDonatur">
                <ul class="navbar-nav mx-auto gap-2">
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('donatur.beranda') ? 'fw-bold text-dark' : '' }}" href="{{ route('donatur.beranda') }}">Beranda</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('donatur.riwayat') ? 'fw-bold text-dark' : '' }}" href="{{ route('donatur.riwayat') }}">Riwayat Donasi</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('donatur.chat*') ? 'fw-bold text-dark' : '' }}" href="{{ route('donatur.chat') }}">Chat</a>
                    </li>
                </ul>
                <div class="dropdown mt-3 mt-lg-0">
                    <a class="d-flex align-items-center gap-2 dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle fs-4 text-secondary"></i>
                        <span class="fw-semibold text-dark">{{ getAuthUser() ? getAuthUser()->name : 'Donatur' }}</span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="{{ route('donatur.settings') }}"><i class="bi bi-gear me-2"></i>Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="dropdown-item text-danger" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Keluar</button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <main class="container py-4">
        @yield('konten')
    </main>

    <img src="{{ asset('img/mascot/duck-umbrella.png') }}" alt="" class="wc-mascot-float d-none d-lg-block">

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
