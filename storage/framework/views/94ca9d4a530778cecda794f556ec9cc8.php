<?php $__env->startSection('judul', 'Daftar Mitra - WeCare'); ?>

<?php $__env->startSection('konten'); ?>
    <section class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-7">
                <div class="wc-card p-4 p-md-5">
                    <div class="text-center mb-4">
                        <img src="<?php echo e(asset('img/mascot/duck-box.png')); ?>" style="height: 70px;" alt="Maskot WeCare">
                        <h4 class="fw-bold mt-3">Daftar Jadi Mitra WeCare</h4>
                        <p class="text-secondary small">Langkah 2 dari 2 &mdash; Data Organisasi</p>
                    </div>

                    <form method="POST" action="<?php echo e(route('register.mitra.organisasi.store')); ?>"
                        enctype="multipart/form-data">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="jenis_organisasi" value="<?php echo e($jenisOrganisasi); ?>">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Alamat</label>
                            <textarea name="alamat" class="form-control" rows="2" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Nomor Rekening Organisasi</label>
                            <input type="text" name="nomor_rekening" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Upload KTP Penanggung Jawab</label>
                            <input type="file" name="dokumen_ktp" class="form-control" required>
                        </div>

                        <?php if($jenisOrganisasi == 'yayasan'): ?>
                            <div class="mb-4">
                                <label class="form-label fw-semibold">Upload SK Yayasan (wajib)</label>
                                <input type="file" name="dokumen_legalitas" class="form-control" required>
                            </div>
                        <?php elseif($jenisOrganisasi == 'komunitas'): ?>
                            <div class="mb-4">
                                <label class="form-label fw-semibold">Upload SK Organisasi (opsional)</label>
                                <input type="file" name="dokumen_legalitas" class="form-control">
                            </div>
                        <?php endif; ?>

                        <button type="submit" class="wc-btn-primary w-100">Daftar & Kirim untuk Verifikasi</button>
                    </form>
                </div>
            </div>
        </div>
    </section>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.guest', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Users/grace/Herd/fai/project/MWF-Proyek/resources/views/auth/register-mitra-organisasi.blade.php ENDPATH**/ ?>