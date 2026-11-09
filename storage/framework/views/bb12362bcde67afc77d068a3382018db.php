

<?php $__env->startSection('judul', 'Laporan Penggunaan'); ?>

<?php $__env->startSection('konten'); ?>

    <h5 class="fw-bold mb-4">Laporan Penggunaan &mdash; <?php echo e($campaign->judul ?? ''); ?></h5>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="wc-card p-4">
                <h6 class="fw-bold mb-3">Tambah Update Laporan</h6>
                <form method="POST" action="<?php echo e(route('mitra.laporan')); ?>" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="campaign_id" value="<?php echo e($campaign->id ?? ''); ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Judul</label>
                        <input type="text" name="judul" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Isi Laporan</label>
                        <textarea name="isi" class="form-control" rows="4" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Foto Bukti</label>
                        <input type="file" name="foto_bukti" class="form-control">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold small">Nominal yang Dipakai (jika donasi uang)</label>
                        <input type="number" name="nominal_terpakai" class="form-control" placeholder="Rp">
                    </div>
                    <button type="submit" class="wc-btn-primary w-100">Publikasikan Update</button>
                </form>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="wc-card p-4">
                <h6 class="fw-bold mb-3">Riwayat Update</h6>
                <?php $__empty_1 = true; $__currentLoopData = $laporan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $update): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <div class="border-bottom pb-3 mb-3">
                        <p class="fw-semibold mb-1"><?php echo e($update->judul); ?></p>
                        <p class="small text-secondary mb-1"><?php echo e($update->isi); ?></p>
                        <?php if($update->nominal_terpakai): ?>
                            <p class="small mb-0">Dana dipakai: Rp<?php echo e(number_format($update->nominal_terpakai, 0, ',', '.')); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <p class="small text-secondary mb-0">Belum ada update laporan untuk campaign ini.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.penerima', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views\penerima\laporan.blade.php ENDPATH**/ ?>