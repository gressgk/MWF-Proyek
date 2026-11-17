

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

                <form method="POST" action="<?php echo e(route('donatur.donasi.barang', $campaign->slug)); ?>" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nama Barang</label>
                        <input type="text" name="nama_barang" class="form-control" placeholder="Contoh: Selimut, buku tulis, dll" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Kategori Barang</label>
                            <select name="item_category_id" class="form-select" required>
                                <?php $__currentLoopData = $itemKategori; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kat): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <option value="<?php echo e($kat->id); ?>"><?php echo e($kat->nama); ?></option>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </select>
                            <p class="small text-secondary mt-1 mb-0">Donasi barang tidak menerima kategori makanan.</p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Jumlah / Berat Estimasi</label>
                            <div class="input-group">
                                <input type="number" name="jumlah_barang" class="form-control" placeholder="0" required>
                                <span class="input-group-text"><?php echo e($campaign->satuan_barang ?? 'pcs'); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Foto Barang</label>
                        <input type="file" name="foto_barang" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Catatan</label>
                        <textarea name="catatan" class="form-control" rows="2" placeholder="Kondisi barang, informasi tambahan, dll"></textarea>
                    </div>

                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" name="is_anonim" id="anonimBarang">
                        <label class="form-check-label" for="anonimBarang">Donasi sebagai anonim</label>
                    </div>

                    <div class="wc-card p-3 mb-4 bg-light border-0" style="background-color: #FDF8F2 !important;">
                        <p class="small fw-semibold mb-2"><i class="bi bi-truck me-1"></i> Estimasi Ongkir (Komship)</p>
                        <p class="small text-secondary mb-0">Rp25.000 - Rp45.000 tergantung kota asal & ekspedisi yang dipilih.</p>
                    </div>

                    <label class="form-label fw-semibold">Metode Pengiriman</label>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="metode_pengiriman" id="kirimSendiri" value="dikirim_sendiri" checked data-bs-toggle="collapse" data-bs-target="#resiBox">
                            <label class="form-check-label" for="kirimSendiri">Dikirim sendiri (isi ekspedisi & no. resi)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="metode_pengiriman" id="diantarLangsung" value="diantar_langsung">
                            <label class="form-check-label" for="diantarLangsung">Diantar langsung</label>
                        </div>
                    </div>
                    <div class="collapse show row mb-4" id="resiBox">
                        <div class="col-md-6 mb-2">
                            <select name="ekspedisi" class="form-select">
                                <option value="JNE">JNE</option>
                                <option value="J&T Express">J&T Express</option>
                                <option value="SiCepat">SiCepat</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-2">
                            <input type="text" name="no_resi" class="form-control" placeholder="Nomor resi">
                        </div>
                    </div>

                    <button type="submit" class="wc-btn-primary w-100">Kirim Donasi Barang</button>
                </form>
            </div>
        </div>
    </div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.donatur', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views\donatur\donasi-barang.blade.php ENDPATH**/ ?>