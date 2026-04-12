<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsightsEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_type',
        'event_data',
        'risk_score',
        'risk_level',
        'source',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'event_data' => 'array',
            'risk_score' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
