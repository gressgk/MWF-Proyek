@extends('layout.penerima')

@section('judul', 'Form Campaign')

@section('konten')

    <h5 class="fw-bold mb-4">{{ isset($campaign) ? 'Edit Campaign' : 'Buat Campaign Baru' }}</h5>

    @if ($errors->any())
        <div class="alert alert-danger mb-4">
            <strong>Please check the following fields:</strong>

            <ul class="mb-0 mt-2">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    <div class="wc-card p-4">
        <form method="POST"
            action="{{ isset($campaign) ? route('mitra.campaign.update', $campaign->id) : route('mitra.campaign.store') }}"
            enctype="multipart/form-data">
            @csrf

            @if (isset($campaign))
                @method('PUT')
            @endif
            <div class="mb-3">
                <label class="form-label fw-semibold">Judul Campaign</label>
                <input type="text" name="judul" class="form-control" value="{{ old('judul', $campaign->judul ?? '') }}"
                    required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Deskripsi</label>
                <textarea name="deskripsi" class="form-control" rows="4" required>{{ old('deskripsi', $campaign->deskripsi ?? '') }}</textarea>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-semibold">Kategori</label>
                    <select name="category_id" class="form-select" id="kategoriSelect" required>
                        @foreach ($kategoriList as $kat)
                            <option value="{{ $kat->id }}"
                                {{ old('category_id', $campaign->category_id ?? '') == $kat->id ? 'selected' : '' }}>
                                {{ $kat->nama }}
                            </option>
                        @endforeach

                        <option value="lainnya"
                            {{ old('category_id', $campaign && $campaign->kategori_lainnya ? 'lainnya' : '') === 'lainnya' ? 'selected' : '' }}>
                            Lainnya
                        </option>
                    </select>
                    <input type="text" name="kategori_lainnya"
                        class="form-control mt-2 {{ old('category_id', $campaign && $campaign->kategori_lainnya ? 'lainnya' : '') === 'lainnya' ? '' : 'd-none' }}"
                        id="kategoriLainnya" placeholder="Tulis kategori lain"
                        value="{{ old('kategori_lainnya', $campaign->kategori_lainnya ?? '') }}">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-semibold">Tipe Bantuan</label>
                    <select name="tipe_bantuan" class="form-select" onchange="toggleTargetFields(this.value)" required>
                        <option value="uang"
                            {{ old('tipe_bantuan', $campaign->tipe_bantuan ?? '') === 'uang' ? 'selected' : '' }}>
                            Uang
                        </option>
                        <option value="barang"
                            {{ old('tipe_bantuan', $campaign->tipe_bantuan ?? '') === 'barang' ? 'selected' : '' }}>
                            Barang
                        </option>
                        <option value="keduanya"
                            {{ old('tipe_bantuan', $campaign->tipe_bantuan ?? '') === 'keduanya' ? 'selected' : '' }}>
                            Keduanya
                        </option>
                    </select>
                </div>
            </div>

            @php
                $selectedTipe = old('tipe_bantuan', $campaign->tipe_bantuan ?? 'uang');
            @endphp

            <div id="targetNominalField" class="{{ in_array($selectedTipe, ['uang', 'keduanya']) ? '' : 'd-none' }}">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Target Nominal</label>
                    <input type="number" name="target_nominal" class="form-control" placeholder="Rp" min="1"
                        value="{{ old('target_nominal', $campaign->target_nominal ?? '') }}">
                </div>
            </div>

            <div id="targetBarangFields" class="{{ in_array($selectedTipe, ['barang', 'keduanya']) ? '' : 'd-none' }}">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-semibold">Target Barang</label>
                        <input type="number" name="target_barang" class="form-control" placeholder="Jumlah" min="1"
                            value="{{ old('target_barang', $campaign->target_barang ?? '') }}">
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-semibold">Satuan</label>
                        <input type="text" name="satuan_barang" class="form-control" placeholder="pcs / paket / dus"
                            value="{{ old('satuan_barang', $campaign->satuan_barang ?? '') }}">
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-semibold">Tingkat Urgensi</label>
                    <select name="urgensi" class="form-select" required>
                        <option value="mendesak"
                            {{ old('urgensi', $campaign->urgensi ?? 'biasa') === 'mendesak' ? 'selected' : '' }}>
                            Mendesak
                        </option>
                        <option value="biasa"
                            {{ old('urgensi', $campaign->urgensi ?? 'biasa') === 'biasa' ? 'selected' : '' }}>
                            Biasa
                        </option>
                        <option value="terjadwal"
                            {{ old('urgensi', $campaign->urgensi ?? 'biasa') === 'terjadwal' ? 'selected' : '' }}>
                            Terjadwal
                        </option>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-semibold">Tanggal Mulai</label>
                    <input type="date" name="tanggal_mulai" class="form-control"
                        value="{{ old('tanggal_mulai', $campaign->tanggal_mulai ?? '') }}" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-semibold">Tanggal Selesai</label>
                    <input type="date" name="tanggal_selesai" class="form-control"
                        value="{{ old('tanggal_selesai', $campaign->tanggal_selesai ?? '') }}" required>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold">Foto / Dokumentasi Pendukung</label>

                @if ($campaign && $campaign->foto_sampul)
                    <div class="mb-3">
                        <img src="{{ asset('storage/' . $campaign->foto_sampul) }}" alt="Campaign Cover"
                            class="img-thumbnail" style="max-width: 250px; max-height: 180px; object-fit: cover;">
                    </div>
                @endif

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

@endsection
