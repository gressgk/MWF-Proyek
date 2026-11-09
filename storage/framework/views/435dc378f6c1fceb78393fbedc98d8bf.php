

<?php $__env->startSection('judul', 'Settings'); ?>

<?php $__env->startSection('konten'); ?>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="wc-card p-4 mb-4">
                <h6 class="fw-bold mb-3">Profil Admin</h6>
                <form method="POST" action="<?php echo e(route('admin.settings')); ?>">
                    <?php echo csrf_field(); ?>
                    <?php echo method_field('PUT'); ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Nama</label>
                        <input type="text" name="name" class="form-control" value="<?php echo e($user->name ?? ''); ?>">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold small">Email</label>
                        <input type="email" name="email" class="form-control" value="<?php echo e($user->email ?? ''); ?>">
                    </div>
                    <button type="submit" class="wc-btn-primary">Simpan Perubahan</button>
                </form>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="wc-card p-4 mb-4">
                <h6 class="fw-bold mb-3">Ganti Password</h6>
                <form>
                    <div class="mb-3"><input type="password" class="form-control" placeholder="Password lama"></div>
                    <div class="mb-3"><input type="password" class="form-control" placeholder="Password baru"></div>
                    <div class="mb-4"><input type="password" class="form-control" placeholder="Konfirmasi password baru"></div>
                    <button type="submit" class="wc-btn-outline w-100">Ganti Password</button>
                </form>
            </div>

            <div class="wc-card p-4">
                <h6 class="fw-bold mb-3">Pengaturan Platform</h6>
                <div class="mb-3 form-check form-switch">
                    <input class="form-check-input" type="checkbox" checked>
                    <label class="form-check-label small">Wajib verifikasi email saat registrasi</label>
                </div>
                <div class="mb-3 form-check form-switch">
                    <input class="form-check-input" type="checkbox" checked>
                    <label class="form-check-label small">Moderasi campaign sebelum tayang</label>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox">
                    <label class="form-check-label small">Mode maintenance</label>
                </div>
            </div>
        </div>
    </div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.admin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views/admin/settings.blade.php ENDPATH**/ ?>