

<?php $__env->startSection('judul', 'Donasi Masuk'); ?>

<?php $__env->startSection('konten'); ?>

    <h5 class="fw-bold mb-4">Donasi Masuk</h5>

    <div class="wc-card p-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr class="text-secondary small text-uppercase">
                        <th>Campaign</th>
                        <th>Dari</th>
                        <th>Detail</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__empty_1 = true; $__currentLoopData = $donasiMasuk; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $donasi): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr>
                            <td><?php echo e($donasi->Campaign->judul); ?></td>
                            <td><?php echo e($donasi->nama_tampil); ?></td>
                            <td>
                                <?php if($donasi->jenis == 'uang'): ?>
                                    Rp<?php echo e(number_format($donasi->nominal, 0, ',', '.')); ?>

                                <?php else: ?>
                                    <?php echo e($donasi->jumlah_barang); ?> <?php echo e($donasi->satuan_barang); ?> <?php echo e($donasi->nama_barang); ?>

                                    <?php if($donasi->Shipment): ?>
                                        <br><span class="small text-secondary"><?php echo e($donasi->Shipment->ekspedisi); ?> &middot; <?php echo e($donasi->Shipment->no_resi); ?> &middot; <?php echo e(str_replace('_', ' ', ucfirst($donasi->Shipment->status))); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge text-bg-<?php echo e($donasi->status == 'diterima' || $donasi->status == 'terverifikasi' ? 'success' : 'warning'); ?>"><?php echo e(str_replace('_', ' ', ucfirst($donasi->status))); ?></span></td>
                            <td>
                                <?php if($donasi->jenis == 'barang' && $donasi->status != 'diterima'): ?>
                                    <button class="btn btn-sm wc-btn-outline">Konfirmasi Diterima</button>
                                <?php else: ?>
                                    <span class="small text-secondary">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr><td colspan="5" class="text-center text-secondary py-4">Belum ada donasi masuk.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex justify-content-center mt-4">
        <?php echo e($donasiMasuk->links()); ?>

    </div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.penerima', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views\penerima\donasi-masuk.blade.php ENDPATH**/ ?>