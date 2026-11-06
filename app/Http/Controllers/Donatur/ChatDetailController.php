<?php

namespace App\Http\Controllers\Donatur;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
class ChatDetailController extends Controller
{
    public function index()
    {
        $percakapan = Conversation::with('Campaign.Penerima', 'Messages.Sender')->firstOrFail();
        return view('donatur.chat-detail', compact('percakapan'));
    }
}
