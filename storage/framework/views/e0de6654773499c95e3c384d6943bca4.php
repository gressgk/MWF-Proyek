

<?php $__env->startSection('judul', 'Form Campaign'); ?>

<?php $__env->startSection('konten'); ?>

    <h5 class="fw-bold mb-4"><?php echo e(isset($campaign) ? 'Edit Campaign' : 'Buat Campaign Baru'); ?></h5>

    <?php if($errors->any()): ?>
        <div class="alert alert-danger mb-4">
            <strong>Please check the following fields:</strong>

            <ul class="mb-0 mt-2">
                <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li><?php echo e($error); ?></li>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </ul>
        </div>
    <?php endif; ?>
    <div class="wc-card p-4">
        <form method="POST"
            action="<?php echo e(isset($campaign) ? route('mitra.campaign.update', $campaign->id) : route('mitra.campaign.store')); ?>"
            enctype="multipart/form-data">
            <?php echo csrf_field(); ?>

            <?php if(isset($campaign)): ?>
                <?php echo method_field('PUT'); ?>
            <?php endif; ?>
            <div class="mb-3">
                <label class="form-label fw-semibold">Judul Campaign</label>
                <input type="text" name="judul" class="form-control" value="<?php echo e(old('judul', $campaign->judul ?? '')); ?>"
                    required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Deskripsi</label>
                <textarea name="deskripsi" class="form-control" rows="4" required><?php echo e(old('deskripsi', $campaign->deskripsi ?? '')); ?></textarea>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-semibold">Kategori</label>
                    <select name="category_id" class="form-select" id="kategoriSelect" required>
                        <?php $__currentLoopData = $kategoriList; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kat): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($kat->id); ?>"
                                <?php echo e(old('category_id', $campaign->category_id ?? '') == $kat->id ? 'selected' : ''); ?>>
                                <?php echo e($kat->nama); ?>

                            </option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

                        <option value="lainnya"
                            <?php echo e(old('category_id', $campaign && $campaign->kategori_lainnya ? 'lainnya' : '') === 'lainnya' ? 'selected' : ''); ?>>
                            Lainnya
                        </option>
                    </select>
                    <input type="text" name="kategori_lainnya"
                        class="form-control mt-2 <?php echo e(old('category_id', $campaign && $campaign->kategori_lainnya ? 'lainnya' : '') === 'lainnya' ? '' : 'd-none'); ?>"
                        id="kategoriLainnya" placeholder="Tulis kategori lain"
                        value="<?php echo e(old('kategori_lainnya', $campaign->kategori_lainnya ?? '')); ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-semibold">Tipe Bantuan</label>
                    <select name="tipe_bantuan" class="form-select" onchange="toggleTargetFields(this.value)" required>
                        <option value="uang"
                            <?php echo e(old('tipe_bantuan', $campaign->tipe_bantuan ?? '') === 'uang' ? 'selected' : ''); ?>>
                            Uang
                        </option>
                        <option value="barang"
                            <?php echo e(old('tipe_bantuan', $campaign->tipe_bantuan ?? '') === 'barang' ? 'selected' : ''); ?>>
                            Barang
                        </option>
                        <option value="keduanya"
                            <?php echo e(old('tipe_bantuan', $campaign->tipe_bantuan ?? '') === 'keduanya' ? 'selected' : ''); ?>>
                            Keduanya
                        </option>
                    </select>
                </div>
            </div>

            <?php
                $selectedTipe = old('tipe_bantuan', $campaign->tipe_bantuan ?? 'uang');
            ?>

            <div id="targetNominalField" class="<?php echo e(in_array($selectedTipe, ['uang', 'keduanya']) ? '' : 'd-none'); ?>">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Target Nominal</label>
                    <input type="number" name="target_nominal" class="form-control" placeholder="Rp" min="1"
                        value="<?php echo e(old('target_nominal', $campaign->target_nominal ?? '')); ?>">
                </div>
            </div>

            <div id="targetBarangFields" class="<?php echo e(in_array($selectedTipe, ['barang', 'keduanya']) ? '' : 'd-none'); ?>">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-semibold">Target Barang</label>
                        <input type="number" name="target_barang" class="form-control" placeholder="Jumlah" min="1"
                            value="<?php echo e(old('target_barang', $campaign->target_barang ?? '')); ?>">
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-semibold">Satuan</label>
                        <input type="text" name="satuan_barang" class="form-control" placeholder="pcs / paket / dus"
                            value="<?php echo e(old('satuan_barang', $campaign->satuan_barang ?? '')); ?>">
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-semibold">Tingkat Urgensi</label>
                    <select name="urgensi" class="form-select" required>
                        <option value="mendesak"
                            <?php echo e(old('urgensi', $campaign->urgensi ?? 'biasa') === 'mendesak' ? 'selected' : ''); ?>>
                            Mendesak
                        </option>
                        <option value="biasa"
                            <?php echo e(old('urgensi', $campaign->urgensi ?? 'biasa') === 'biasa' ? 'selected' : ''); ?>>
                            Biasa
                        </option>
                        <option value="terjadwal"
                            <?php echo e(old('urgensi', $campaign->urgensi ?? 'biasa') === 'terjadwal' ? 'selected' : ''); ?>>
                            Terjadwal
                        </option>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-semibold">Tanggal Mulai</label>
                    <input type="date" name="tanggal_mulai" class="form-control"
                        value="<?php echo e(old('tanggal_mulai', $campaign->tanggal_mulai ?? '')); ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-semibold">Tanggal Selesai</label>
                    <input type="date" name="tanggal_selesai" class="form-control"
                        value="<?php echo e(old('tanggal_selesai', $campaign->tanggal_selesai ?? '')); ?>" required>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold">Foto / Dokumentasi Pendukung</label>

                <?php if($campaign && $campaign->foto_sampul): ?>
                    <div class="mb-3">
                        <img src="<?php echo e(asset('storage/' . $campaign->foto_sampul)); ?>" alt="Campaign Cover"
                            class="img-thumbnail" style="max-width: 250px; max-height: 180px; object-fit: cover;">
                    </div>
                <?php endif; ?>

                <input type="file" name="foto_sampul" class="form-control" accept="image/jpeg,image/png,image/webp">

                <small class="text-muted">
                    JPG, JPEG, PNG, or WEBP. Maximum file size: 2 MB.
                    Leave empty to keep the current image.
                </small>
            </div>

            <button type="submit" class="wc-btn-primary">Simpan & Ajukan untuk Disetujui Admin</button>
        </form>
    </div>

    <script>
        document.getElementById('kategoriSelect').addEventListener('change', function() {
            document.getElementById('kategoriLainnya').classList.toggle('d-none', this.value !== 'lainnya');
        });

        function toggleTargetFields(tipe) {
            const targetNominal = document.getElementById('targetNominalField');
            const targetBarang = document.getElementById('targetBarangFields');

            targetNominal.classList.toggle(
                'd-none',
                tipe !== 'uang' && tipe !== 'keduanya'
            );

            targetBarang.classList.toggle(
                'd-none',
                tipe !== 'barang' && tipe !== 'keduanya'
            );
        }
    </script>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.penerima', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views\penerima\campaign-form.blade.php ENDPATH**/ ?>