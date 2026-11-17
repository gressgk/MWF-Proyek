

<?php $__env->startSection('judul', $campaign->judul . ' - WeCare'); ?>

<?php $__env->startSection('konten'); ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="wc-card overflow-hidden mb-4">
                <div style="height: 260px; background: linear-gradient(135deg, #FBDCD3, #FDEFD9);" class="d-flex align-items-center justify-content-center">
                    <i class="bi bi-heart-fill text-white" style="font-size: 4rem;"></i>
                </div>
                <div class="p-4">
                    <div class="d-flex gap-2 mb-3">
                        <span class="wc-badge wc-badge-<?php echo e($campaign->urgensi); ?>"><?php echo e(ucfirst($campaign->urgensi)); ?></span>
                        <span class="wc-badge wc-badge-biasa"><?php echo e($campaign->Category->nama ?? $campaign->kategori_lainnya); ?></span>
                    </div>
                    <h3 class="fw-bold mb-2"><?php echo e($campaign->judul); ?></h3>
                    <p class="text-secondary mb-4">
                        oleh <strong><?php echo e($campaign->Penerima->PenerimaProfile->nama_organisasi ?? $campaign->Penerima->name); ?></strong>
                        &middot; berlangsung sampai <?php echo e(\Illuminate\Support\Carbon::parse($campaign->tanggal_selesai)->translatedFormat('d F Y')); ?>

                    </p>
                    <p style="white-space: pre-line;"><?php echo e($campaign->deskripsi); ?></p>
                </div>
            </div>

            <div class="wc-card p-4 mb-4">
                <h5 class="fw-bold mb-3"><i class="bi bi-file-earmark-text me-2"></i>Update Laporan Penggunaan</h5>
                <?php $__empty_1 = true; $__currentLoopData = $campaign->Reports; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $laporan): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <div class="border-bottom pb-3 mb-3">
                        <p class="fw-semibold mb-1"><?php echo e($laporan->judul); ?></p>
                        <p class="small text-secondary mb-0"><?php echo e($laporan->isi); ?></p>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <p class="text-secondary small mb-0">Belum ada update laporan dari penerima.</p>
                <?php endif; ?>
            </div>

            <div class="wc-card p-4">
                <h5 class="fw-bold mb-3"><i class="bi bi-people me-2"></i>Donatur Terbaru</h5>
                <?php $__empty_1 = true; $__currentLoopData = $campaign->Donations->take(6); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $donasi): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                        <span><?php echo e($donasi->nama_tampil); ?></span>
                        <span class="small text-secondary">
                            <?php if($donasi->jenis == 'uang'): ?>
                                Rp<?php echo e(number_format($donasi->nominal, 0, ',', '.')); ?>

                            <?php else: ?>
                                <?php echo e($donasi->jumlah_barang); ?> <?php echo e($donasi->satuan_barang); ?> <?php echo e($donasi->nama_barang); ?>

                            <?php endif; ?>
                        </span>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <p class="text-secondary small mb-0">Jadilah donatur pertama di campaign ini.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="wc-card p-4 mb-3" style="position: sticky; top: 90px;">
                <?php if($campaign->tipe_bantuan != 'barang'): ?>
                    <div class="wc-progress mb-2"><div style="width: <?php echo e($campaign->progres_persen); ?>%"></div></div>
                    <p class="mb-1"><strong>Rp<?php echo e(number_format($campaign->terkumpul_nominal, 0, ',', '.')); ?></strong></p>
                    <p class="small text-secondary mb-4">terkumpul dari target Rp<?php echo e(number_format($campaign->target_nominal, 0, ',', '.')); ?></p>
                <?php else: ?>
                    <div class="wc-progress mb-2"><div style="width: <?php echo e($campaign->progres_persen); ?>%"></div></div>
                    <p class="mb-1"><strong><?php echo e($campaign->terkumpul_barang); ?> <?php echo e($campaign->satuan_barang); ?></strong></p>
                    <p class="small text-secondary mb-4">terkumpul dari target <?php echo e($campaign->target_barang); ?> <?php echo e($campaign->satuan_barang); ?></p>
                <?php endif; ?>

                <div class="d-grid gap-2">
                    <?php if(in_array($campaign->tipe_bantuan, ['uang', 'keduanya'])): ?>
                        <a href="<?php echo e(route('donatur.donasi.uang', $campaign->slug)); ?>" class="wc-btn-primary">
                            <i class="bi bi-cash-coin me-1"></i> Donasi Uang
                        </a>
                    <?php endif; ?>
                    <?php if(in_array($campaign->tipe_bantuan, ['barang', 'keduanya'])): ?>
                        <a href="<?php echo e(route('donatur.donasi.barang', $campaign->slug)); ?>" class="wc-btn-outline">
                            <i class="bi bi-box-seam me-1"></i> Donasi Barang
                        </a>
                    <?php endif; ?>
                    <a href="<?php echo e(route('donatur.chat')); ?>" class="btn btn-light border">
                        <i class="bi bi-chat-dots me-1"></i> Chat dengan Penerima
                    </a>
                    <a href="<?php echo e(route('donatur.campaign.laporan-pdf', $campaign->slug)); ?>" class="btn btn-light border">
                        <i class="bi bi-file-earmark-pdf me-1"></i> Export Laporan PDF
                    </a>
                </div>
            </div>

            <div class="wc-card p-4 text-center">
                <p class="small fw-semibold mb-2">QR Code Campaign</p>
                <div class="border rounded p-3 d-inline-block bg-white">
                    <i class="bi bi-qr-code" style="font-size: 4rem;"></i>
                </div>
                <p class="small text-secondary mt-2 mb-0">Scan untuk share campaign ini</p>
            </div>
        </div>
    </div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.donatur', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views\donatur\campaign-detail.blade.php ENDPATH**/ ?>