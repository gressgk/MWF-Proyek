<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ItemCategory extends Model
{
    use SoftDeletes;

    protected $fillable = ['nama', 'slug'];

    public function Donations()
    {
        return $this->hasMany(Donation::class);
    }
}
