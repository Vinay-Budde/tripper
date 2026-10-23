<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MockInsurerPolicy extends Model
{
    protected $fillable = [
        'partner_request_id',
        'provider_reference',
        'policy_number',
        'status',
        'premium',
        'customer_data',
    ];

    protected $casts = [
        'customer_data' => 'array',
        'premium' => 'decimal:2',
    ];
}
