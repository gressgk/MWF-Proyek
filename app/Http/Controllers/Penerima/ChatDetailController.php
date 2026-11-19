<?php

namespace App\Http\Controllers\Penerima;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ChatDetailController extends Controller
{
    public function index(Conversation $conversation): View
    {
        Gate::authorize('view', $conversation);

        $this->markAsRead($conversation);
        $percakapan = $this->loadThread($conversation);

        return view('penerima.chat-detail', compact('percakapan'));
    }

    public function pesan(Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        $this->markAsRead($conversation);
        $percakapan = $this->loadThread($conversation);

        return response()->json([
            'last_id' => (int) ($percakapan->Messages->last()?->id ?? 0),
            'html' => view('chat.messages', compact('percakapan'))->render(),
        ]);
    }

    public function kirimPesan(Request $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize('sendMessage', $conversation);

        $validated = $request->validate([
            'isi' => ['required', 'string', 'min:1', 'max:2000'],
        ]);

        $conversation->Messages()->create([
            'sender_id' => getAuthUser()->id,
            'isi' => trim($validated['isi']),
        ]);

        $conversation->touch();

        return redirect()->route('mitra.chat.show', $conversation);
    }

    private function markAsRead(Conversation $conversation): void
    {
        $conversation->Messages()
            ->where('sender_id', '!=', getAuthUser()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    private function loadThread(Conversation $conversation): Conversation
    {
        return $conversation->load([
            'Campaign',
            'Donatur',
            'Messages' => fn ($query) => $query->orderBy('id'),
        ]);
    }
}
