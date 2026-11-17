

<?php $__env->startSection('judul', 'Kelola User'); ?>

<?php $__env->startSection('konten'); ?>

    <div class="wc-card p-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr class="text-secondary small text-uppercase">
                        <th>Nama</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Bergabung</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__empty_1 = true; $__currentLoopData = $users; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr>
                            <td><?php echo e($item->name); ?></td>
                            <td><?php echo e($item->email); ?></td>
                            <td><span class="wc-badge wc-badge-biasa"><?php echo e(ucfirst($item->role)); ?></span></td>
                            <td>
                                <?php if($item->role == 'penerima'): ?>
                                    <span class="badge text-bg-<?php echo e($item->status_verifikasi == 'approved' ? 'success' : ($item->status_verifikasi == 'rejected' ? 'danger' : 'warning')); ?>">
                                        <?php echo e(ucfirst($item->status_verifikasi)); ?>

                                    </span>
                                <?php else: ?>
                                    <span class="badge text-bg-success">Aktif</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-secondary"><?php echo e($item->created_at->format('d M Y')); ?></td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-danger">Suspend</button>
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr><td colspan="6" class="text-center text-secondary py-4">Belum ada user.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            <?php echo e($users->links()); ?>

        </div>
    </div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.admin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views\admin\user-index.blade.php ENDPATH**/ ?>