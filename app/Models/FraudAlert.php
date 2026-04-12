<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FraudAlert extends Model
{
    protected $fillable = [
        'transaction_id',
        'risk_score',
        'risk_level',
        'explanations',
        'risk_factors',
        'alert_status',
        'resolution',
    ];

    protected function casts(): array
    {
        return [
            'risk_score' => 'decimal:2',
            'explanations' => 'array',
            'risk_factors' => 'array',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
