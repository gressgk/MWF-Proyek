<?php $__env->startSection('judul', 'Detail Laporan'); ?>

<?php $__env->startSection('konten'); ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="fw-bold mb-0">Detail Laporan</h5>

        <a href="<?php echo e(route('mitra.laporan', ['campaign_id' => $report->campaign_id])); ?>"
            class="wc-btn-secondary">
            <i class="bi bi-arrow-left me-1"></i> Kembali
        </a>
    </div>

    <div class="wc-card p-4 mb-4">
        <h5 class="fw-bold mb-2"><?php echo e($report->judul); ?></h5>

        <p class="text-muted small mb-3">
            <i class="bi bi-megaphone me-1"></i>
            <?php echo e($report->Campaign->judul ?? 'Campaign'); ?>

            <span class="mx-2">•</span>
            <i class="bi bi-calendar me-1"></i>
            <?php echo e($report->created_at->format('d M Y')); ?>

        </p>

        <hr>

        <h6 class="fw-bold">Description</h6>
        <p class="mb-4" style="white-space: pre-line;"><?php echo e($report->isi); ?></p>

        <h6 class="fw-bold">Amount Spent</h6>
        <p class="mb-0">
            Rp <?php echo e(number_format($report->nominal_terpakai ?? 0, 0, ',', '.')); ?>

        </p>
    </div>

    
    <?php if($report->Photos->isNotEmpty() || $report->foto_bukti): ?>
        <div class="wc-card p-4 mb-4">
            <h6 class="fw-bold mb-3">Supporting Photos</h6>

            <div class="row g-3">
                <?php $__currentLoopData = $report->Photos; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $photo): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <div class="col-md-4 col-sm-6">
                        <a href="<?php echo e(asset('storage/' . $photo->foto_path)); ?>" target="_blank">
                            <img
                                src="<?php echo e(asset('storage/' . $photo->foto_path)); ?>"
                                alt="Supporting photo"
                                class="img-fluid rounded"
                                style="width: 100%; height: 220px; object-fit: cover;"
                            >
                        </a>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

                
                <?php if($report->foto_bukti): ?>
                    <div class="col-md-4 col-sm-6">
                        <a href="<?php echo e(asset('storage/' . $report->foto_bukti)); ?>" target="_blank">
                            <img
                                src="<?php echo e(asset('storage/' . $report->foto_bukti)); ?>"
                                alt="Supporting photo"
                                class="img-fluid rounded"
                                style="width: 100%; height: 220px; object-fit: cover;"
                            >
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    
    <?php if($report->dokumen_bukti): ?>
        <div class="wc-card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0">Supporting Document</h6>

                <a href="<?php echo e(asset('storage/' . $report->dokumen_bukti)); ?>"
                    class="wc-btn-primary"
                    target="_blank"
                    download>
                    <i class="bi bi-download me-1"></i> Download PDF
                </a>
            </div>

            <iframe
                src="<?php echo e(asset('storage/' . $report->dokumen_bukti)); ?>"
                width="100%"
                height="600"
                style="border: 1px solid #dee2e6; border-radius: 8px;"
                title="Report supporting document">
            </iframe>
        </div>
    <?php endif; ?>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.penerima', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views/penerima/laporan-detail.blade.php ENDPATH**/ ?>