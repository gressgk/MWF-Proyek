    

    <?php $__env->startSection('judul', 'Laporan Penggunaan'); ?>

    <?php $__env->startSection('konten'); ?>

        <?php if(session('success')): ?>
            <div class="alert alert-success">
                <?php echo e(session('success')); ?>

            </div>
        <?php endif; ?>

        <?php if($errors->any()): ?>
            <div class="alert alert-danger">
                <strong>Please check the following fields:</strong>
                <ul class="mb-0 mt-2">
                    <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </ul>
            </div>
        <?php endif; ?>

        <h5 class="fw-bold mb-4">Laporan Penggunaan &mdash; <?php echo e($campaign->judul ?? ''); ?></h5>

        <?php if($campaigns->isNotEmpty()): ?>
            <div class="wc-card p-4 w-100">
                <form method="GET" action="<?php echo e(route('mitra.laporan')); ?>">
                    <div class="row align-items-end g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Pilih Campaign</label>
                            <select name="campaign_id" class="form-select">
                                <?php $__currentLoopData = $campaigns; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <option value="<?php echo e($item->id); ?>"
                                        <?php echo e($campaign && $campaign->id == $item->id ? 'selected' : ''); ?>>
                                        <?php echo e($item->judul); ?>

                                    </option>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="wc-btn-primary w-100">
                                Lihat Laporan
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>
        <div class="row g-4" style="margin-top: 0.3rem;">
            <div class="col-lg-5">
                <?php if($campaign): ?>
                    <div class="wc-card p-4 w-100">
                        <h6 class="fw-bold mb-3">Tambah Update Laporan</h6>

                        <form method="POST" action="<?php echo e(route('mitra.laporan')); ?>" enctype="multipart/form-data">
                            <?php echo csrf_field(); ?>

                            <input type="hidden" name="campaign_id" value="<?php echo e($campaign->id); ?>">

                            <div class="mb-3">
                                <label class="form-label">Judul Update</label>
                                <input type="text" name="judul" class="form-control" value="<?php echo e(old('judul')); ?>"
                                    required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Isi Laporan</label>
                                <textarea name="isi" class="form-control" rows="4" required><?php echo e(old('isi')); ?></textarea>
                            </div>

                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Dokumen Bukti (PDF)</label>
                                <input type="file" name="dokumen_bukti" class="form-control"
                                    accept=".pdf,application/pdf">
                                <small class="text-muted">
                                    Upload invoices, receipts, or distribution reports. Maximum size: 10 MB.
                                </small>
                            </div>

                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Foto Bukti</label>
                                <input type="file" name="foto_bukti[]" class="form-control"
                                    accept=".jpg,.jpeg,.png,.webp" multiple>
                                <small class="text-muted">
                                    You can upload multiple photos. JPG, PNG, or WEBP. Maximum size: 2 MB per photo.
                                </small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Nominal Terpakai (Opsional)</label>
                                <input type="number" name="nominal_terpakai" class="form-control" placeholder="Rp"
                                    min="0" step="0.01" value="<?php echo e(old('nominal_terpakai')); ?>">
                            </div>

                            <button type="submit" class="wc-btn-primary w-100">
                                Publikasikan Update
                            </button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="wc-card p-4">
                        <h6 class="fw-bold mb-2">Belum Ada Campaign</h6>
                        <p class="small text-secondary mb-3">
                            You need to create a campaign before publishing a usage report.
                        </p>
                        <a href="<?php echo e(route('mitra.campaign.create')); ?>" class="wc-btn-primary">
                            Buat Campaign
                        </a>
                    </div>
                <?php endif; ?>
            </div>


            <div class="col-lg-7">
                <div class="wc-card p-4">
                    <h6 class="fw-bold mb-3">Riwayat Update</h6>

                    <?php $__empty_1 = true; $__currentLoopData = $laporan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $update): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <a href="<?php echo e(route('mitra.laporan.show', $update->id)); ?>" class="text-decoration-none text-dark">

                            <div class="border-bottom pb-3 mb-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <p class="fw-semibold mb-1">
                                        <?php echo e($update->judul); ?>

                                    </p>

                                    <i class="bi bi-chevron-right text-muted"></i>
                                </div>

                                <p class="small text-muted mb-2">
                                    <i class="bi bi-calendar me-1"></i>
                                    <?php echo e($update->created_at->format('d M Y, H:i')); ?>

                                </p>

                                <p class="small text-secondary mb-1">
                                    <?php echo e(\Illuminate\Support\Str::limit($update->isi, 120)); ?>

                                </p>

                                <?php if($update->dokumen_bukti || $update->Photos->isNotEmpty() || $update->foto_bukti): ?>
                                    <span class="small text-primary">
                                        <i class="bi bi-paperclip me-1"></i>
                                        Supporting attachments available
                                    </span>
                                <?php endif; ?>

                                <?php if($update->nominal_terpakai): ?>
                                    <p class="small mb-0 mt-2">
                                        Amount spent:
                                        Rp<?php echo e(number_format($update->nominal_terpakai, 0, ',', '.')); ?>

                                    </p>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <p class="small text-secondary mb-0">
                            Belum ada update laporan untuk campaign ini.
                        </p>
                    <?php endif; ?>
                </div>
            </div>

        </div>

    <?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.penerima', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views/penerima/laporan.blade.php ENDPATH**/ ?>