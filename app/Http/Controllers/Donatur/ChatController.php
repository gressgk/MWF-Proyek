<?php

namespace App\Http\Controllers\Donatur;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
class ChatController extends Controller
{
    public function index()
    {
        $percakapan = Conversation::with('Campaign', 'PesanTerakhir')->latest()->get();
        return view('donatur.chat-index', compact('percakapan'));
    }
}
