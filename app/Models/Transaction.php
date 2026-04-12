<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Transaction extends Model
{
    protected $fillable = [
        'transaction_id',
        'user_id',
        'receiver_id',
        'amount',
        'currency',
        'transaction_type',
        'location',
        'device',
        'device_type',
        'ip_address',
        'risk_score',
        'risk_level',
        'is_flagged',
        'is_simulated',
        'status',
        'recommended_action',
        'ai_explanation',
        'explanation_source',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'risk_score' => 'decimal:2',
            'is_flagged' => 'boolean',
            'is_simulated' => 'boolean',
        ];
    }

    public function alert(): HasOne
    {
        return $this->hasOne(FraudAlert::class);
    }

    public function userProfile()
    {
        return UserProfile::where('user_id', $this->user_id)->first();
    }
}
