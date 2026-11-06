<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = ['conversation_id', 'sender_id', 'isi', 'read_at'];

    public function Conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function Sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
