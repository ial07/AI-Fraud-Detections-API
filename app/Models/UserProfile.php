<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    protected $fillable = [
        'user_id',
        'avg_transaction_amt',
        'max_transaction_amt',
        'total_transactions',
        'usual_location',
        'known_devices',
        'last_active',
    ];

    protected function casts(): array
    {
        return [
            'avg_transaction_amt' => 'decimal:2',
            'max_transaction_amt' => 'decimal:2',
            'known_devices' => 'array',
            'last_active' => 'datetime',
        ];
    }
}
