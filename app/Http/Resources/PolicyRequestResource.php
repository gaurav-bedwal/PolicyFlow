<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PolicyRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'client_request_id' => $this->client_request_id,
            'agent_id' => $this->agent_id,
            'customer_name' => $this->customer_name,
            'date_of_birth' => $this->date_of_birth ? $this->date_of_birth->format('Y-m-d') : null,
            'destination' => $this->destination,
            'trip_start' => $this->trip_start ? $this->trip_start->format('Y-m-d') : null,
            'trip_end' => $this->trip_end ? $this->trip_end->format('Y-m-d') : null,
            'travel_days' => $this->travel_days,
            'customer_age_at_trip_start' => $this->customer_age_at_trip_start,
            'plan_code' => $this->plan_code,
            'sum_insured' => (string) $this->sum_insured,
            'pricing_snapshot' => [
                'base_daily_rate' => (string) $this->base_daily_rate,
                'age_multiplier' => (string) $this->age_multiplier,
                'destination_multiplier' => (string) $this->destination_multiplier,
                'coverage_multiplier' => (string) $this->coverage_multiplier,
                'premium_amount' => (string) $this->premium_amount,
                'currency' => $this->currency ?? 'INR',
            ],
            'premium_amount' => (string) $this->premium_amount,
            'currency' => $this->currency ?? 'INR',
            'status' => $this->status instanceof \App\Enums\PolicyStatus ? $this->status->value : (string) $this->status,
            'provider_reference' => $this->provider_reference,
            'policy_number' => $this->policy_number,
            'attempt_count' => (int) $this->attempt_count,
            'last_error' => $this->last_error,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'state_history' => $this->whenLoaded('stateHistories', function () {
                return $this->stateHistories->map(function ($h) {
                    return [
                        'id' => $h->id,
                        'previous_status' => $h->previous_status,
                        'new_status' => $h->new_status,
                        'reason' => $h->reason,
                        'source' => $h->source,
                        'metadata' => $h->metadata,
                        'created_at' => $h->created_at?->toIso8601String(),
                    ];
                });
            }),
            'attempts' => $this->whenLoaded('attempts', function () {
                return $this->attempts->map(function ($att) {
                    return [
                        'id' => $att->id,
                        'attempt_number' => $att->attempt_number,
                        'idempotency_key' => $att->idempotency_key,
                        'outcome' => $att->outcome,
                        'error_category' => $att->error_category,
                        'error_message' => $att->error_message,
                        'started_at' => $att->started_at?->toIso8601String(),
                        'completed_at' => $att->completed_at?->toIso8601String(),
                    ];
                });
            }),
        ];
    }
}
