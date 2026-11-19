@extends('layout.penerima')

@section('judul', 'Chat Masuk')

@section('konten')
    <h5 class="fw-bold mb-4">Chat Masuk</h5>

    <div class="wc-card overflow-hidden">
        @forelse ($percakapan as $chat)
            <a href="{{ route('mitra.chat.show', $chat) }}" class="d-flex align-items-center gap-3 p-3 border-bottom text-dark text-decoration-none">
                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;">
                    <i class="bi bi-person-circle text-warning fs-5"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <p class="fw-semibold mb-0">{{ $chat->Donatur?->name ?? 'Donatur' }}
                        <span class="fw-normal text-secondary small"> · {{ $chat->Campaign?->judul ?? 'Campaign tidak tersedia' }}</span></p>
                    <p class="small text-secondary mb-0 text-truncate">{{ $chat->PesanTerakhir?->isi ?? 'Belum ada pesan' }}</p>
                </div>
                <div class="text-end flex-shrink-0">
                    @if ($chat->unread_count > 0)
                        <span class="badge text-bg-warning">{{ $chat->unread_count }} baru</span>
                    @endif
                    <small class="d-block text-secondary text-nowrap">{{ $chat->updated_at?->diffForHumans() }}</small>
                </div>
            </a>
        @empty
            <div class="text-center text-secondary py-5">Belum ada chat masuk dari Donatur.</div>
        @endforelse
    </div>
    <div class="mt-3">{{ $percakapan->links() }}</div>
@endsection
