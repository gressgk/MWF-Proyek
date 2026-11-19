<?php

namespace App\Http\Controllers\Donatur;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ChatController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Conversation::class);

        $userId = getAuthUser()->id;

        $percakapan = Conversation::query()
            ->where('donatur_id', $userId)
            ->whereHas('Campaign')
            ->with(['Campaign.Penerima', 'PesanTerakhir'])
            ->withCount(['Messages as unread_count' => fn ($query) => $query
                ->where('sender_id', '!=', $userId)
                ->whereNull('read_at')])
            ->latest('updated_at')
            ->paginate(15);

        return view('donatur.chat-index', compact('percakapan'));
    }

    public function mulai(Campaign $campaign): RedirectResponse
    {
        Gate::authorize('viewAny', Conversation::class);

        abort_unless($campaign->status === 'aktif' && $campaign->Penerima !== null, 404);
        abort_if((int) $campaign->user_id === (int) getAuthUser()->id, 403);

        // Tabel conversations sudah punya UNIQUE (campaign_id, donatur_id).
        // createOrFirst menangani request serentak tanpa membuat duplikasi.
        $percakapan = Conversation::query()->createOrFirst([
            'campaign_id' => $campaign->id,
            'donatur_id' => getAuthUser()->id,
        ]);

        Gate::authorize('view', $percakapan);

        return redirect()->route('donatur.chat.show', $percakapan);
    }
}
