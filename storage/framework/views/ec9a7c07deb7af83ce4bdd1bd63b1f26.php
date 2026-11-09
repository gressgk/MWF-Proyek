

<?php $__env->startSection('judul', 'Broadcast Notifikasi'); ?>

<?php $__env->startSection('konten'); ?>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="wc-card p-4">
                <h6 class="fw-bold mb-3">Kirim Broadcast Baru</h6>
                <form method="POST" action="<?php echo e(route('admin.broadcast')); ?>">
                    <?php echo csrf_field(); ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Judul</label>
                        <input type="text" name="judul" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Isi Pesan</label>
                        <textarea name="isi" class="form-control" rows="4" required></textarea>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold small">Target Penerima</label>
                        <select name="target_role" class="form-select">
                            <option value="semua">Semua User</option>
                            <option value="donatur">Donatur</option>
                            <option value="penerima">Mitra Penerima</option>
                        </select>
                    </div>
                    <button type="submit" class="wc-btn-primary w-100">Kirim Broadcast</button>
                </form>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="wc-card p-4">
                <h6 class="fw-bold mb-3">Riwayat Broadcast</h6>
                <?php $__empty_1 = true; $__currentLoopData = $riwayatBroadcast; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <div class="border-bottom pb-3 mb-3">
                        <div class="d-flex justify-content-between">
                            <p class="fw-semibold mb-1"><?php echo e($item->judul); ?></p>
                            <span class="wc-badge wc-badge-biasa"><?php echo e(ucfirst($item->target_role)); ?></span>
                        </div>
                        <p class="small text-secondary mb-1"><?php echo e($item->isi); ?></p>
                        <p class="small text-secondary mb-0"><?php echo e($item->created_at->format('d M Y, H:i')); ?></p>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <p class="small text-secondary mb-0">Belum ada broadcast yang dikirim.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.admin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views\admin\broadcast.blade.php ENDPATH**/ ?>