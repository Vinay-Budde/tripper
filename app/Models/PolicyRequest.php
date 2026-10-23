<?php

namespace App\Models;

use App\Enums\PolicyStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PolicyRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'client_request_id',
        'agent_id',
        'customer_name',
        'date_of_birth',
        'destination',
        'trip_start',
        'trip_end',
        'plan_code',
        'sum_insured',
        'travel_days',
        'base_daily_rate',
        'age_multiplier',
        'destination_multiplier',
        'coverage_multiplier',
        'calculated_premium',
        'status',
        'policy_number',
        'provider_reference',
        'attempt_count',
        'last_response',
        'last_error_message',
    ];

    protected $casts = [
        'status' => PolicyStatus::class,
        'date_of_birth' => 'date:Y-m-d',
        'trip_start' => 'date:Y-m-d',
        'trip_end' => 'date:Y-m-d',
        'sum_insured' => 'integer',
        'travel_days' => 'integer',
        'base_daily_rate' => 'decimal:2',
        'age_multiplier' => 'decimal:2',
        'destination_multiplier' => 'decimal:2',
        'coverage_multiplier' => 'decimal:2',
        'calculated_premium' => 'decimal:2',
        'attempt_count' => 'integer',
        'last_response' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(IssuanceAttempt::class);
    }

    public function stateHistories(): HasMany
    {
        return $this->hasMany(PolicyStateHistory::class)->orderBy('id', 'asc');
    }

    public function providerEvents(): HasMany
    {
        return $this->hasMany(ProviderEvent::class);
    }
}
