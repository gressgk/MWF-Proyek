<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignReportPhoto extends Model
{
    protected $fillable = [
        'campaign_report_id',
        'foto_path',
    ];

    public function Report()
    {
        return $this->belongsTo(
            CampaignReport::class,
            'campaign_report_id'
        );
    }
}
