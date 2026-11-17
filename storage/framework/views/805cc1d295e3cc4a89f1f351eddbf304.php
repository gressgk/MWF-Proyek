

<?php $__env->startSection('judul', 'Dashboard Mitra'); ?>

<?php $__env->startSection('konten'); ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="wc-stat-card">
                <i class="bi bi-megaphone text-warning fs-4"></i>
                <h4 class="fw-bold mt-2 mb-0"><?php echo e($totalCampaignAktif); ?></h4>
                <p class="small text-secondary mb-0">Campaign Aktif</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="wc-stat-card">
                <i class="bi bi-cash-coin text-warning fs-4"></i>
                <h4 class="fw-bold mt-2 mb-0">Rp<?php echo e(number_format($totalDana, 0, ',', '.')); ?></h4>
                <p class="small text-secondary mb-0">Total Dana Terkumpul</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="wc-stat-card">
                <i class="bi bi-box-seam text-warning fs-4"></i>
                <h4 class="fw-bold mt-2 mb-0"><?php echo e($totalBarang); ?></h4>
                <p class="small text-secondary mb-0">Total Barang Masuk</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="wc-stat-card">
                <i class="bi bi-bell text-warning fs-4"></i>
                <h4 class="fw-bold mt-2 mb-0">3</h4>
                <p class="small text-secondary mb-0">Perlu Ditindaklanjuti</p>
            </div>
        </div>
    </div>

    <div class="wc-card p-4">
        <h6 class="fw-bold mb-3">Campaign Terbaru</h6>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr class="text-secondary small text-uppercase">
                        <th>Judul</th>
                        <th>Status</th>
                        <th>Progres</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $campaignTerbaru; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $campaign): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr>
                            <td class="fw-semibold"><?php echo e($campaign->judul); ?></td>
                            <td><span class="wc-badge wc-badge-biasa"><?php echo e(str_replace('_', ' ', ucfirst($campaign->status))); ?></span></td>
                            <td style="width: 160px;">
                                <div class="wc-progress"><div style="width: <?php echo e($campaign->progres_persen); ?>%"></div></div>
                            </td>
                            <td><a href="<?php echo e(route('mitra.campaign.index')); ?>" class="small">Kelola &rarr;</a></td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
    </div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.penerima', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views\penerima\dashboard.blade.php ENDPATH**/ ?>