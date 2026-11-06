<?php

namespace App\Http\Controllers\Penerima;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
class ChatController extends Controller
{
    public function index()
    {
        $percakapan = Conversation::with('Campaign', 'Donatur', 'PesanTerakhir')->latest()->get();
        return view('penerima.chat-index', compact('percakapan'));
    }
}
