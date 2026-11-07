<?php $__env->startSection('judul', 'Reset Password - WeCare'); ?>

<?php $__env->startSection('konten'); ?>
    <section class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="wc-card p-4 p-md-5">
                    <div class="text-center mb-4">
                        <img src="<?php echo e(asset('img/mascot/duck-umbrella.png')); ?>" style="height: 70px;" alt="Maskot WeCare">
                        <h4 class="fw-bold mt-3">Buat Password Baru</h4>
                    </div>

                    <?php if(session('pesan')): ?>
                        <div class="alert alert-info small">
                            <?php echo e(session('pesan')); ?>

                        </div>
                    <?php endif; ?>

                    <form method="POST" action="<?php echo e(route('password.update')); ?>">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="token" value="<?php echo e($token ?? ''); ?>">
                        <input type="hidden" name="email" value="<?php echo e($email ?? ''); ?>">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Password Baru</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Konfirmasi Password Baru</label>
                            <input type="password" name="password_confirmation" class="form-control" required>
                        </div>
                        <button type="submit" class="wc-btn-primary w-100">Simpan Password Baru</button>
                    </form>
                </div>
            </div>
        </div>
    </section>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.guest', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Users/grace/Herd/fai/project/MWF-Proyek/resources/views/auth/reset-password.blade.php ENDPATH**/ ?>