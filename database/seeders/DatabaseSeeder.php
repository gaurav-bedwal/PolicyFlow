<?php

namespace Database\Seeders;

use App\Enums\PolicyStatus;
use App\Models\IssuanceAttempt;
use App\Models\MockProviderPolicy;
use App\Models\PolicyRequest;
use App\Services\PremiumCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $calculator = new PremiumCalculator();

        // 1. Policy 1: VALIDATED (Awaiting Submission)
        $pricing1 = $calculator->calculate('PLUS', 'INTERNATIONAL', 300000, '1998-04-10', '2026-11-10', '2026-11-14');
        $p1 = PolicyRequest::create([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Aditi Sharma',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
            'travel_days' => $pricing1['travel_days'],
            'customer_age_at_trip_start' => $pricing1['customer_age_at_trip_start'],
            'base_daily_rate' => $pricing1['base_daily_rate'],
            'age_multiplier' => $pricing1['age_multiplier'],
            'destination_multiplier' => $pricing1['destination_multiplier'],
            'coverage_multiplier' => $pricing1['coverage_multiplier'],
            'premium_amount' => $pricing1['premium_amount'],
            'currency' => 'INR',
            'status' => PolicyStatus::VALIDATED,
        ]);
        $p1->stateHistories()->create([
            'previous_status' => null,
            'new_status' => PolicyStatus::VALIDATED->value,
            'reason' => 'Intake validated; pricing snapshot generated.',
            'source' => 'INTAKE',
            'created_at' => now()->subMinutes(30),
        ]);

        // 2. Policy 2: ISSUED (Fully Confirmed)
        $pricing2 = $calculator->calculate('PREMIUM', 'INTERNATIONAL', 500000, '1985-08-20', '2026-12-01', '2026-12-10');
        $provRef2 = 'PROV-SEED-8801';
        $policyNum2 = 'POL-SEED-8801';
        $p2 = PolicyRequest::create([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => 'AGT-2026-0002',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Rahul Verma',
            'date_of_birth' => '1985-08-20',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-12-01',
            'trip_end' => '2026-12-10',
            'plan_code' => 'PREMIUM',
            'sum_insured' => 500000,
            'travel_days' => $pricing2['travel_days'],
            'customer_age_at_trip_start' => $pricing2['customer_age_at_trip_start'],
            'base_daily_rate' => $pricing2['base_daily_rate'],
            'age_multiplier' => $pricing2['age_multiplier'],
            'destination_multiplier' => $pricing2['destination_multiplier'],
            'coverage_multiplier' => $pricing2['coverage_multiplier'],
            'premium_amount' => $pricing2['premium_amount'],
            'currency' => 'INR',
            'status' => PolicyStatus::ISSUED,
            'provider_reference' => $provRef2,
            'policy_number' => $policyNum2,
            'attempt_count' => 1,
            'submitted_at' => now()->subHours(2),
            'issued_at' => now()->subHours(2),
        ]);
        MockProviderPolicy::create([
            'idempotency_key' => "IDEM-POL-{$p2->uuid}",
            'provider_reference' => $provRef2,
            'policy_number' => $policyNum2,
            'status' => 'ISSUED',
        ]);
        $p2->stateHistories()->create([
            'previous_status' => null,
            'new_status' => PolicyStatus::VALIDATED->value,
            'reason' => 'Intake accepted.',
            'source' => 'INTAKE',
            'created_at' => now()->subHours(2)->subMinutes(5),
        ]);
        $p2->stateHistories()->create([
            'previous_status' => PolicyStatus::VALIDATED->value,
            'new_status' => PolicyStatus::SUBMITTED->value,
            'reason' => 'Submission initiated.',
            'source' => 'SUBMISSION',
            'created_at' => now()->subHours(2)->subMinutes(3),
        ]);
        $p2->stateHistories()->create([
            'previous_status' => PolicyStatus::SUBMITTED->value,
            'new_status' => PolicyStatus::ISSUED->value,
            'reason' => 'Authoritative policy confirmation received.',
            'source' => 'SUBMISSION',
            'created_at' => now()->subHours(2),
        ]);
        IssuanceAttempt::create([
            'policy_request_id' => $p2->id,
            'attempt_number' => 1,
            'idempotency_key' => "IDEM-POL-{$p2->uuid}",
            'outcome' => 'SUCCESS',
            'started_at' => now()->subHours(2)->subMinutes(3),
            'completed_at' => now()->subHours(2),
        ]);

        // 3. Policy 3: PENDING_CONFIRMATION (Waiting for Callback)
        $pricing3 = $calculator->calculate('BASIC', 'DOMESTIC', 100000, '2001-02-14', '2026-10-25', '2026-10-28');
        $provRef3 = 'PROV-SEED-9902';
        $p3 = PolicyRequest::create([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => 'AGT-2026-0003',
            'agent_id' => 'AGENT-25',
            'customer_name' => 'Kavita Patel',
            'date_of_birth' => '2001-02-14',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-10-25',
            'trip_end' => '2026-10-28',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'travel_days' => $pricing3['travel_days'],
            'customer_age_at_trip_start' => $pricing3['customer_age_at_trip_start'],
            'base_daily_rate' => $pricing3['base_daily_rate'],
            'age_multiplier' => $pricing3['age_multiplier'],
            'destination_multiplier' => $pricing3['destination_multiplier'],
            'coverage_multiplier' => $pricing3['coverage_multiplier'],
            'premium_amount' => $pricing3['premium_amount'],
            'currency' => 'INR',
            'status' => PolicyStatus::PENDING_CONFIRMATION,
            'provider_reference' => $provRef3,
            'attempt_count' => 1,
            'submitted_at' => now()->subMinutes(15),
        ]);
        MockProviderPolicy::create([
            'idempotency_key' => "IDEM-POL-{$p3->uuid}",
            'provider_reference' => $provRef3,
            'policy_number' => 'POL-SEED-9902',
            'status' => 'PENDING',
        ]);
        $p3->stateHistories()->create([
            'previous_status' => null,
            'new_status' => PolicyStatus::VALIDATED->value,
            'reason' => 'Intake accepted.',
            'source' => 'INTAKE',
            'created_at' => now()->subMinutes(20),
        ]);
        $p3->stateHistories()->create([
            'previous_status' => PolicyStatus::VALIDATED->value,
            'new_status' => PolicyStatus::SUBMITTED->value,
            'reason' => 'Submission initiated.',
            'source' => 'SUBMISSION',
            'created_at' => now()->subMinutes(16),
        ]);
        $p3->stateHistories()->create([
            'previous_status' => PolicyStatus::SUBMITTED->value,
            'new_status' => PolicyStatus::PENDING_CONFIRMATION->value,
            'reason' => 'Provider accepted request; awaiting asynchronous callback confirmation.',
            'source' => 'SUBMISSION',
            'created_at' => now()->subMinutes(15),
        ]);
        IssuanceAttempt::create([
            'policy_request_id' => $p3->id,
            'attempt_number' => 1,
            'idempotency_key' => "IDEM-POL-{$p3->uuid}",
            'outcome' => 'PENDING',
            'started_at' => now()->subMinutes(16),
            'completed_at' => now()->subMinutes(15),
        ]);

        // 4. Policy 4: FAILED (Temporary network failure - ready for safe retry)
        $pricing4 = $calculator->calculate('PLUS', 'DOMESTIC', 300000, '1992-11-05', '2026-11-01', '2026-11-05');
        $p4 = PolicyRequest::create([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => 'AGT-2026-0004',
            'agent_id' => 'AGENT-25',
            'customer_name' => 'Siddharth Roy',
            'date_of_birth' => '1992-11-05',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-01',
            'trip_end' => '2026-11-05',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
            'travel_days' => $pricing4['travel_days'],
            'customer_age_at_trip_start' => $pricing4['customer_age_at_trip_start'],
            'base_daily_rate' => $pricing4['base_daily_rate'],
            'age_multiplier' => $pricing4['age_multiplier'],
            'destination_multiplier' => $pricing4['destination_multiplier'],
            'coverage_multiplier' => $pricing4['coverage_multiplier'],
            'premium_amount' => $pricing4['premium_amount'],
            'currency' => 'INR',
            'status' => PolicyStatus::FAILED,
            'last_error' => 'Upstream mock insurer returned 503 Service Unavailable (retriable).',
            'attempt_count' => 1,
            'submitted_at' => now()->subMinutes(10),
        ]);
        $p4->stateHistories()->create([
            'previous_status' => null,
            'new_status' => PolicyStatus::VALIDATED->value,
            'reason' => 'Intake accepted.',
            'source' => 'INTAKE',
            'created_at' => now()->subMinutes(12),
        ]);
        $p4->stateHistories()->create([
            'previous_status' => PolicyStatus::VALIDATED->value,
            'new_status' => PolicyStatus::SUBMITTED->value,
            'reason' => 'Submission initiated.',
            'source' => 'SUBMISSION',
            'created_at' => now()->subMinutes(11),
        ]);
        $p4->stateHistories()->create([
            'previous_status' => PolicyStatus::SUBMITTED->value,
            'new_status' => PolicyStatus::FAILED->value,
            'reason' => 'Temporary upstream failure. Safe retry permitted.',
            'source' => 'SUBMISSION',
            'created_at' => now()->subMinutes(10),
        ]);
        IssuanceAttempt::create([
            'policy_request_id' => $p4->id,
            'attempt_number' => 1,
            'idempotency_key' => "IDEM-POL-{$p4->uuid}",
            'outcome' => 'FAILED',
            'error_category' => 'TEMPORARY_FAILURE',
            'error_message' => 'Upstream mock insurer returned 503 Service Unavailable (retriable).',
            'started_at' => now()->subMinutes(11),
            'completed_at' => now()->subMinutes(10),
        ]);
    }
}
