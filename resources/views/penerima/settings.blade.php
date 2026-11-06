@extends('layout.penerima')

@section('judul', 'Settings')

@section('konten')

    <h5 class="fw-bold mb-4">Settings</h5>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="wc-card p-4 mb-4">
                <h6 class="fw-bold mb-3">Profil Organisasi</h6>
                <form method="POST" action="{{ route('mitra.settings') }}" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Nama Organisasi</label>
                        <input type="text" name="nama_organisasi" class="form-control" value="{{ $profil->nama_organisasi ?? '' }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Alamat</label>
                        <textarea name="alamat" class="form-control" rows="2">{{ $profil->alamat ?? '' }}</textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Rekening Bank untuk Pencairan Dana</label>
                        <input type="text" name="nomor_rekening" class="form-control" value="{{ $profil->nomor_rekening ?? '' }}">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold small">Update Dokumen Legalitas</label>
                        <input type="file" name="dokumen_legalitas" class="form-control">
                        <p class="small text-secondary mt-1 mb-0">Mengganti dokumen akan memicu verifikasi ulang oleh Admin.</p>
                    </div>
                    <button type="submit" class="wc-btn-primary">Simpan Perubahan</button>
                </form>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="wc-card p-4">
                <h6 class="fw-bold mb-3">Ganti Password</h6>
                <form>
                    <div class="mb-3"><input type="password" class="form-control" placeholder="Password lama"></div>
                    <div class="mb-3"><input type="password" class="form-control" placeholder="Password baru"></div>
                    <div class="mb-4"><input type="password" class="form-control" placeholder="Konfirmasi password baru"></div>
                    <button type="submit" class="wc-btn-outline w-100">Ganti Password</button>
                </form>
            </div>
        </div>
    </div>

@endsection
