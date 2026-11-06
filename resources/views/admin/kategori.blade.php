@extends('layout.admin')

@section('judul', 'Kelola Kategori')

@section('konten')

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="wc-card p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0">Kategori Campaign</h6>
                    <button class="btn btn-sm wc-btn-primary" data-bs-toggle="modal" data-bs-target="#tambahKategoriCampaign">+ Tambah</button>
                </div>
                <table class="table align-middle mb-0">
                    <tbody>
                        @foreach ($kategoriCampaign as $kategori)
                            <tr>
                                <td>{{ $kategori->nama }}</td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-light border"><i class="bi bi-pencil"></i></button>
                                    <button class="btn btn-sm btn-light border text-danger"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                        @endforeach
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
                        @foreach ($kategoriBarang as $kategori)
                            <tr>
                                <td>{{ $kategori->nama }}</td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-light border"><i class="bi bi-pencil"></i></button>
                                    <button class="btn btn-sm btn-light border text-danger"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                        @endforeach
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
                    <form method="POST" action="{{ route('admin.kategori') }}">
                        @csrf
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
                    <form method="POST" action="{{ route('admin.kategori') }}">
                        @csrf
                        <input type="text" name="nama" class="form-control mb-3" placeholder="Nama kategori">
                        <button class="wc-btn-primary w-100">Simpan</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

@endsection
