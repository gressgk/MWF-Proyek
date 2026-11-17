

<?php $__env->startSection('judul', 'Settings - WeCare'); ?>

<?php $__env->startSection('konten'); ?>

    <h4 class="fw-bold mb-4">Settings</h4>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="wc-card p-4 mb-4">
                <h6 class="fw-bold mb-3">Edit Profil</h6>
                <form method="POST" action="<?php echo e(route('donatur.settings')); ?>">
                    <?php echo csrf_field(); ?>
                    <?php echo method_field('PUT'); ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Nama Lengkap</label>
                        <input type="text" name="name" class="form-control" value="<?php echo e($user->name ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">No. HP</label>
                        <input type="text" name="phone" class="form-control" value="<?php echo e($user->phone ?? ''); ?>">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold small">Foto Profil</label>
                        <input type="file" name="avatar" class="form-control">
                    </div>
                    <button type="submit" class="wc-btn-primary">Simpan Perubahan</button>
                </form>
            </div>

            <div class="wc-card p-4">
                <h6 class="fw-bold mb-3">Ganti Password</h6>
                <form>
                    <div class="mb-3">
                        <input type="password" class="form-control" placeholder="Password lama">
                    </div>
                    <div class="mb-3">
                        <input type="password" class="form-control" placeholder="Password baru">
                    </div>
                    <div class="mb-4">
                        <input type="password" class="form-control" placeholder="Konfirmasi password baru">
                    </div>
                    <button type="submit" class="wc-btn-outline">Ganti Password</button>
                </form>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="wc-card p-4 mb-4">
                <h6 class="fw-bold mb-3">Donasi Rutin Aktif</h6>
                <?php $__empty_1 = true; $__currentLoopData = $donasiRutin; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $rutin): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <div class="border-bottom pb-3 mb-3">
                        <p class="fw-semibold mb-0"><?php echo e($rutin->Campaign->judul); ?></p>
                        <p class="small text-secondary mb-2">Rp<?php echo e(number_format($rutin->nominal, 0, ',', '.')); ?> &middot; <?php echo e(ucfirst($rutin->frequency)); ?></p>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm wc-btn-outline">Pause</button>
                            <button class="btn btn-sm btn-outline-danger">Batalkan</button>
                        </div>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <p class="small text-secondary mb-0">Belum ada donasi rutin yang aktif.</p>
                <?php endif; ?>
            </div>

            <div class="wc-card p-4">
                <h6 class="fw-bold mb-3">Preferensi Notifikasi</h6>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" checked>
                    <label class="form-check-label small">Email saat status donasi berubah</label>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" checked>
                    <label class="form-check-label small">Email pengingat donasi rutin</label>
                </div>
            </div>
        </div>
    </div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.donatur', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views\donatur\settings.blade.php ENDPATH**/ ?>