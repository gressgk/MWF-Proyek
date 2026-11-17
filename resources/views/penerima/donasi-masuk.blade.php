@extends('layout.penerima')

@section('judul', 'Donasi Masuk')

@section('konten')

    <h5 class="fw-bold mb-4">Donasi Masuk</h5>

    <div class="wc-card p-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr class="text-secondary small text-uppercase">
                        <th>Campaign</th>
                        <th>Dari</th>
                        <th>Detail</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($donasiMasuk as $donasi)
                        <tr>
                            <td>{{ $donasi->Campaign->judul }}</td>
                            <td>{{ $donasi->nama_tampil }}</td>
                            <td>
                                @if ($donasi->jenis == 'uang')
                                    Rp{{ number_format($donasi->nominal, 0, ',', '.') }}
                                @else
                                    {{ $donasi->jumlah_barang }} {{ $donasi->satuan_barang }} {{ $donasi->nama_barang }}
                                    @if ($donasi->Shipment)
                                        <br><span class="small text-secondary">{{ $donasi->Shipment->ekspedisi }} &middot;
                                            {{ $donasi->Shipment->no_resi }} &middot;
                                            {{ str_replace('_', ' ', ucfirst($donasi->Shipment->status)) }}</span>
                                    @endif
                                @endif
                            </td>
                            <td><span
                                    class="badge text-bg-{{ $donasi->status == 'diterima' || $donasi->status == 'terverifikasi' ? 'success' : 'warning' }}">{{ str_replace('_', ' ', ucfirst($donasi->status)) }}</span>
                            </td>
                            <td>
                                @if ($donasi->jenis == 'barang' && $donasi->status == 'menunggu_konfirmasi')
                                    <form action="{{ url('mitra/donasi-masuk/' . $donasi->id . '/konfirmasi') }}"
                                        method="POST"
                                        onsubmit="return confirm('Apakah kamu yakin ingin mengonfirmasi donasi barang ini?')">
                                        @csrf
                                        @method('PUT')

                                        <button type="submit" class="btn btn-sm wc-btn-outline">
                                            Konfirmasi Diterima
                                        </button>
                                    </form>
                                @elseif ($donasi->status == 'terverifikasi' || $donasi->status == 'diterima')
                                    <span class="small text-success fw-semibold">
                                        <i class="bi bi-check-circle-fill me-1"></i>
                                        Terkonfirmasi
                                    </span>
                                @else
                                    <span class="small text-secondary">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-secondary py-4">Belum ada donasi masuk.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex justify-content-center mt-4">
        {{ $donasiMasuk->links() }}
    </div>

@endsection
