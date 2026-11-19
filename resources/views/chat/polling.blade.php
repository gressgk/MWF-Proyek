{{-- Polling setiap 5 detik: fallback jika Laravel Reverb/Pusher belum diaktifkan. --}}
<script>
    (() => {
        const panel = document.getElementById('chat-messages');
        if (!panel) return;

        const endpoint = @json($pollingUrl);
        let lastId = Number(panel.dataset.lastId || 0);
        let isBusy = false;

        const scrollBottom = () => { panel.scrollTop = panel.scrollHeight; };
        scrollBottom();

        async function refreshMessages() {
            if (isBusy || document.hidden) return;
            isBusy = true;
            try {
                const response = await fetch(endpoint, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    cache: 'no-store'
                });
                if (!response.ok) return;
                const result = await response.json();
                const currentLastId = Number(result.last_id || 0);
                if (currentLastId !== lastId) {
                    const nearBottom = panel.scrollHeight - panel.scrollTop - panel.clientHeight < 120;
                    panel.innerHTML = result.html;
                    lastId = currentLastId;
                    if (nearBottom) scrollBottom();
                }
            } catch (error) {
                // Koneksi sesaat gagal: pesan tersimpan di database, polling berikutnya akan mencoba lagi.
            } finally {
                isBusy = false;
            }
        }

        window.setInterval(refreshMessages, 5000);
    })();
</script>
