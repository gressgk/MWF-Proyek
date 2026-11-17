<div class="col-md-6 col-lg-4">
    <div class="wc-card h-100 overflow-hidden">
        <div style="height: 150px; background: linear-gradient(135deg, #FBDCD3, #FDEFD9);" class="d-flex align-items-center justify-content-center">
            <i class="bi bi-heart-fill text-white" style="font-size: 2.5rem;"></i>
        </div>
        <div class="p-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="wc-badge wc-badge-<?php echo e($campaign->urgensi); ?>"><?php echo e(ucfirst($campaign->urgensi)); ?></span>
                <small class="text-secondary"><?php echo e($campaign->Category->nama ?? $campaign->kategori_lainnya); ?></small>
            </div>
            <h6 class="fw-bold mb-1"><?php echo e($campaign->judul); ?></h6>
            <p class="small text-secondary mb-2">oleh <?php echo e($campaign->Penerima->name ?? '-'); ?></p>

            <?php if($campaign->tipe_bantuan != 'barang'): ?>
                <div class="wc-progress mb-1"><div style="width: <?php echo e($campaign->progres_persen); ?>%"></div></div>
                <p class="small mb-2">
                    <strong>Rp<?php echo e(number_format($campaign->terkumpul_nominal, 0, ',', '.')); ?></strong>
                    terkumpul dari Rp<?php echo e(number_format($campaign->target_nominal, 0, ',', '.')); ?>

                </p>
            <?php else: ?>
                <div class="wc-progress mb-1"><div style="width: <?php echo e($campaign->progres_persen); ?>%"></div></div>
                <p class="small mb-2">
                    <strong><?php echo e($campaign->terkumpul_barang); ?></strong> / <?php echo e($campaign->target_barang); ?> <?php echo e($campaign->satuan_barang); ?>

                </p>
            <?php endif; ?>

            <a href="<?php echo e(route('donatur.campaign.show', $campaign->slug)); ?>" class="wc-btn-primary w-100 text-center d-block mt-2">Lihat Detail</a>
        </div>
    </div>
</div>
<?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views\partials\campaign-card.blade.php ENDPATH**/ ?>