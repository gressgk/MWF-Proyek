@extends('layout.penerima')

@section('judul', 'Donasi Masuk')

@section('konten')

    <h5 class="fw-bold mb-4">Donasi Masuk</h5>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>{{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="wc-card p-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr class="text-secondary small text-uppercase">
                        <th>Campaign</th>
                        <th>Dari</th>
                        <th>Detail</th>
                        <th>Metode & Resi</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($donasiMasuk as $donasi)
                        <tr>
                            <td>
                                <span class="fw-semibold">{{ $donasi->Campaign->judul }}</span>
                            </td>
                            <td>{{ $donasi->nama_tampil }}</td>
                            <td>
                                @if ($donasi->jenis == 'uang')
                                    <span class="fw-semibold">Rp{{ number_format($donasi->nominal, 0, ',', '.') }}</span>
                                @else
                                    <span class="fw-semibold">{{ $donasi->jumlah_barang }} {{ $donasi->satuan_barang }} {{ $donasi->nama_barang }}</span>
                                    @if ($donasi->berat_gram)
                                        <br><span class="small text-muted"><i class="bi bi-box-seam me-1"></i>{{ number_format($donasi->berat_gram, 0, ',', '.') }} gram</span>
                                    @endif
                                    @if ($donasi->catatan)
                                        <br><span class="small text-secondary fst-italic">"{{ $donasi->catatan }}"</span>
                                    @endif
                                @endif
                            </td>
                            <td>
                                @if ($donasi->jenis == 'barang')
                                    @if ($donasi->metode_pengiriman === 'diantar_langsung')
                                        <span class="badge text-bg-light border text-dark">
                                            <i class="bi bi-geo-alt me-1"></i>Diantar Langsung
                                        </span>
                                    @else
                                        <span class="badge text-bg-light border text-dark mb-1">
                                            <i class="bi bi-truck me-1"></i>Dikirim Sendiri
                                        </span>
                                        @if ($donasi->Shipment)
                                            <br><span class="small text-secondary">{{ $donasi->Shipment->ekspedisi }} &middot; Resi: <code>{{ $donasi->Shipment->no_resi }}</code></span>
                                            <br><span class="small text-secondary">Status Kurir: <strong>{{ str_replace('_', ' ', ucfirst($donasi->Shipment->status)) }}</strong></span>
                                        @endif
                                    @endif
                                @else
                                    <span class="small text-secondary">-</span>
                                @endif
                            </td>
                            <td>
                                @php
                                    $badgeClass = match ($donasi->status) {
                                        'diterima', 'terverifikasi' => 'success',
                                        'menunggu_konfirmasi', 'menunggu_pembayaran' => 'warning',
                                        default => 'danger',
                                    };
                                @endphp
                                <span class="badge text-bg-{{ $badgeClass }}">
                                    {{ str_replace('_', ' ', ucfirst($donasi->status)) }}
                                </span>
                            </td>
                            <td>
                                @if ($donasi->jenis == 'barang' && $donasi->status == 'menunggu_konfirmasi')
                                    <form action="{{ url('mitra/donasi-masuk/' . $donasi->id . '/konfirmasi') }}"
                                        method="POST"
                                        onsubmit="return confirm('Apakah kamu yakin ingin mengonfirmasi donasi barang ini sudah diterima secara fisik?')">
                                        @csrf
                                        @method('PUT')

                                        <button type="submit" class="btn btn-sm wc-btn-outline">
                                            <i class="bi bi-check-lg me-1"></i>Konfirmasi Diterima
                                        </button>
                                    </form>
                                @elseif ($donasi->status == 'terverifikasi' || $donasi->status == 'diterima')
                                    <span class="small text-success fw-semibold">
                                        <i class="bi bi-check-circle-fill me-1"></i>
                                        Terkonfirmasi
                                        @if ($donasi->tanggal_diterima)
                                            <br><span class="text-secondary fw-normal">({{ $donasi->tanggal_diterima->translatedFormat('d M Y') }})</span>
                                        @endif
                                    </span>
                                @else
                                    <span class="small text-secondary">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-4">Belum ada donasi masuk.</td>
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
