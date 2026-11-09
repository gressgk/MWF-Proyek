

<?php $__env->startSection('judul', 'Dashboard Admin'); ?>

<?php $__env->startSection('konten'); ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="wc-stat-card">
                <i class="bi bi-person-heart text-warning fs-4"></i>
                <h4 class="fw-bold mt-2 mb-0"><?php echo e($totalDonatur); ?></h4>
                <p class="small text-secondary mb-0">Total Donatur</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="wc-stat-card">
                <i class="bi bi-building text-warning fs-4"></i>
                <h4 class="fw-bold mt-2 mb-0"><?php echo e($totalPenerima); ?></h4>
                <p class="small text-secondary mb-0">Total Mitra Penerima</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="wc-stat-card">
                <i class="bi bi-cash-coin text-warning fs-4"></i>
                <h4 class="fw-bold mt-2 mb-0">Rp<?php echo e(number_format($totalDana, 0, ',', '.')); ?></h4>
                <p class="small text-secondary mb-0">Total Dana Terkumpul</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="wc-stat-card">
                <i class="bi bi-megaphone text-warning fs-4"></i>
                <h4 class="fw-bold mt-2 mb-0"><?php echo e($totalCampaignAktif); ?> / <?php echo e($totalCampaignPending); ?></h4>
                <p class="small text-secondary mb-0">Campaign Aktif / Pending</p>
            </div>
        </div>
    </div>

    <div class="wc-card p-4">
        <h6 class="fw-bold mb-3">Grafik Donasi per Bulan</h6>
        <div class="d-flex align-items-end gap-3" style="height: 180px;">
            <?php $__empty_1 = true; $__currentLoopData = $donasiPerBulan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $d): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <div class="d-flex flex-column align-items-center justify-content-end" style="flex:1; height:100%;">
                    <div style="width: 100%; max-width: 40px; height: <?php echo e(max(10, min(150, $d->total / 100000))); ?>px; background-color: var(--wc-orange); border-radius: 8px 8px 0 0;"></div>
                    <small class="text-secondary mt-1"><?php echo e($d->bulan); ?></small>
                </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <p class="text-secondary small">Belum ada data donasi.</p>
            <?php endif; ?>
        </div>
    </div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.admin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views\admin\dashboard.blade.php ENDPATH**/ ?>