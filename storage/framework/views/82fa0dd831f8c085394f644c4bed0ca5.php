

<?php $__env->startSection('judul', 'Donasi Uang - WeCare'); ?>

<?php $__env->startSection('konten'); ?>

    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="wc-card p-4 p-md-5">
                <div class="d-flex align-items-center gap-3 mb-4">
                    <img src="<?php echo e(asset('img/mascot/duck-gift.png')); ?>" style="height: 60px;" alt="Maskot WeCare">
                    <div>
                        <h5 class="fw-bold mb-0">Donasi Uang</h5>
                        <p class="text-secondary small mb-0">untuk campaign "<?php echo e($campaign->judul); ?>"</p>
                    </div>
                </div>

                <form method="POST" action="<?php echo e(route('donatur.donasi.uang', $campaign->slug)); ?>">
                    <?php echo csrf_field(); ?>
                    <label class="form-label fw-semibold">Pilih Nominal</label>
                    <div class="row g-2 mb-3">
                        <?php $__currentLoopData = [50000, 100000, 250000, 500000, 1000000]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $preset): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <div class="col-4">
                                <input type="radio" class="btn-check" name="nominal_preset" id="nom<?php echo e($preset); ?>" value="<?php echo e($preset); ?>">
                                <label class="btn wc-btn-outline w-100" for="nom<?php echo e($preset); ?>">Rp<?php echo e(number_format($preset, 0, ',', '.')); ?></label>
                            </div>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        <div class="col-4">
                            <input type="radio" class="btn-check" name="nominal_preset" id="nomCustom" value="custom">
                            <label class="btn wc-btn-outline w-100" for="nomCustom">Lainnya</label>
                        </div>
                    </div>
                    <div class="mb-4">
                        <input type="number" name="nominal" class="form-control" placeholder="Masukkan nominal donasi (Rp)" required>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_anonim" id="anonim">
                        <label class="form-check-label" for="anonim">Donasi sebagai anonim</label>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_recurring" id="rutin" data-bs-toggle="collapse" data-bs-target="#frekuensiBox">
                        <label class="form-check-label" for="rutin">Jadikan donasi rutin</label>
                    </div>
                    <div class="collapse mb-4" id="frekuensiBox">
                        <label class="form-label fw-semibold small">Frekuensi donasi rutin</label>
                        <select name="frequency" class="form-select">
                            <option value="mingguan">Mingguan</option>
                            <option value="bulanan">Bulanan</option>
                        </select>
                        <p class="small text-secondary mt-2 mb-0">
                            Kami akan kirim pengingat email H-1 sebelum jatuh tempo, dan konfirmasi setiap transaksi rutin berhasil.
                        </p>
                    </div>

                    <button type="submit" class="wc-btn-primary w-100">Lanjut ke Pembayaran (Midtrans)</button>
                </form>
            </div>
        </div>
    </div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.donatur', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views/donatur/donasi-uang.blade.php ENDPATH**/ ?>