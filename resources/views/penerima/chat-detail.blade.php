@extends('layout.penerima')

@section('judul', 'Chat - ' . ($percakapan->Donatur->name ?? 'Donatur'))

@section('konten')

    <a href="{{ route('mitra.chat') }}" class="d-inline-block mb-3"><i class="bi bi-arrow-left"></i> Kembali ke daftar chat</a>

    <div class="wc-card" style="height: 65vh; display: flex; flex-direction: column;">
        <div class="p-3 border-bottom d-flex align-items-center gap-2">
            <i class="bi bi-person-circle text-warning fs-5"></i>
            <div>
                <p class="fw-semibold mb-0">{{ $percakapan->Donatur->name ?? 'Donatur' }}</p>
                <p class="small text-secondary mb-0">{{ $percakapan->Campaign->judul }}</p>
            </div>
        </div>

        <div class="p-3 flex-grow-1 overflow-auto d-flex flex-column gap-2">
            @foreach ($percakapan->Messages as $pesan)
                @if ($pesan->sender_id == $percakapan->campaign->user_id)
                    <div class="align-self-end wc-chat-bubble-mine">{{ $pesan->isi }}</div>
                @else
                    <div class="align-self-start wc-chat-bubble-other">{{ $pesan->isi }}</div>
                @endif
            @endforeach
        </div>

        <form method="POST" action="{{ route('mitra.chat.show', $percakapan->id) }}" class="p-3 border-top d-flex gap-2">
            @csrf
            <input type="text" name="isi" class="form-control" placeholder="Balas pesan...">
            <button type="submit" class="wc-btn-primary"><i class="bi bi-send"></i></button>
        </form>
    </div>

@endsection
