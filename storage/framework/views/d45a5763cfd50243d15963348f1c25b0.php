<?php $__env->startSection('judul', 'Donasi Masuk'); ?>

<?php $__env->startSection('konten'); ?>

    <h5 class="fw-bold mb-4">Donasi Masuk</h5>

    <?php if(session('success')): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo e(session('success')); ?>

            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="wc-card p-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr class="text-secondary small text-uppercase">
                        <th>Campaign</th>
                        <th>Dari</th>
                        <th>Detail</th>
                        <th>Metode & Resi</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__empty_1 = true; $__currentLoopData = $donasiMasuk; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $donasi): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr>
                            <td>
                                <span class="fw-semibold"><?php echo e($donasi->Campaign->judul); ?></span>
                            </td>
                            <td><?php echo e($donasi->nama_tampil); ?></td>
                            <td>
                                <?php if($donasi->jenis == 'uang'): ?>
                                    <span class="fw-semibold">Rp<?php echo e(number_format($donasi->nominal, 0, ',', '.')); ?></span>
                                <?php else: ?>
                                    <span class="fw-semibold"><?php echo e($donasi->jumlah_barang); ?> <?php echo e($donasi->satuan_barang); ?> <?php echo e($donasi->nama_barang); ?></span>
                                    <?php if($donasi->berat_gram): ?>
                                        <br><span class="small text-muted"><i class="bi bi-box-seam me-1"></i><?php echo e(number_format($donasi->berat_gram, 0, ',', '.')); ?> gram</span>
                                    <?php endif; ?>
                                    <?php if($donasi->catatan): ?>
                                        <br><span class="small text-secondary fst-italic">"<?php echo e($donasi->catatan); ?>"</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($donasi->jenis == 'barang'): ?>
                                    <?php if($donasi->metode_pengiriman === 'diantar_langsung'): ?>
                                        <span class="badge text-bg-light border text-dark">
                                            <i class="bi bi-geo-alt me-1"></i>Diantar Langsung
                                        </span>
                                    <?php else: ?>
                                        <span class="badge text-bg-light border text-dark mb-1">
                                            <i class="bi bi-truck me-1"></i>Dikirim Sendiri
                                        </span>
                                        <?php if($donasi->Shipment): ?>
                                            <br><span class="small text-secondary"><?php echo e($donasi->Shipment->ekspedisi); ?> &middot; Resi: <code><?php echo e($donasi->Shipment->no_resi); ?></code></span>
                                            <br><span class="small text-secondary">Status Kurir: <strong><?php echo e(str_replace('_', ' ', ucfirst($donasi->Shipment->status))); ?></strong></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="small text-secondary">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                    $badgeClass = match ($donasi->status) {
                                        'diterima', 'terverifikasi' => 'success',
                                        'menunggu_konfirmasi', 'menunggu_pembayaran' => 'warning',
                                        default => 'danger',
                                    };
                                ?>
                                <span class="badge text-bg-<?php echo e($badgeClass); ?>">
                                    <?php echo e(str_replace('_', ' ', ucfirst($donasi->status))); ?>

                                </span>
                            </td>
                            <td>
                                <?php if($donasi->jenis == 'barang' && $donasi->status == 'menunggu_konfirmasi'): ?>
                                    <form action="<?php echo e(url('mitra/donasi-masuk/' . $donasi->id . '/konfirmasi')); ?>"
                                        method="POST"
                                        onsubmit="return confirm('Apakah kamu yakin ingin mengonfirmasi donasi barang ini sudah diterima secara fisik?')">
                                        <?php echo csrf_field(); ?>
                                        <?php echo method_field('PUT'); ?>

                                        <button type="submit" class="btn btn-sm wc-btn-outline">
                                            <i class="bi bi-check-lg me-1"></i>Konfirmasi Diterima
                                        </button>
                                    </form>
                                <?php elseif($donasi->status == 'terverifikasi' || $donasi->status == 'diterima'): ?>
                                    <span class="small text-success fw-semibold">
                                        <i class="bi bi-check-circle-fill me-1"></i>
                                        Terkonfirmasi
                                        <?php if($donasi->tanggal_diterima): ?>
                                            <br><span class="text-secondary fw-normal">(<?php echo e($donasi->tanggal_diterima->translatedFormat('d M Y')); ?>)</span>
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="small text-secondary">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-4">Belum ada donasi masuk.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex justify-content-center mt-4">
        <?php echo e($donasiMasuk->links()); ?>

    </div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.penerima', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Nosh\Documents\GitHub\MWF-Proyek\resources\views/penerima/donasi-masuk.blade.php ENDPATH**/ ?>