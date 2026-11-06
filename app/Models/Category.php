<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use SoftDeletes;

    protected $fillable = ['nama', 'slug', 'icon'];

    public function Campaigns()
    {
        return $this->hasMany(Campaign::class);
    }
}
