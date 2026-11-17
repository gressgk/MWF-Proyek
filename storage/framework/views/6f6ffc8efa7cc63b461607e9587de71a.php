

<?php $__env->startSection('judul', 'Kelola Kategori'); ?>

<?php $__env->startSection('konten'); ?>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="wc-card p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0">Kategori Campaign</h6>
                    <button class="btn btn-sm wc-btn-primary" data-bs-toggle="modal" data-bs-target="#tambahKategoriCampaign">+ Tambah</button>
                </div>
                <table class="table align-middle mb-0">
                    <tbody>
                        <?php $__currentLoopData = $kategoriCampaign; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kategori): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr>
                                <td><?php echo e($kategori->nama); ?></td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-light border"><i class="bi bi-pencil"></i></button>
                                    <button class="btn btn-sm btn-light border text-danger"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="wc-card p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0">Kategori Barang</h6>
                    <button class="btn btn-sm wc-btn-primary" data-bs-toggle="modal" data-bs-target="#tambahKategoriBarang">+ Tambah</button>
                </div>
                <p class="small text-secondary mb-2">Kategori makanan tidak diperbolehkan sebagai donasi barang.</p>
                <table class="table align-middle mb-0">
                    <tbody>
                        <?php $__currentLoopData = $kategoriBarang; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kategori): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr>
                                <td><?php echo e($kategori->nama); ?></td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-light border"><i class="bi bi-pencil"></i></button>
                                    <button class="btn btn-sm btn-light border text-danger"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="tambahKategoriCampaign">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-body p-4">
                    <h6 class="fw-bold mb-3">Tambah Kategori Campaign</h6>
                    <form method="POST" action="<?php echo e(route('admin.kategori')); ?>">
                        <?php echo csrf_field(); ?>
                        <input type="text" name="nama" class="form-control mb-3" placeholder="Nama kategori">
                        <button class="wc-btn-primary w-100">Simpan</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="tambahKategoriBarang">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-body p-4">
                    <h6 class="fw-bold mb-3">Tambah Kategori Barang</h6>
                    <form method="POST" action="<?php echo e(route('admin.kategori')); ?>">
                        <?php echo csrf_field(); ?>
                        <input type="text" name="nama" class="form-control mb-3" placeholder="Nama kategori">
                        <button class="wc-btn-primary w-100">Simpan</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.admin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views\admin\kategori.blade.php ENDPATH**/ ?>