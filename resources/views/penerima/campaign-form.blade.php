@extends('layout.penerima')

@section('judul', 'Form Campaign')

@section('konten')

    <h5 class="fw-bold mb-4">{{ isset($campaign) ? 'Edit Campaign' : 'Buat Campaign Baru' }}</h5>

    <div class="wc-card p-4">
        <form method="POST" action="{{ route('mitra.campaign.store') }}" enctype="multipart/form-data">
            @csrf
            <div class="mb-3">
                <label class="form-label fw-semibold">Judul Campaign</label>
                <input type="text" name="judul" class="form-control" value="{{ $campaign->judul ?? '' }}" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Deskripsi</label>
                <textarea name="deskripsi" class="form-control" rows="4" required>{{ $campaign->deskripsi ?? '' }}</textarea>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-semibold">Kategori</label>
                    <select name="category_id" class="form-select" id="kategoriSelect">
                        @foreach ($kategoriList as $kat)
                            <option value="{{ $kat->id }}">{{ $kat->nama }}</option>
                        @endforeach
                        <option value="lainnya">Lainnya</option>
                    </select>
                    <input type="text" name="kategori_lainnya" class="form-control mt-2 d-none" id="kategoriLainnya" placeholder="Tulis kategori lain">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-semibold">Tipe Bantuan</label>
                    <select name="tipe_bantuan" class="form-select">
                        <option value="uang">Uang</option>
                        <option value="barang">Barang</option>
                        <option value="keduanya">Keduanya</option>
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-semibold">Target Nominal (jika uang)</label>
                    <input type="number" name="target_nominal" class="form-control" placeholder="Rp">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-semibold">Target Barang (jika barang)</label>
                    <input type="number" name="target_barang" class="form-control" placeholder="Jumlah">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-semibold">Satuan</label>
                    <input type="text" name="satuan_barang" class="form-control" placeholder="pcs / paket / dus">
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-semibold">Tingkat Urgensi</label>
                    <select name="urgensi" class="form-select">
                        <option value="mendesak">Mendesak</option>
                        <option value="biasa" selected>Biasa</option>
                        <option value="terjadwal">Terjadwal</option>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-semibold">Tanggal Mulai</label>
                    <input type="date" name="tanggal_mulai" class="form-control" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-semibold">Tanggal Selesai</label>
                    <input type="date" name="tanggal_selesai" class="form-control" required>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold">Foto / Dokumentasi Pendukung</label>
                <input type="file" name="foto_sampul" class="form-control">
            </div>

            <button type="submit" class="wc-btn-primary">Simpan & Ajukan untuk Disetujui Admin</button>
        </form>
    </div>

    <script>
        document.getElementById('kategoriSelect').addEventListener('change', function () {
            document.getElementById('kategoriLainnya').classList.toggle('d-none', this.value !== 'lainnya');
        });
    </script>

@endsection
