<?php $__env->startSection('judul', 'Lupa Password - WeCare'); ?>

<?php $__env->startSection('konten'); ?>
    <section class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="wc-card p-4 p-md-5 text-center">
                    <img src="<?php echo e(asset('img/mascot/duck-umbrella.png')); ?>" style="height: 70px;" alt="Maskot WeCare">
                    <h4 class="fw-bold mt-3">Lupa Password?</h4>
                    <p class="text-secondary small mb-4">Masukkan email kamu, kami akan kirim link reset password.</p>

                    <?php if(session('pesan')): ?>
                        <div class="alert alert-info small text-start">
                            <?php echo e(session('pesan')); ?>

                        </div>
                    <?php endif; ?>

                    <form method="POST" action="<?php echo e(route('password.request')); ?>" class="text-start">
                        <?php echo csrf_field(); ?>
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="email" name="email" class="form-control" placeholder="nama@email.com" required>
                        </div>
                        <button type="submit" class="wc-btn-primary w-100">Kirim Link Reset</button>
                    </form>

                    <p class="small mt-4 mb-0">
                        <a href="<?php echo e(route('login')); ?>"><i class="bi bi-arrow-left"></i> Kembali ke halaman masuk</a>
                    </p>
                </div>
            </div>
        </div>
    </section>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.guest', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Users/grace/Herd/fai/project/MWF-Proyek/resources/views/auth/forgot-password.blade.php ENDPATH**/ ?>