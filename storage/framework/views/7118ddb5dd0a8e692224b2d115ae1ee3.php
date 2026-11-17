

<?php $__env->startSection('judul', 'Monitoring Donasi'); ?>

<?php $__env->startSection('konten'); ?>

    <div class="wc-card p-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr class="text-secondary small text-uppercase">
                        <th>Donatur</th>
                        <th>Campaign</th>
                        <th>Jenis</th>
                        <th>Nilai</th>
                        <th>Status</th>
                        <th>Tanggal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__empty_1 = true; $__currentLoopData = $donasi; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr>
                            <td><?php echo e($item->is_anonim ? 'Donatur Anonim' : ($item->Donatur->name ?? '-')); ?></td>
                            <td><?php echo e($item->Campaign->judul ?? '-'); ?></td>
                            <td>
                                <span class="wc-badge wc-badge-biasa"><?php echo e($item->jenis == 'uang' ? 'Uang' : 'Barang'); ?></span>
                                <?php if($item->is_recurring): ?>
                                    <span class="badge text-bg-light border small">Recurring</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($item->jenis == 'uang'): ?>
                                    Rp<?php echo e(number_format($item->nominal, 0, ',', '.')); ?>

                                <?php else: ?>
                                    <?php echo e($item->jumlah_barang); ?> <?php echo e($item->satuan_barang); ?> <?php echo e($item->nama_barang); ?>

                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge text-bg-<?php echo e($item->status == 'terverifikasi' || $item->status == 'diterima' ? 'success' : ($item->status == 'gagal' ? 'danger' : 'warning')); ?>">
                                    <?php echo e(ucfirst(str_replace('_', ' ', $item->status))); ?>

                                </span>
                            </td>
                            <td class="small text-secondary"><?php echo e($item->created_at->format('d M Y')); ?></td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr><td colspan="6" class="text-center text-secondary py-4">Belum ada data donasi.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            <?php echo e($donasi->links()); ?>

        </div>
    </div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.admin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views\admin\monitoring-donasi.blade.php ENDPATH**/ ?>