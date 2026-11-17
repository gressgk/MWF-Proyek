

<?php $__env->startSection('judul', 'Beranda - WeCare'); ?>

<?php $__env->startSection('konten'); ?>

    <div class="wc-card p-4 mb-4 d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
        <div>
            <h4 class="fw-bold mb-1">Halo, <?php echo e(getAuthUser() ? getAuthUser()->name : 'Donatur'); ?> 👋</h4>
            <p class="text-secondary mb-0">Yuk cari campaign yang ingin kamu bantu hari ini.</p>
        </div>
        <img src="<?php echo e(asset('img/mascot/duck-donationbox.png')); ?>" style="height: 80px;" alt="Maskot WeCare">
    </div>

    <form class="wc-card p-3 mb-4">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-semibold">Cari campaign</label>
                <input type="text" class="form-control" placeholder="Cari berdasarkan judul...">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Kategori</label>
                <select class="form-select">
                    <option value="">Semua kategori</option>
                    <?php $__currentLoopData = $kategoriList; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kat): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($kat->slug); ?>"><?php echo e($kat->nama); ?></option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Tipe Bantuan</label>
                <select class="form-select">
                    <option value="">Semua tipe</option>
                    <option value="uang">Uang</option>
                    <option value="barang">Barang</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="wc-btn-primary w-100">Cari</button>
            </div>
        </div>
    </form>

    <div class="row g-4">
        <?php $__empty_1 = true; $__currentLoopData = $campaigns; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $campaign): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <?php echo $__env->make('partials.campaign-card', ['campaign' => $campaign], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <p class="text-secondary">Belum ada campaign aktif saat ini.</p>
        <?php endif; ?>
    </div>

    <div class="d-flex justify-content-center mt-4">
        <?php echo e($campaigns->links()); ?>

    </div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.donatur', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views/donatur/beranda.blade.php ENDPATH**/ ?>