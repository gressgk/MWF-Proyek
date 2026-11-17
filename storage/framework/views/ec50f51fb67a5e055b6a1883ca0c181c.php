

<?php $__env->startSection('judul', 'Chat - WeCare'); ?>

<?php $__env->startSection('konten'); ?>

    <h4 class="fw-bold mb-4">Chat</h4>

    <div class="wc-card">
        <?php $__empty_1 = true; $__currentLoopData = $percakapan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $chat): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <a href="<?php echo e(route('donatur.chat.show', $chat->id)); ?>" class="d-flex align-items-center gap-3 p-3 border-bottom text-dark">
                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                    <i class="bi bi-chat-dots text-warning fs-5"></i>
                </div>
                <div class="flex-grow-1">
                    <p class="fw-semibold mb-0"><?php echo e($chat->Campaign->judul); ?></p>
                    <p class="small text-secondary mb-0"><?php echo e($chat->PesanTerakhir->isi ?? 'Belum ada pesan'); ?></p>
                </div>
                <small class="text-secondary"><?php echo e($chat->updated_at->diffForHumans()); ?></small>
            </a>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <div class="text-center text-secondary py-5">
                Belum ada percakapan. Mulai chat dari halaman detail campaign.
            </div>
        <?php endif; ?>
    </div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.donatur', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views\donatur\chat-index.blade.php ENDPATH**/ ?>