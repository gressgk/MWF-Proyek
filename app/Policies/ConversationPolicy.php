<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Conversation;

class ConversationPolicy
{
    public function viewAny(User $user)
    {
        return $user->isDonatur() || $user->isPenerima();
    }

    public function view(User $user, Conversation $conversation)
    {
        if ($user->isDonatur()) {
            return $user->id == $conversation->donatur_id;
        }

        if ($user->isPenerima()) {
            return $user->id == $conversation->Campaign?->user_id;
        }

        return false;
    }

    public function sendMessage(User $user, Conversation $conversation)
    {
        return $this->view($user, $conversation);
    }
}