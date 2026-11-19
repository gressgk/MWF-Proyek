<?php

namespace App\Http\Controllers\Penerima;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ChatController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Conversation::class);

        $userId = getAuthUser()->id;

        $percakapan = Conversation::query()
            ->whereHas('Campaign', fn ($query) => $query->where('user_id', $userId))
            ->with(['Campaign', 'Donatur', 'PesanTerakhir'])
            ->withCount(['Messages as unread_count' => fn ($query) => $query
                ->where('sender_id', '!=', $userId)
                ->whereNull('read_at')])
            ->latest('updated_at')
            ->paginate(15);

        return view('penerima.chat-index', compact('percakapan'));
    }
}
