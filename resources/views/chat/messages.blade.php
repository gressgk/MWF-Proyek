@forelse ($percakapan->Messages as $pesan)
    <div class="{{ (int) $pesan->sender_id === (int) getAuthUser()->id ? 'align-self-end wc-chat-bubble-mine' : 'align-self-start wc-chat-bubble-other' }}"
        style="max-width:85%;white-space:pre-wrap;overflow-wrap:anywhere;">{{ $pesan->isi }}</div>
@empty
    <p class="text-center text-secondary small my-auto">Belum ada pesan. Silakan mulai percakapan.</p>
@endforelse
