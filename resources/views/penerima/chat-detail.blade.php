@extends('layout.penerima')

@section('judul', 'Chat - ' . ($percakapan->Donatur?->name ?? 'Donatur'))

@section('konten')
    <a href="{{ route('mitra.chat') }}" class="d-inline-block mb-3 text-decoration-none"><i class="bi bi-arrow-left"></i> Kembali ke daftar chat</a>

    <div class="wc-card overflow-hidden" style="height:65vh;display:flex;flex-direction:column;">
        <div class="p-3 border-bottom d-flex align-items-center gap-2">
            <i class="bi bi-person-circle text-warning fs-5"></i>
            <div>
                <p class="fw-semibold mb-0">{{ $percakapan->Donatur?->name ?? 'Donatur' }}</p>
                <p class="small text-secondary mb-0">{{ $percakapan->Campaign?->judul ?? 'Campaign' }}</p>
            </div>
        </div>

        <div id="chat-messages" data-last-id="{{ (int) ($percakapan->Messages->last()?->id ?? 0) }}"
            class="p-3 flex-grow-1 overflow-auto d-flex flex-column gap-2" aria-live="polite">
            @include('chat.messages', ['percakapan' => $percakapan])
        </div>

        <form method="POST" action="{{ route('mitra.chat.kirim', $percakapan) }}" class="p-3 border-top d-flex gap-2">
            @csrf
            <input name="isi" type="text" value="{{ old('isi') }}" maxlength="2000" required
                class="form-control @error('isi') is-invalid @enderror" placeholder="Balas pesan..." aria-label="Pesan">
            <button type="submit" class="wc-btn-primary" aria-label="Kirim pesan"><i class="bi bi-send"></i> Kirim</button>
        </form>
    </div>
    @error('isi') <p class="text-danger small mt-2">{{ $message }}</p> @enderror
    @include('chat.polling', ['pollingUrl' => route('mitra.chat.pesan', $percakapan)])
@endsection
