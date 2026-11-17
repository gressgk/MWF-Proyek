<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $__env->yieldContent('judul', 'WeCare'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo e(asset('css/wecare.css')); ?>" rel="stylesheet">
</head>

<body>

    <div class="d-flex">
        <aside class="wc-sidebar p-3" style="width: 250px; flex-shrink: 0;">
            <a href="<?php echo e(route('admin.dashboard')); ?>" class="wc-brand d-block mb-4 px-2">
                <img src="<?php echo e(asset('img/mascot/duck-heart.png')); ?>" alt="Maskot WeCare">
                WeCare
            </a>
            <div class="small text-uppercase text-secondary fw-bold px-2 mb-2">Admin WeCare</div>
            <ul class="nav nav-pills flex-column mb-4">
                <li class="nav-item">
                    <a class="nav-link <?php echo e(request()->routeIs('admin.dashboard*') ? 'active' : ''); ?>"
                        href="<?php echo e(route('admin.dashboard')); ?>">
                        <i class="bi bi-grid me-2"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo e(request()->routeIs('admin.verifikasi-mitra*') ? 'active' : ''); ?>"
                        href="<?php echo e(route('admin.verifikasi-mitra')); ?>">
                        <i class="bi bi-patch-check me-2"></i>Verifikasi Mitra
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo e(request()->routeIs('admin.approve-campaign*') ? 'active' : ''); ?>"
                        href="<?php echo e(route('admin.approve-campaign')); ?>">
                        <i class="bi bi-megaphone me-2"></i>Approve Campaign
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo e(request()->routeIs('admin.kategori*') ? 'active' : ''); ?>"
                        href="<?php echo e(route('admin.kategori')); ?>">
                        <i class="bi bi-tags me-2"></i>Kelola Kategori
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo e(request()->routeIs('admin.monitoring-donasi*') ? 'active' : ''); ?>"
                        href="<?php echo e(route('admin.monitoring-donasi')); ?>">
                        <i class="bi bi-bar-chart me-2"></i>Monitoring Donasi
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo e(request()->routeIs('admin.user*') ? 'active' : ''); ?>"
                        href="<?php echo e(route('admin.user')); ?>">
                        <i class="bi bi-people me-2"></i>Kelola User
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo e(request()->routeIs('admin.broadcast*') ? 'active' : ''); ?>"
                        href="<?php echo e(route('admin.broadcast')); ?>">
                        <i class="bi bi-megaphone-fill me-2"></i>Broadcast Notifikasi
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo e(request()->routeIs('admin.settings*') ? 'active' : ''); ?>"
                        href="<?php echo e(route('admin.settings')); ?>">
                        <i class="bi bi-gear me-2"></i>Settings
                    </a>
                </li>
            </ul>
            <form method="POST" action="<?php echo e(route('logout')); ?>">
                <?php echo csrf_field(); ?>
                <button class="nav-link text-danger border-0 bg-transparent w-100 text-start" type="submit">
                    <i class="bi bi-box-arrow-right me-2"></i>Keluar
                </button>
            </form>
        </aside>

        <div class="flex-grow-1 d-flex flex-column min-vh-100">
            <nav class="wc-navbar px-4 py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold"><?php echo $__env->yieldContent('judul', 'Admin WeCare'); ?></h5>
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-person-circle fs-4 text-secondary"></i>
                    <span class="fw-semibold"><?php echo e(getAuthUser() ? getAuthUser()->name : 'Admin WeCare'); ?></span>
                </div>
            </nav>
            <main class="container-fluid p-4 flex-grow-1">
                <?php echo $__env->yieldContent('konten'); ?>
            </main>
            <footer class="wc-footer py-3 px-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 small">
                    <span>&copy; <?php echo e(date('Y')); ?> WeCare. Berbagi kebaikan, bersama.</span>
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
<?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views/layout/admin.blade.php ENDPATH**/ ?>