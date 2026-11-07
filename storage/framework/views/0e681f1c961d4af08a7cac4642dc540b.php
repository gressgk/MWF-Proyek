<?php $__env->startSection('judul', 'Masuk - WeCare'); ?>

<?php $__env->startSection('konten'); ?>
    <section class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="wc-card p-4 p-md-5">
                    <div class="text-center mb-4">
                        <img src="<?php echo e(asset('img/mascot/duck-heart.png')); ?>" style="height: 70px;" alt="Maskot WeCare">
                        <h4 class="fw-bold mt-3">Selamat Datang Kembali</h4>
                        <p class="text-secondary small">Masuk untuk lanjut berbagi kebaikan</p>
                    </div>

                    <?php if(session('pesan')): ?>
                        <div class="alert alert-info small">
                            <?php echo e(session('pesan')); ?>

                        </div>
                    <?php endif; ?>

                    <form method="POST" action="<?php echo e(route('login')); ?>">
                        <?php echo csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="email" name="email" class="form-control" placeholder="nama@email.com" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Password</label>
                            <input type="password" name="password" class="form-control" placeholder="Password" required>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="remember" id="remember">
                                <label class="form-check-label small" for="remember">Ingat saya</label>
                            </div>
                            <a href="<?php echo e(route('password.request')); ?>" class="small">Lupa password?</a>
                        </div>
                        <button type="submit" class="wc-btn-primary w-100">Masuk</button>
                    </form>

                    <p class="text-center small mt-4 mb-0">
                        Belum punya akun? <a href="<?php echo e(route('register')); ?>">Daftar sebagai Donatur</a><br>
                        Mewakili organisasi? <a href="<?php echo e(route('register.mitra')); ?>">Daftar jadi Mitra</a>
                    </p>
                </div>
            </div>
        </div>
    </section>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.guest', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Users/grace/Herd/fai/project/MWF-Proyek/resources/views/auth/login.blade.php ENDPATH**/ ?>