<?php

namespace App\Http\Controllers\Penerima;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
class ChatDetailController extends Controller
{
    public function index()
    {
        $percakapan = Conversation::with('Campaign', 'Donatur', 'Messages.Sender')->firstOrFail();
        return view('penerima.chat-detail', compact('percakapan'));
    }
}
