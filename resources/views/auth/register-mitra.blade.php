@extends('layout.guest')

@section('judul', 'Daftar Mitra - WeCare')

@section('konten')
    <section class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-7">
                <div class="wc-card p-4 p-md-5">
                    <div class="text-center mb-4">
                        <img src="{{ asset('img/mascot/duck-box.png') }}" style="height: 70px;" alt="Maskot WeCare">
                        <h4 class="fw-bold mt-3">Daftar Jadi Mitra WeCare</h4>
                        <p class="text-secondary small">
                            Dua langkah mudah sebelum akun diverifikasi Admin
                        </p>
                    </div>

                    @if (session('pesan'))
                        <div class="alert alert-info small">
                            {{ session('pesan') }}
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="alert alert-danger small">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <ul class="nav nav-pills justify-content-center gap-2 mb-4">
                        <li class="nav-item">
                            <span class="nav-link {{ $step == 1 ? 'active' : '' }}">
                                1. Data Akun
                            </span>
                        </li>
                        <li class="nav-item">
                            <span class="nav-link {{ $step == 2 ? 'active' : '' }}">
                                2. Data Organisasi
                            </span>
                        </li>
                    </ul>

                    {{-- Step 1 - Data Akun --}}
                    @if ($step == 1)
                        <form method="POST" action="{{ route('register.mitra.akun') }}">
                            @csrf

                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    Nama Penanggung Jawab
                                </label>
                                <input type="text" name="nama_pj" class="form-control"
                                    value="{{ old('nama_pj', $dataAkun['nama_pj'] ?? '') }}" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Email</label>
                                <input type="email" name="email" class="form-control"
                                    value="{{ old('email', $dataAkun['email'] ?? '') }}" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">No. HP</label>
                                <input type="text" name="phone" class="form-control"
                                    value="{{ old('phone', $dataAkun['phone'] ?? '') }}" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Password</label>
                                <input type="password" name="password" class="form-control" minlength="8" required>
                            </div>

                            <button type="submit" class="wc-btn-primary w-100">
                                Lanjut ke Data Organisasi
                            </button>
                        </form>

                        {{-- Step 2 - Data Organisasi --}}
                    @else
                        <form method="POST" action="{{ route('register.mitra.store') }}" enctype="multipart/form-data">
                            @csrf

                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    Nama Organisasi / Yayasan
                                </label>
                                <input type="text" name="nama_organisasi" class="form-control"
                                    value="{{ old('nama_organisasi') }}" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Jenis Organisasi</label>
                                <select name="jenis_organisasi" id="jenisOrganisasi" class="form-select" required>
                                    <option value="yayasan" @selected(old('jenis_organisasi') == 'yayasan')>
                                        Yayasan
                                    </option>
                                    <option value="individu" @selected(old('jenis_organisasi') == 'individu')>
                                        Individu
                                    </option>
                                    <option value="komunitas" @selected(old('jenis_organisasi') == 'komunitas')>
                                        Komunitas
                                    </option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Alamat</label>
                                <textarea name="alamat" class="form-control" rows="2" required>{{ old('alamat') }}</textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    Nomor Rekening Organisasi
                                </label>
                                <input type="text" name="nomor_rekening" class="form-control"
                                    value="{{ old('nomor_rekening') }}" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    Upload KTP Penanggung Jawab
                                </label>
                                <input type="file" name="dokumen_ktp" class="form-control" accept=".pdf,.jpg,.jpeg,.png"
                                    required>
                            </div>

                            <div class="mb-4" id="wrapDokumenLegalitas">
                                <label class="form-label fw-semibold">
                                    Upload Dokumen Legalitas (Opsional)
                                </label>
                                <input type="file" name="dokumen_legalitas" id="inputDokumenLegalitas"
                                    class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                            </div>

                            <a href="{{ route('register.mitra') }}" class="wc-btn-outline d-block text-center mb-3">
                                Kembali ke Data Akun
                            </a>

                            <button type="submit" class="wc-btn-primary w-100">
                                Daftar & Kirim untuk Verifikasi
                            </button>
                        </form>
                    @endif

                    <p class="text-center small mt-4 mb-0">
                        Sudah punya akun?
                        <a href="{{ route('login') }}">Masuk di sini</a>
                    </p>
                </div>
            </div>
        </div>
    </section>

    @if ($step == 2)
        <script>
            const jenisOrganisasi = document.getElementById('jenisOrganisasi');
            const wrapDokumenLegalitas = document.getElementById('wrapDokumenLegalitas');
            const inputDokumenLegalitas = document.getElementById('inputDokumenLegalitas');

            function toggleDokumenLegalitas() {
                if (jenisOrganisasi.value == 'individu') {
                    wrapDokumenLegalitas.style.display = 'none';
                    inputDokumenLegalitas.disabled = true;
                    inputDokumenLegalitas.value = '';
                } else {
                    wrapDokumenLegalitas.style.display = 'block';
                    inputDokumenLegalitas.disabled = false;
                }
            }

            jenisOrganisasi.addEventListener('change', toggleDokumenLegalitas);
            toggleDokumenLegalitas();
        </script>
    @endif
@endsection
