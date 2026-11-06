@extends('layout.donatur')

@section('judul', 'Chat - ' . $percakapan->Campaign->judul)

@section('konten')

    <a href="{{ route('donatur.chat') }}" class="d-inline-block mb-3"><i class="bi bi-arrow-left"></i> Kembali ke daftar chat</a>

    <div class="wc-card" style="height: 65vh; display: flex; flex-direction: column;">
        <div class="p-3 border-bottom d-flex align-items-center gap-2">
            <i class="bi bi-chat-dots text-warning fs-5"></i>
            <div>
                <p class="fw-semibold mb-0">{{ $percakapan->Campaign->judul }}</p>
                <p class="small text-secondary mb-0">dengan {{ $percakapan->Campaign->Penerima->name ?? 'Penerima' }}</p>
            </div>
        </div>

        <div class="p-3 flex-grow-1 overflow-auto d-flex flex-column gap-2">
            @foreach ($percakapan->Messages as $pesan)
                @if ($pesan->sender_id == $percakapan->donatur_id)
                    <div class="align-self-end wc-chat-bubble-mine">{{ $pesan->isi }}</div>
                @else
                    <div class="align-self-start wc-chat-bubble-other">{{ $pesan->isi }}</div>
                @endif
            @endforeach
        </div>

        <form method="POST" action="{{ route('donatur.chat.show', $percakapan->id) }}" class="p-3 border-top d-flex gap-2">
            @csrf
            <input type="text" name="isi" class="form-control" placeholder="Tulis pesan...">
            <button type="submit" class="wc-btn-primary"><i class="bi bi-send"></i></button>
        </form>
    </div>

@endsection
