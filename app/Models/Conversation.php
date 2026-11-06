<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $fillable = ['campaign_id', 'donatur_id'];

    public function Campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function Donatur()
    {
        return $this->belongsTo(User::class, 'donatur_id');
    }

    public function Messages()
    {
        return $this->hasMany(Message::class);
    }

    public function PesanTerakhir()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }
}
