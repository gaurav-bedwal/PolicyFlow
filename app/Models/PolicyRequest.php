<?php

namespace App\Models;

use App\Enums\PolicyStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PolicyRequest extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'status' => PolicyStatus::class,
        'date_of_birth' => 'date:Y-m-d',
        'trip_start' => 'date:Y-m-d',
        'trip_end' => 'date:Y-m-d',
        'sum_insured' => 'decimal:2',
        'base_daily_rate' => 'decimal:2',
        'age_multiplier' => 'decimal:2',
        'destination_multiplier' => 'decimal:2',
        'coverage_multiplier' => 'decimal:2',
        'premium_amount' => 'decimal:2',
        'last_provider_response' => 'array',
        'attempt_count' => 'integer',
        'travel_days' => 'integer',
        'customer_age_at_trip_start' => 'integer',
        'submitted_at' => 'datetime',
        'issued_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (PolicyRequest $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Issuance attempts for this policy request.
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(IssuanceAttempt::class)->orderBy('attempt_number', 'asc');
    }

    /**
     * Provider callback events associated with this policy request.
     */
    public function events(): HasMany
    {
        return $this->hasMany(ProviderEvent::class)->orderBy('received_at', 'desc');
    }

    /**
     * Audit trail of state transitions.
     */
    public function stateHistories(): HasMany
    {
        return $this->hasMany(PolicyStateHistory::class)->orderBy('id', 'asc');
    }

    /**
     * Check if the policy has already reached authoritative ISSUED state.
     */
    public function isIssued(): bool
    {
        return $this->status === PolicyStatus::ISSUED;
    }

    /**
     * Centralized state transition method with validation and audit logging.
     *
     * @throws InvalidArgumentException
     */
    public function transitionTo(
        PolicyStatus $newStatus,
        string $reason,
        string $source,
        ?array $metadata = null
    ): bool {
        $currentStatus = $this->status;

        // ISSUED is immutable - never downgrade or overwrite
        if ($currentStatus === PolicyStatus::ISSUED && $newStatus !== PolicyStatus::ISSUED) {
            throw new InvalidArgumentException(
                "Illegal transition: Policy {$this->uuid} is already ISSUED and cannot transition to {$newStatus->value}."
            );
        }

        // If transitioning to the same status, log audit if metadata/reason is provided, but don't error
        if ($currentStatus === $newStatus) {
            return true;
        }

        if (! $currentStatus->canTransitionTo($newStatus)) {
            throw new InvalidArgumentException(
                "Invalid transition from {$currentStatus->value} to {$newStatus->value} for policy {$this->uuid}."
            );
        }

        $this->status = $newStatus;

        if ($newStatus === PolicyStatus::SUBMITTED && ! $this->submitted_at) {
            $this->submitted_at = now();
        }

        if ($newStatus === PolicyStatus::ISSUED && ! $this->issued_at) {
            $this->issued_at = now();
        }

        $this->save();

        $this->stateHistories()->create([
            'previous_status' => $currentStatus->value,
            'new_status' => $newStatus->value,
            'reason' => $reason,
            'source' => $source,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);

        return true;
    }

    /**
     * Check whether an incoming intake payload matches this request's material fields.
     */
    public function matchesIntakePayload(array $payload): bool
    {
        return (string) $this->customer_name === (string) ($payload['customer_name'] ?? '')
            && $this->date_of_birth->format('Y-m-d') === (string) ($payload['date_of_birth'] ?? '')
            && (string) $this->destination === (string) ($payload['destination'] ?? '')
            && $this->trip_start->format('Y-m-d') === (string) ($payload['trip_start'] ?? '')
            && $this->trip_end->format('Y-m-d') === (string) ($payload['trip_end'] ?? '')
            && (string) $this->plan_code === (string) ($payload['plan_code'] ?? '')
            && bccomp((string) $this->sum_insured, (string) ($payload['sum_insured'] ?? 0), 2) === 0;
    }
}
