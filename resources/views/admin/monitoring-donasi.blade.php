@extends('layout.admin')

@section('judul', 'Monitoring Donasi')

@section('konten')

    <div class="wc-card p-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr class="text-secondary small text-uppercase">
                        <th>Donatur</th>
                        <th>Campaign</th>
                        <th>Jenis</th>
                        <th>Nilai</th>
                        <th>Status</th>
                        <th>Tanggal</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($donasi as $item)
                        <tr>
                            <td>{{ $item->is_anonim ? 'Donatur Anonim' : ($item->Donatur->name ?? '-') }}</td>
                            <td>{{ $item->Campaign->judul ?? '-' }}</td>
                            <td>
                                <span class="wc-badge wc-badge-biasa">{{ $item->jenis == 'uang' ? 'Uang' : 'Barang' }}</span>
                                @if ($item->is_recurring)
                                    <span class="badge text-bg-light border small">Recurring</span>
                                @endif
                            </td>
                            <td>
                                @if ($item->jenis == 'uang')
                                    Rp{{ number_format($item->nominal, 0, ',', '.') }}
                                @else
                                    {{ $item->jumlah_barang }} {{ $item->satuan_barang }} {{ $item->nama_barang }}
                                @endif
                            </td>
                            <td>
                                <span class="badge text-bg-{{ $item->status == 'terverifikasi' || $item->status == 'diterima' ? 'success' : ($item->status == 'gagal' ? 'danger' : 'warning') }}">
                                    {{ ucfirst(str_replace('_', ' ', $item->status)) }}
                                </span>
                            </td>
                            <td class="small text-secondary">{{ $item->created_at->format('d M Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-secondary py-4">Belum ada data donasi.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            {{ $donasi->links() }}
        </div>
    </div>

@endsection
