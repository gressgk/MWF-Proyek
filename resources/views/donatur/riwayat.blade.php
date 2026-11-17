@extends('layout.donatur')

@section('judul', 'Riwayat Donasi - WeCare')

@section('konten')

    <h4 class="fw-bold mb-4">Riwayat Donasi</h4>

    <div class="wc-card p-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr class="text-secondary small text-uppercase">
                        <th>Campaign</th>
                        <th>Jenis</th>
                        <th>Detail</th>
                        <th>Status</th>
                        <th>Tanggal</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($riwayat as $donasi)
                        <tr>
                            <td>
                                <a href="{{ route('donatur.campaign.show', $donasi->Campaign->slug) }}" class="fw-semibold">{{ $donasi->Campaign->judul }}</a>
                                @if ($donasi->is_recurring)
                                    <br><span class="small text-secondary">Donasi rutin &middot; {{ ucfirst($donasi->frequency) }}</span>
                                @endif
                            </td>
                            <td>{{ $donasi->jenis == 'uang' ? 'Uang' : 'Barang' }}</td>
                            <td>
                                @if ($donasi->jenis == 'uang')
                                    Rp{{ number_format($donasi->nominal, 0, ',', '.') }}
                                @else
                                    {{ $donasi->jumlah_barang }} {{ $donasi->satuan_barang }} {{ $donasi->nama_barang }}
                                    @if ($donasi->Shipment)
                                        <br><span class="small text-secondary">Tracking: {{ str_replace('_', ' ', ucfirst($donasi->Shipment->status)) }}</span>
                                    @endif
                                @endif
                            </td>
                            <td>
                                @php
                                    $warna = match ($donasi->status) {
                                        'terverifikasi', 'diterima' => 'success',
                                        'menunggu_pembayaran' => 'warning',
                                        default => 'danger',
                                    };
                                @endphp
                                <span class="badge text-bg-{{ $warna }}">{{ str_replace('_', ' ', ucfirst($donasi->status)) }}</span>
                            </td>
                            <td class="small text-secondary">{{ $donasi->created_at->translatedFormat('d M Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-secondary py-4">Belum ada riwayat donasi.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex justify-content-center mt-4">
        {{ $riwayat->links() }}
    </div>

@endsection
