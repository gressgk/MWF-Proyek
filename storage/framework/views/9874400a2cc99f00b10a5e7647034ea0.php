<?php $__env->startSection('judul', 'Donasi Barang - WeCare'); ?>

<?php $__env->startSection('konten'); ?>

    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="wc-card p-4 p-md-5">
                <div class="d-flex align-items-center gap-3 mb-4">
                    <img src="<?php echo e(asset('img/mascot/duck-box.png')); ?>" style="height: 60px;" alt="Maskot WeCare">
                    <div>
                        <h5 class="fw-bold mb-0">Donasi Barang</h5>
                        <p class="text-secondary small mb-0">untuk campaign "<?php echo e($campaign->judul); ?>"</p>
                    </div>
                </div>

                <?php if($errors->any()): ?>
                    <div class="alert alert-danger">
                        <strong>Periksa kesalahan berikut:</strong>
                        <ul class="mb-0 mt-2">
                            <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <li><?php echo e($error); ?></li>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if(session('success')): ?>
                    <div class="alert alert-success">
                        <?php echo e(session('success')); ?>

                    </div>
                <?php endif; ?>

                <form method="POST" action="<?php echo e(route('donatur.donasi.barang', $campaign->slug)); ?>"
                    enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nama Barang</label>
                        <input type="text" name="nama_barang" class="form-control <?php $__errorArgs = ['nama_barang'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> is-invalid <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>"
                            placeholder="Contoh: Selimut hangat, paket buku tulis, dll"
                            value="<?php echo e(old('nama_barang')); ?>" required>
                        <?php $__errorArgs = ['nama_barang'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                            <div class="invalid-feedback"><?php echo e($message); ?></div>
                        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Kategori Barang</label>
                        <?php if($itemKategori->isEmpty()): ?>
                            <div class="alert alert-warning py-2 mb-0 small">
                                Belum ada kategori barang non-makanan yang tersedia saat ini.
                            </div>
                        <?php else: ?>
                            <select name="item_category_id" class="form-select <?php $__errorArgs = ['item_category_id'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> is-invalid <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>" required>
                                <option value="" disabled <?php echo e(old('item_category_id') ? '' : 'selected'); ?>>Pilih Kategori...</option>
                                <?php $__currentLoopData = $itemKategori; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kat): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <option value="<?php echo e($kat->id); ?>" <?php echo e(old('item_category_id') == $kat->id ? 'selected' : ''); ?>>
                                        <?php echo e($kat->nama); ?>

                                    </option>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </select>
                        <?php endif; ?>
                        <p class="small text-secondary mt-1 mb-0">Donasi barang tidak menerima kategori makanan.</p>
                        <?php $__errorArgs = ['item_category_id'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                            <div class="text-danger small mt-1"><?php echo e($message); ?></div>
                        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Jumlah Barang</label>
                            <div class="input-group">
                                <input type="number" name="jumlah_barang" min="1"
                                    class="form-control <?php $__errorArgs = ['jumlah_barang'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> is-invalid <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>"
                                    placeholder="Contoh: 10"
                                    value="<?php echo e(old('jumlah_barang')); ?>" required>
                                <span class="input-group-text"><?php echo e($campaign->satuan_barang ?? 'pcs'); ?></span>
                            </div>
                            <?php $__errorArgs = ['jumlah_barang'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                <div class="text-danger small mt-1"><?php echo e($message); ?></div>
                            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Estimasi Berat Total (gram)</label>
                            <div class="input-group">
                                <input type="number" name="berat_gram" min="1" max="1000000"
                                    class="form-control <?php $__errorArgs = ['berat_gram'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> is-invalid <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>"
                                    placeholder="Contoh: 1500"
                                    value="<?php echo e(old('berat_gram')); ?>" required>
                                <span class="input-group-text">gram</span>
                            </div>
                            <span class="form-text small text-secondary">1 kg = 1.000 gram</span>
                            <?php $__errorArgs = ['berat_gram'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                <div class="text-danger small mt-1"><?php echo e($message); ?></div>
                            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Foto Barang</label>
                        <input type="file" name="foto_barang" accept="image/*"
                            class="form-control <?php $__errorArgs = ['foto_barang'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> is-invalid <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>" required>
                        <span class="form-text small text-secondary">Format: JPG, JPEG, PNG, WEBP (Maksimal 2MB)</span>
                        <?php $__errorArgs = ['foto_barang'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                            <div class="invalid-feedback"><?php echo e($message); ?></div>
                        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Catatan (Opsional)</label>
                        <textarea name="catatan" class="form-control <?php $__errorArgs = ['catatan'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> is-invalid <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>" rows="2"
                            placeholder="Kondisi barang, petunjuk penanganan, dll"><?php echo e(old('catatan')); ?></textarea>
                        <?php $__errorArgs = ['catatan'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                            <div class="invalid-feedback"><?php echo e($message); ?></div>
                        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                    </div>

                    <div class="form-check mb-4">
                        <input type="checkbox" name="is_anonim" id="anonimBarang" value="1" class="form-check-input"
                            <?php echo e(old('is_anonim') ? 'checked' : ''); ?>>
                        <label class="form-check-label" for="anonimBarang">Donasi sebagai anonim</label>
                    </div>

                    
                    <div class="wc-card p-3 mb-4 bg-light border-0" style="background-color: #FDF8F2 !important;">
                        <p class="small fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i> Informasi Pengiriman</p>
                        <p class="small text-secondary mb-0">Estimasi ongkir otomatis belum tersedia. Pengiriman dan ongkos kirim diatur sendiri oleh Donatur.</p>
                    </div>

                    <label class="form-label fw-semibold">Metode Pengiriman</label>
                    <div class="mb-3">
                        <?php
                            $metodeTerpilih = old('metode_pengiriman', 'dikirim_sendiri');
                        ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="metode_pengiriman" id="kirimSendiri"
                                value="dikirim_sendiri" <?php echo e($metodeTerpilih === 'dikirim_sendiri' ? 'checked' : ''); ?>

                                onchange="togglePengiriman(this.value)">
                            <label class="form-check-label" for="kirimSendiri">
                                Dikirim sendiri (isi ekspedisi & no. resi)
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="metode_pengiriman" id="diantarLangsung"
                                value="diantar_langsung" <?php echo e($metodeTerpilih === 'diantar_langsung' ? 'checked' : ''); ?>

                                onchange="togglePengiriman(this.value)">
                            <label class="form-check-label" for="diantarLangsung">
                                Diantar langsung ke lokasi penerima
                            </label>
                        </div>
                    </div>

                    <div class="row mb-4 <?php echo e($metodeTerpilih === 'dikirim_sendiri' ? '' : 'd-none'); ?>" id="resiBox">
                        <div class="col-md-6 mb-2">
                            <label class="form-label small fw-semibold">Pilih Ekspedisi</label>
                            <select name="ekspedisi" id="inputEkspedisi" class="form-select <?php $__errorArgs = ['ekspedisi'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> is-invalid <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>">
                                <option value="JNE" <?php echo e(old('ekspedisi', 'JNE') === 'JNE' ? 'selected' : ''); ?>>JNE</option>
                                <option value="J&T Express" <?php echo e(old('ekspedisi') === 'J&T Express' ? 'selected' : ''); ?>>J&T Express</option>
                                <option value="SiCepat" <?php echo e(old('ekspedisi') === 'SiCepat' ? 'selected' : ''); ?>>SiCepat</option>
                            </select>
                            <?php $__errorArgs = ['ekspedisi'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                <div class="invalid-feedback"><?php echo e($message); ?></div>
                            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label small fw-semibold">Nomor Resi</label>
                            <input type="text" name="no_resi" id="inputNoResi"
                                class="form-control <?php $__errorArgs = ['no_resi'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> is-invalid <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>"
                                placeholder="Masukkan nomor resi pengiriman"
                                value="<?php echo e(old('no_resi')); ?>">
                            <?php $__errorArgs = ['no_resi'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                <div class="invalid-feedback"><?php echo e($message); ?></div>
                            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                        </div>
                    </div>

                    <button type="submit" class="wc-btn-primary w-100">Kirim Donasi Barang</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function togglePengiriman(metode) {
            const resiBox = document.getElementById('resiBox');
            const inputNoResi = document.getElementById('inputNoResi');
            const inputEkspedisi = document.getElementById('inputEkspedisi');

            if (metode === 'dikirim_sendiri') {
                resiBox.classList.remove('d-none');
            } else {
                resiBox.classList.add('d-none');
            }
        }
    </script>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.donatur', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Nosh\Documents\GitHub\MWF-Proyek\resources\views/donatur/donasi-barang.blade.php ENDPATH**/ ?>