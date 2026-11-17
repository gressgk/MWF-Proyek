

<?php $__env->startSection('judul', 'Daftar Mitra - WeCare'); ?>

<?php $__env->startSection('konten'); ?>
    <section class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-7">
                <div class="wc-card p-4 p-md-5">
                    <div class="text-center mb-4">
                        <img src="<?php echo e(asset('img/mascot/duck-box.png')); ?>" style="height: 70px;" alt="Maskot WeCare">
                        <h4 class="fw-bold mt-3">Daftar Jadi Mitra WeCare</h4>
                        <p class="text-secondary small">Dua langkah mudah sebelum akun diverifikasi Admin</p>
                    </div>

                    <?php if(session('pesan')): ?>
                        <div class="alert alert-info small">
                            <?php echo e(session('pesan')); ?>

                        </div>
                    <?php endif; ?>

                    <ul class="nav nav-pills justify-content-center gap-2 mb-4" id="stepTab">
                        <li class="nav-item">
                            <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#step1" type="button">1.
                                Data Akun</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="pill" data-bs-target="#step2" type="button">2. Data
                                Organisasi</button>
                        </li>
                    </ul>

                    <form method="POST" action="<?php echo e(route('register.mitra.store')); ?>" enctype="multipart/form-data">
                        <?php echo csrf_field(); ?>
                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="step1">
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Nama Penanggung Jawab</label>
                                    <input type="text" name="nama_pj" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Email</label>
                                    <input type="email" name="email" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">No. HP</label>
                                    <input type="text" name="phone" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Password</label>
                                    <input type="password" name="password" class="form-control" required>
                                </div>
                                <button type="button" class="wc-btn-primary w-100"
                                    onclick="document.querySelector('[data-bs-target=\'#step2\']').click()">Lanjut ke Data
                                    Organisasi</button>
                            </div>

                            <div class="tab-pane fade" id="step2">
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Nama Organisasi / Yayasan</label>
                                    <input type="text" name="nama_organisasi" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Jenis Organisasi</label>
                                    <select name="jenis_organisasi" id="jenisOrganisasi" class="form-select" required>
                                        <option value="yayasan">Yayasan</option>
                                        <option value="individu">Individu</option>
                                        <option value="komunitas">Komunitas</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Alamat</label>
                                    <textarea name="alamat" class="form-control" rows="2" required></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Nomor Rekening Organisasi</label>
                                    <input type="text" name="nomor_rekening" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Upload KTP Penanggung Jawab</label>
                                    <input type="file" name="dokumen_ktp" class="form-control" required>
                                </div>
                                <div class="mb-4" id="wrapDokumenLegalitas">
                                    <label class="form-label fw-semibold" id="labelDokumenLegalitas">Upload SK
                                        Yayasan</label>
                                    <input type="file" name="dokumen_legalitas" id="inputDokumenLegalitas"
                                        class="form-control" required>
                                </div>
                                <button type="submit" class="wc-btn-primary w-100">Daftar & Kirim untuk Verifikasi</button>
                            </div>
                        </div>
                    </form>

                    <p class="text-center small mt-4 mb-0">
                        Sudah punya akun? <a href="<?php echo e(route('login')); ?>">Masuk di sini</a>
                    </p>
                </div>
            </div>
        </div>
    </section>

    <script>
        const jenisOrganisasi = document.getElementById('jenisOrganisasi');
        const wrapDokumenLegalitas = document.getElementById('wrapDokumenLegalitas');
        const labelDokumenLegalitas = document.getElementById('labelDokumenLegalitas');
        const inputDokumenLegalitas = document.getElementById('inputDokumenLegalitas');

        function toggleDokumenLegalitas() {
            if (jenisOrganisasi.value == 'individu') {
                wrapDokumenLegalitas.style.display = 'none';
                inputDokumenLegalitas.required = false;
            } else if (jenisOrganisasi.value == 'yayasan') {
                wrapDokumenLegalitas.style.display = 'block';
                labelDokumenLegalitas.innerText = 'Upload SK Yayasan (wajib)';
                inputDokumenLegalitas.required = true;
            } else {
                wrapDokumenLegalitas.style.display = 'block';
                labelDokumenLegalitas.innerText = 'Upload SK Organisasi (opsional)';
                inputDokumenLegalitas.required = false;
            }
        }

        jenisOrganisasi.addEventListener('change', toggleDokumenLegalitas);
        toggleDokumenLegalitas();
    </script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout.guest', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views\auth\register-mitra.blade.php ENDPATH**/ ?>