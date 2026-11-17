@extends('layout.admin')

@section('judul', 'Kelola Kategori')

@section('konten')

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="wc-card p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0">Kategori Campaign</h6>
                    <button class="btn btn-sm wc-btn-primary" data-bs-toggle="modal" data-bs-target="#tambahKategoriCampaign">+
                        Tambah</button>
                </div>
                <table class="table align-middle mb-0">
                    <tbody>
                        @foreach ($kategoriCampaign as $kategori)
                            <tr>
                                <td>{{ $kategori->nama }}</td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end gap-2">
                                        <button type="button" class="btn btn-sm btn-light border" data-bs-toggle="modal"
                                            data-bs-target="#editKategoriCampaign" data-id="{{ $kategori->id }}"
                                            data-nama="{{ $kategori->nama }}">
                                            <i class="bi bi-pencil"></i>
                                        </button>

                                        <form method="POST" action="{{ url('admin/kategori/campaign/' . $kategori->id) }}"
                                            onsubmit="return confirm('Yakin ingin menghapus kategori campaign ini?')">
                                            @csrf
                                            @method('DELETE')

                                            <button type="submit" class="btn btn-sm btn-light border text-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
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
                    <button class="btn btn-sm wc-btn-primary" data-bs-toggle="modal"
                        data-bs-target="#tambahKategoriBarang">+ Tambah</button>
                </div>
                <p class="small text-secondary mb-2">Kategori makanan tidak diperbolehkan sebagai donasi barang.</p>
                <table class="table align-middle mb-0">
                    <tbody>
                        @foreach ($kategoriBarang as $kategori)
                            <tr>
                                <td>{{ $kategori->nama }}</td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end gap-2">
                                        <button type="button" class="btn btn-sm btn-light border" data-bs-toggle="modal"
                                            data-bs-target="#editKategoriBarang" data-id="{{ $kategori->id }}"
                                            data-nama="{{ $kategori->nama }}">
                                            <i class="bi bi-pencil"></i>
                                        </button>

                                        <form method="POST" action="{{ url('admin/kategori/barang/' . $kategori->id) }}"
                                            onsubmit="return confirm('Yakin ingin menghapus kategori barang ini?')">
                                            @csrf
                                            @method('DELETE')

                                            <button type="submit" class="btn btn-sm btn-light border text-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
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
                    <form method="POST" action="{{ url('admin/kategori/campaign') }}">
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
                    <form method="POST" action="{{ url('admin/kategori/barang') }}">
                        @csrf
                        <input type="text" name="nama" class="form-control mb-3" placeholder="Nama kategori">
                        <button class="wc-btn-primary w-100">Simpan</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editKategoriCampaign">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-body p-4">
                    <h6 class="fw-bold mb-3">Edit Kategori Campaign</h6>

                    <form method="POST" id="formEditKategoriCampaign">
                        @csrf
                        @method('PUT')

                        <input type="text" name="nama" id="editNamaCampaign" class="form-control mb-3"
                            placeholder="Nama kategori" required>

                        <button type="submit" class="wc-btn-primary w-100">
                            Simpan Perubahan
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editKategoriBarang">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-body p-4">
                    <h6 class="fw-bold mb-3">Edit Kategori Barang</h6>

                    <form method="POST" id="formEditKategoriBarang">
                        @csrf
                        @method('PUT')

                        <input type="text" name="nama" id="editNamaBarang" class="form-control mb-3"
                            placeholder="Nama kategori" required>

                        <button type="submit" class="wc-btn-primary w-100">
                            Simpan Perubahan
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script>
        // Edit Campaign Category
        const editCampaignModal = document.getElementById('editKategoriCampaign');

        editCampaignModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;

            const categoryId = button.getAttribute('data-id');
            const categoryName = button.getAttribute('data-nama');

            document.getElementById('editNamaCampaign').value = categoryName;

            document.getElementById('formEditKategoriCampaign').action =
                `/admin/kategori/campaign/${categoryId}`;
        });

        // Edit Goods Category
        const editBarangModal = document.getElementById('editKategoriBarang');

        editBarangModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;

            const categoryId = button.getAttribute('data-id');
            const categoryName = button.getAttribute('data-nama');

            document.getElementById('editNamaBarang').value = categoryName;

            document.getElementById('formEditKategoriBarang').action =
                `/admin/kategori/barang/${categoryId}`;
        });
    </script>
@endsection
