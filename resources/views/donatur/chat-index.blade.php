@extends('layout.donatur')

@section('judul', 'Chat - WeCare')

@section('konten')

    <h4 class="fw-bold mb-4">Chat</h4>

    <div class="wc-card">
        @forelse ($percakapan as $chat)
            <a href="{{ route('donatur.chat.show', $chat->id) }}" class="d-flex align-items-center gap-3 p-3 border-bottom text-dark">
                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                    <i class="bi bi-chat-dots text-warning fs-5"></i>
                </div>
                <div class="flex-grow-1">
                    <p class="fw-semibold mb-0">{{ $chat->Campaign->judul }}</p>
                    <p class="small text-secondary mb-0">{{ $chat->PesanTerakhir->isi ?? 'Belum ada pesan' }}</p>
                </div>
                <small class="text-secondary">{{ $chat->updated_at->diffForHumans() }}</small>
            </a>
        @empty
            <div class="text-center text-secondary py-5">
                Belum ada percakapan. Mulai chat dari halaman detail campaign.
            </div>
        @endforelse
    </div>

@endsection
