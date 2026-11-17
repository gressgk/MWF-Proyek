

<?php $__env->startSection('judul', 'Campaign Saya'); ?>

<?php $__env->startSection('konten'); ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="fw-bold mb-0">Campaign Saya</h5>
        <a href="<?php echo e(route('mitra.campaign.create')); ?>" class="wc-btn-primary"><i class="bi bi-plus-lg me-1"></i>Buat
            Campaign</a>
    </div>

    <div class="wc-card p-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr class="text-secondary small text-uppercase">
                        <th>Judul</th>
                        <th>Tipe Bantuan</th>
                        <th>Urgensi</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__empty_1 = true; $__currentLoopData = $campaigns; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $campaign): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr>
                            <td class="fw-semibold"><?php echo e($campaign->judul); ?></td>
                            <td class="text-capitalize"><?php echo e($campaign->tipe_bantuan); ?></td>
                            <td><span
                                    class="wc-badge wc-badge-<?php echo e($campaign->urgensi); ?>"><?php echo e(ucfirst($campaign->urgensi)); ?></span>
                            </td>
                            <td>
                                <?php
                                    $warna = match ($campaign->status) {
                                        'aktif' => 'success',
                                        'pending_approval', 'draft' => 'warning',
                                        'selesai' => 'secondary',
                                        default => 'danger',
                                    };
                                ?>
                                <span class="badge text-bg-<?php echo e($warna); ?>">
                                    <?php echo e(str_replace('_', ' ', ucfirst($campaign->status))); ?>

                                </span>

                                <?php if($campaign->status === 'ditolak' && $campaign->alasan_ditolak): ?>
                                    <div class="small text-danger mt-2">
                                        <strong>Alasan penolakan:</strong>
                                        <?php echo e($campaign->alasan_ditolak); ?>

                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo e(route('mitra.campaign.edit', $campaign->id)); ?>" class="small">
                                    Edit
                                </a>

                                &middot;

                                <form action="<?php echo e(route('mitra.campaign.destroy', $campaign->id)); ?>" method="POST"
                                    class="d-inline"
                                    onsubmit="return confirm('Are you sure you want to delete this campaign?')">
                                    <?php echo csrf_field(); ?>
                                    <?php echo method_field('DELETE'); ?>

                                    <button type="submit" class="btn btn-link p-0 small text-danger text-decoration-none">
                                        Hapus
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr>
                            <td colspan="5" class="text-center text-secondary py-4">Belum ada campaign. Yuk buat campaign
                                pertamamu.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex justify-content-center mt-4">
        <?php echo e($campaigns->links()); ?>

    </div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.penerima', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views\penerima\campaign-index.blade.php ENDPATH**/ ?>