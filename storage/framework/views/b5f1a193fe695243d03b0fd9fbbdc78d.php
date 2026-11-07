<?php $__env->startSection('judul', 'Riwayat Donasi - WeCare'); ?>

<?php $__env->startSection('konten'); ?>

    <h4 class="fw-bold mb-4">Riwayat Donasi</h4>

    <div class="wc-card p-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr class="text-secondary small text-uppercase">
                        <th>Campaign</th>
                        <th>Jenis</th>
                        <th>Detail</th>
                        <th>Status</th>
                        <th>Tanggal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__empty_1 = true; $__currentLoopData = $riwayat; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $donasi): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr>
                            <td>
                                <a href="<?php echo e(route('donatur.campaign.show', $donasi->Campaign->slug)); ?>" class="fw-semibold"><?php echo e($donasi->Campaign->judul); ?></a>
                                <?php if($donasi->is_recurring): ?>
                                    <br><span class="small text-secondary">Donasi rutin &middot; <?php echo e(ucfirst($donasi->frequency)); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo e($donasi->jenis == 'uang' ? 'Uang' : 'Barang'); ?></td>
                            <td>
                                <?php if($donasi->jenis == 'uang'): ?>
                                    Rp<?php echo e(number_format($donasi->nominal, 0, ',', '.')); ?>

                                <?php else: ?>
                                    <?php echo e($donasi->jumlah_barang); ?> <?php echo e($donasi->satuan_barang); ?> <?php echo e($donasi->nama_barang); ?>

                                    <?php if($donasi->Shipment): ?>
                                        <br><span class="small text-secondary">Tracking: <?php echo e(str_replace('_', ' ', ucfirst($donasi->Shipment->status))); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                    $warna = match ($donasi->status) {
                                        'terverifikasi', 'diterima' => 'success',
                                        'menunggu_pembayaran' => 'warning',
                                        default => 'danger',
                                    };
                                ?>
                                <span class="badge text-bg-<?php echo e($warna); ?>"><?php echo e(str_replace('_', ' ', ucfirst($donasi->status))); ?></span>
                            </td>
                            <td class="small text-secondary"><?php echo e($donasi->created_at->translatedFormat('d M Y')); ?></td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr><td colspan="5" class="text-center text-secondary py-4">Belum ada riwayat donasi.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex justify-content-center mt-4">
        <?php echo e($riwayat->links()); ?>

    </div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.donatur', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Users/grace/Herd/fai/project/MWF-Proyek/resources/views/donatur/riwayat.blade.php ENDPATH**/ ?>