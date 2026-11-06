@extends('layout.penerima')

@section('judul', 'Chat')

@section('konten')

    <h5 class="fw-bold mb-4">Chat Masuk</h5>

    <div class="wc-card">
        @forelse ($percakapan as $chat)
            <a href="{{ route('mitra.chat.show', $chat->id) }}" class="d-flex align-items-center gap-3 p-3 border-bottom text-dark">
                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                    <i class="bi bi-person-circle text-warning fs-5"></i>
                </div>
                <div class="flex-grow-1">
                    <p class="fw-semibold mb-0">{{ $chat->Donatur->name ?? 'Donatur' }} &middot; <span class="fw-normal text-secondary small">{{ $chat->Campaign->judul }}</span></p>
                    <p class="small text-secondary mb-0">{{ $chat->PesanTerakhir->isi ?? 'Belum ada pesan' }}</p>
                </div>
                <small class="text-secondary">{{ $chat->updated_at->diffForHumans() }}</small>
            </a>
        @empty
            <div class="text-center text-secondary py-5">Belum ada chat masuk dari donatur.</div>
        @endforelse
    </div>

@endsection
