

<?php $__env->startSection('judul', 'WeCare - Platform Donasi Uang & Barang'); ?>

<?php $__env->startSection('konten'); ?>

    <section class="wc-hero py-5">
        <div class="container py-5">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <span class="wc-badge wc-badge-biasa mb-3">Platform donasi terpercaya</span>
                    <h1 class="fw-bold display-6 mb-3">Berbagi kebaikan lebih mudah bersama WeCare</h1>
                    <p class="text-secondary mb-4">
                        Salurkan donasi uang maupun barang untuk campaign yang paling membutuhkan.
                        Transparan, mudah dipantau, dan langsung sampai ke penerima manfaat.
                    </p>
                    <div class="d-flex gap-2">
                        <a href="<?php echo e(route('register')); ?>" class="wc-btn-primary">Mulai Donasi</a>
                        <a href="<?php echo e(route('register.mitra')); ?>" class="wc-btn-outline">Daftar Jadi Mitra</a>
                    </div>
                </div>
                <div class="col-lg-6 text-center mt-5 mt-lg-0">
                    <img src="<?php echo e(asset('img/mascot/duck-donationbox.png')); ?>" alt="Maskot WeCare" style="max-height: 320px;">
                </div>
            </div>
        </div>
    </section>

    <section class="container py-5">
        <div class="row text-center g-4">
            <div class="col-md-4">
                <div class="wc-stat-card">
                    <i class="bi bi-cash-coin fs-2 text-warning"></i>
                    <h6 class="fw-bold mt-2">Donasi Uang</h6>
                    <p class="small text-secondary mb-0">Bayar cepat & aman lewat Midtrans, bisa sekali atau rutin.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="wc-stat-card">
                    <i class="bi bi-box-seam fs-2 text-warning"></i>
                    <h6 class="fw-bold mt-2">Donasi Barang</h6>
                    <p class="small text-secondary mb-0">Kirim barang layak pakai (non-makanan) langsung ke penerima.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="wc-stat-card">
                    <i class="bi bi-file-earmark-check fs-2 text-warning"></i>
                    <h6 class="fw-bold mt-2">Laporan Transparan</h6>
                    <p class="small text-secondary mb-0">Pantau langsung penggunaan dana dan barang yang kamu donasikan.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="container pb-5">
        <div class="d-flex justify-content-between align-items-end mb-4">
            <div>
                <h4 class="fw-bold mb-1">Campaign yang butuh bantuanmu</h4>
                <p class="text-secondary mb-0">Pilih campaign dan mulai berbagi sekarang.</p>
            </div>
            <a href="<?php echo e(route('register')); ?>">Lihat semua &rarr;</a>
        </div>
        <div class="row g-4">
            <?php $__currentLoopData = $campaigns; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $campaign): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <?php echo $__env->make('partials.campaign-card', ['campaign' => $campaign], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>
    </section>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.guest', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views\home.blade.php ENDPATH**/ ?>