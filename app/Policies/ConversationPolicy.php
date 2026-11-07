<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Conversation;

class ConversationPolicy
{
    public function view(User $user, Conversation $conversation)
    {
        if ($user->id == $conversation->donatur_id) {
            return true;
        }

        return $user->id == $conversation->Campaign->user_id;
    }
}