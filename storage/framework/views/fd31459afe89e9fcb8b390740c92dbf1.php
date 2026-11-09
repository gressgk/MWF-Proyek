

<?php $__env->startSection('judul', 'Approve Campaign'); ?>

<?php $__env->startSection('konten'); ?>

    <h5 class="fw-bold mb-4">Campaign Menunggu Persetujuan</h5>

    <div class="row g-3">
        <?php $__empty_1 = true; $__currentLoopData = $campaignPending; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $campaign): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <div class="col-md-6">
                <div class="wc-card p-3">
                    <div class="d-flex gap-2 mb-2">
                        <span class="wc-badge wc-badge-<?php echo e($campaign->urgensi); ?>"><?php echo e(ucfirst($campaign->urgensi)); ?></span>
                        <span class="wc-badge wc-badge-biasa"><?php echo e($campaign->Category->nama ?? '-'); ?></span>
                    </div>
                    <p class="fw-bold mb-1"><?php echo e($campaign->judul); ?></p>
                    <p class="small text-secondary mb-3">oleh <?php echo e($campaign->Penerima->name ?? '-'); ?></p>
                    <p class="small mb-3"><?php echo e(\Illuminate\Support\Str::limit($campaign->deskripsi, 120)); ?></p>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm wc-btn-primary">Approve</button>
                        <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectC<?php echo e($campaign->id); ?>">Reject</button>
                        <a href="#" class="btn btn-sm btn-light border">Preview</a>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="rejectC<?php echo e($campaign->id); ?>">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-body p-4">
                            <h6 class="fw-bold mb-3">Alasan Penolakan Campaign</h6>
                            <textarea class="form-control mb-3" rows="3"></textarea>
                            <button class="btn btn-danger w-100">Tolak Campaign</button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <p class="text-secondary small">Tidak ada campaign yang menunggu persetujuan saat ini.</p>
        <?php endif; ?>
    </div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.admin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views\admin\approve-campaign.blade.php ENDPATH**/ ?>