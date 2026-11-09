

<?php $__env->startSection('judul', 'Verifikasi Mitra'); ?>

<?php $__env->startSection('konten'); ?>

    <h5 class="fw-bold mb-3">Menunggu Verifikasi</h5>
    <div class="row g-3 mb-4">
        <?php $__empty_1 = true; $__currentLoopData = $mitraPending; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $mitra): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <div class="col-md-6">
                <div class="wc-card p-3">
                    <div class="d-flex justify-content-between">
                        <div>
                            <p class="fw-bold mb-0"><?php echo e($mitra->PenerimaProfile->nama_organisasi ?? '-'); ?></p>
                            <p class="small text-secondary mb-2">PIC: <?php echo e($mitra->name); ?> &middot; <?php echo e($mitra->email); ?></p>
                        </div>
                        <span class="badge text-bg-warning align-self-start">Pending</span>
                    </div>
                    <p class="small mb-1"><i class="bi bi-geo-alt me-1"></i><?php echo e($mitra->PenerimaProfile->alamat ?? '-'); ?></p>
                    <p class="small mb-3">
                        <i class="bi bi-file-earmark-text me-1"></i>
                        <a
                            href="<?php echo e(route('admin.verifikasi-mitra.dokumen', ['path' => $mitra->PenerimaProfile->dokumen_ktp ?? ''])); ?>">Lihat
                            dokumen KTP</a>
                        <?php if($mitra->PenerimaProfile->dokumen_legalitas ?? false): ?>
                            &middot; <a
                                href="<?php echo e(route('admin.verifikasi-mitra.dokumen', ['path' => $mitra->PenerimaProfile->dokumen_legalitas])); ?>">Lihat
                                dokumen legalitas</a>
                        <?php endif; ?>
                    </p>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm wc-btn-primary">Approve</button>
                        <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                            data-bs-target="#reject<?php echo e($mitra->id); ?>">Reject</button>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="reject<?php echo e($mitra->id); ?>">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-body p-4">
                            <h6 class="fw-bold mb-3">Alasan Penolakan</h6>
                            <textarea class="form-control mb-3" rows="3" placeholder="Jelaskan alasan penolakan, akan dikirim via email"></textarea>
                            <button class="btn btn-danger w-100">Kirim Penolakan</button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <p class="text-secondary small">Tidak ada mitra yang menunggu verifikasi.</p>
        <?php endif; ?>
    </div>

    <h5 class="fw-bold mb-3">Riwayat Mitra Lain</h5>
    <div class="wc-card p-3">
        <table class="table align-middle mb-0">
            <thead>
                <tr class="text-secondary small text-uppercase">
                    <th>Organisasi</th>
                    <th>PIC</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $__empty_1 = true; $__currentLoopData = $mitraLain; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $mitra): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <tr>
                        <td><?php echo e($mitra->PenerimaProfile->nama_organisasi ?? '-'); ?></td>
                        <td><?php echo e($mitra->name); ?></td>
                        <td>
                            <span
                                class="badge text-bg-<?php echo e($mitra->status_verifikasi == 'approved' ? 'success' : 'danger'); ?>"><?php echo e(ucfirst($mitra->status_verifikasi)); ?></span>
                        </td>
                    </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <tr>
                        <td colspan="3" class="text-center text-secondary py-3">Belum ada data.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.admin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views/admin/verifikasi-mitra.blade.php ENDPATH**/ ?>