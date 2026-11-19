@extends('layout.admin')

@section('judul', 'Monitoring Donasi')

@section('konten')

    <form method="GET" action="{{ route('admin.monitoring-donasi') }}" class="wc-card p-3 mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label for="cari" class="form-label small">Cari campaign, nama donatur, atau order ID</label>
                <input id="cari" class="form-control" type="search" name="cari" maxlength="100" value="{{ $filters['cari'] ?? '' }}">
            </div>
            <div class="col-md-3">
                <label for="jenis" class="form-label small">Jenis donasi</label>
                <select id="jenis" name="jenis" class="form-select">
                    <option value="">Semua</option>
                    <option value="uang" @selected(($filters['jenis'] ?? '') === 'uang')>Uang</option>
                    <option value="barang" @selected(($filters['jenis'] ?? '') === 'barang')>Barang</option>
                </select>
            </div>
            <div class="col-md-3">
                <label for="status" class="form-label small">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">Semua</option>
                    @foreach (['menunggu_pembayaran', 'menunggu_konfirmasi', 'terverifikasi', 'diterima', 'gagal'] as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-warning w-100 fw-semibold" type="submit">Filter</button>
            </div>
        </div>
        <div class="mt-2"><a href="{{ route('admin.monitoring-donasi') }}" class="small">Reset filter</a></div>
    </form>

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
                        <th>Tanggal / Order</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($donasi as $item)
                        <tr>
                            <td>
                                {{ $item->Donatur->name ?? '-' }}
                                @if ($item->is_anonim)
                                    <span class="small text-secondary d-block">Anonim untuk publik</span>
                                @endif
                            </td>
                            <td>{{ $item->Campaign->judul ?? '-' }}</td>
                            <td>
                                <span class="wc-badge wc-badge-biasa">{{ $item->jenis === 'uang' ? 'Uang' : 'Barang' }}</span>
                                @if ($item->is_recurring)
                                    <span class="badge text-bg-light border small">Rutin</span>
                                @endif
                            </td>
                            <td>
                                @if ($item->jenis === 'uang')
                                    Rp{{ number_format((float) $item->nominal, 0, ',', '.') }}
                                @else
                                    {{ $item->jumlah_barang }} {{ $item->satuan_barang }} — {{ $item->nama_barang }}
                                @endif
                            </td>
                            <td>
                                <span class="badge text-bg-{{ in_array($item->status, ['terverifikasi', 'diterima']) ? 'success' : ($item->status === 'gagal' ? 'danger' : 'warning') }}">
                                    {{ ucfirst(str_replace('_', ' ', $item->status)) }}
                                </span>
                                @if ($item->jenis === 'uang' && $item->payment_status_detail)
                                    <span class="small text-secondary d-block">{{ $item->payment_status_detail }}</span>
                                @endif
                            </td>
                            <td class="small text-secondary">
                                {{ $item->created_at?->format('d M Y H:i') ?? '-' }}
                                @if ($item->jenis === 'uang' && $item->order_id)
                                    <span class="small d-block">Order: {{ $item->order_id }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-secondary py-4">Belum ada data donasi yang sesuai filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $donasi->links() }}</div>
    </div>

@endsection
