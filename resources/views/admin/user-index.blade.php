@extends('layout.admin')

@section('judul', 'Kelola User')

@section('konten')

    <div class="wc-card p-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr class="text-secondary small text-uppercase">
                        <th>Nama</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Bergabung</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $item)
                        <tr>
                            <td>{{ $item->name }}</td>
                            <td>{{ $item->email }}</td>
                            <td><span class="wc-badge wc-badge-biasa">{{ ucfirst($item->role) }}</span></td>
                            <td>
                                @if ($item->role == 'penerima')
                                    <span class="badge text-bg-{{ $item->status_verifikasi == 'approved' ? 'success' : ($item->status_verifikasi == 'rejected' ? 'danger' : 'warning') }}">
                                        {{ ucfirst($item->status_verifikasi) }}
                                    </span>
                                @else
                                    <span class="badge text-bg-success">Aktif</span>
                                @endif
                            </td>
                            <td class="small text-secondary">{{ $item->created_at->format('d M Y') }}</td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-danger">Suspend</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-secondary py-4">Belum ada user.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            {{ $users->links() }}
        </div>
    </div>

@endsection
