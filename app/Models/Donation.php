<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Donation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'campaign_id',
        'user_id',
        'jenis',
        'is_anonim',
        'nominal',
        'is_recurring',
        'frequency',
        'order_id',
        'item_category_id',
        'nama_barang',
        'jumlah_barang',
        'satuan_barang',
        'foto_barang',
        'catatan',
        'metode_pengiriman',
        'status',
        'tanggal_diterima',
    ];

    // relasi
    public function Campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function Donatur()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function ItemCategory()
    {
        return $this->belongsTo(ItemCategory::class);
    }

    // RELASI 1-1
    public function Shipment()
    {
        return $this->hasOne(Shipment::class);
    }

    // accessor nama donatur, sembunyikan kalau anonim
    public function getNamaTampilAttribute()
    {
        return $this->is_anonim ? 'Donatur Anonim' : $this->Donatur->name;
    }
}
