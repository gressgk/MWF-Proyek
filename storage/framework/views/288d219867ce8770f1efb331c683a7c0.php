

<?php $__env->startSection('judul', 'Chat - ' . $percakapan->Campaign->judul); ?>

<?php $__env->startSection('konten'); ?>

    <a href="<?php echo e(route('donatur.chat')); ?>" class="d-inline-block mb-3"><i class="bi bi-arrow-left"></i> Kembali ke daftar chat</a>

    <div class="wc-card" style="height: 65vh; display: flex; flex-direction: column;">
        <div class="p-3 border-bottom d-flex align-items-center gap-2">
            <i class="bi bi-chat-dots text-warning fs-5"></i>
            <div>
                <p class="fw-semibold mb-0"><?php echo e($percakapan->Campaign->judul); ?></p>
                <p class="small text-secondary mb-0">dengan <?php echo e($percakapan->Campaign->Penerima->name ?? 'Penerima'); ?></p>
            </div>
        </div>

        <div class="p-3 flex-grow-1 overflow-auto d-flex flex-column gap-2">
            <?php $__currentLoopData = $percakapan->Messages; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $pesan): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <?php if($pesan->sender_id == $percakapan->donatur_id): ?>
                    <div class="align-self-end wc-chat-bubble-mine"><?php echo e($pesan->isi); ?></div>
                <?php else: ?>
                    <div class="align-self-start wc-chat-bubble-other"><?php echo e($pesan->isi); ?></div>
                <?php endif; ?>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>

        <form method="POST" action="<?php echo e(route('donatur.chat.show', $percakapan->id)); ?>" class="p-3 border-top d-flex gap-2">
            <?php echo csrf_field(); ?>
            <input type="text" name="isi" class="form-control" placeholder="Tulis pesan...">
            <button type="submit" class="wc-btn-primary"><i class="bi bi-send"></i></button>
        </form>
    </div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.donatur', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views\donatur\chat-detail.blade.php ENDPATH**/ ?>