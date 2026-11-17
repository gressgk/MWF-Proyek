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

<body class="d-flex flex-column min-vh-100">

    <nav class="navbar navbar-expand-lg wc-navbar py-3">
        <div class="container">
            <a class="navbar-brand wc-brand" href="<?php echo e(route('home')); ?>">
                <img src="<?php echo e(asset('img/mascot/duck-heart.png')); ?>" alt="Maskot WeCare">
                WeCare
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navGuest">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse justify-content-end" id="navGuest">
                <div class="d-flex gap-2 mt-3 mt-lg-0">
                    <a href="<?php echo e(route('login')); ?>" class="wc-btn-outline">Masuk</a>
                    <a href="<?php echo e(route('register')); ?>" class="wc-btn-primary">Mulai Donasi</a>
                </div>
            </div>
        </div>
    </nav>

    <main class="flex-grow-1">
        <?php echo $__env->yieldContent('konten'); ?>
    </main>

    <footer class="wc-footer py-4 mt-5">
        <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
            <span>&copy; <?php echo e(date('Y')); ?> WeCare. Berbagi kebaikan, bersama.</span>
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
<?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views\layout\guest.blade.php ENDPATH**/ ?>