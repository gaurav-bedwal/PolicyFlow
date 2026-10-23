<?php

namespace Tests\Feature;

use App\Enums\PolicyStatus;
use App\Models\IssuanceAttempt;
use App\Models\MockProviderPolicy;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PolicyIssuanceAndReliabilityTest extends TestCase
{
    use DatabaseTransactions;

    protected array $basePayload = [
        'client_request_id' => 'AGT-RELIABILITY-001',
        'agent_id' => 'AGENT-42',
        'customer_name' => 'John Doe Reliability',
        'date_of_birth' => '1995-05-15',
        'destination' => 'INTERNATIONAL',
        'trip_start' => '2026-12-01',
        'trip_end' => '2026-12-10',
        'plan_code' => 'PREMIUM',
        'sum_insured' => 500000,
    ];

    /**
     * Helper to create a validated policy request.
     */
    protected function createValidatedPolicy(array $overrides = []): PolicyRequest
    {
        $payload = array_merge($this->basePayload, $overrides);
        $res = $this->postJson('/api/policy-requests', $payload);
        $res->assertStatus(201);
        $uuid = $res->json('data.id');

        return PolicyRequest::where('uuid', $uuid)->firstOrFail();
    }

    /**
     * Test successful standard issuance:
     * - Generates unique policy number.
     * - Status transitions to ISSUED.
     * - Audit trail is created.
     */
    public function test_successful_issuance_creates_unique_policy_number_and_audits(): void
    {
        $policy = $this->createValidatedPolicy();

        $response = $this->postJson("/api/policy-requests/{$policy->uuid}/submit");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.attempt_count', 1);

        $policyNumber = $response->json('policy_number');
        $this->assertNotNull($policyNumber);
        $this->assertStringStartsWith('POL-', $policyNumber);

        // Verify policy request updated in database
        $this->assertDatabaseHas('policy_requests', [
            'uuid' => $policy->uuid,
            'status' => PolicyStatus::ISSUED->value,
            'policy_number' => $policyNumber,
            'attempt_count' => 1,
        ]);

        // Verify attempt recorded
        $this->assertDatabaseHas('issuance_attempts', [
            'policy_request_id' => $policy->id,
            'attempt_number' => 1,
            'outcome' => 'SUCCESS',
        ]);

        // Verify audit history includes SUBMISSION transition to ISSUED
        $this->assertDatabaseHas('policy_state_histories', [
            'policy_request_id' => $policy->id,
            'previous_status' => PolicyStatus::SUBMITTED->value,
            'new_status' => PolicyStatus::ISSUED->value,
            'source' => 'SUBMISSION',
        ]);
    }

    /**
     * Test repeated submission of already ISSUED policy:
     * - Returns existing result (200).
     * - Does NOT create another policy number or second attempt.
     */
    public function test_repeated_submission_is_idempotent_and_does_not_reissue(): void
    {
        $policy = $this->createValidatedPolicy();

        // First submit -> 200 ISSUED
        $res1 = $this->postJson("/api/policy-requests/{$policy->uuid}/submit");
        $res1->assertStatus(200);
        $policyNumber1 = $res1->json('policy_number');

        // Second submit -> 200 with same policy number
        $res2 = $this->postJson("/api/policy-requests/{$policy->uuid}/submit");
        $res2->assertStatus(200)
            ->assertJsonPath('is_idempotent_replay', true)
            ->assertJsonPath('policy_number', $policyNumber1);

        // Verify only 1 attempt was recorded
        $attemptsCount = IssuanceAttempt::where('policy_request_id', $policy->id)->count();
        $this->assertSame(1, $attemptsCount);
    }

    /**
     * RELIABILITY CASE 2: TIMEOUT AFTER PROVIDER-SIDE SUCCESS
     *
     * The mock provider creates a policy internally, but submission call times out.
     * Expected behavior:
     * - Local application does NOT mark request as definitively failed.
     * - No blind reissue occurs.
     * - Reconciliation queries provider state and recovers the already-issued policy number.
     * - Final state becomes ISSUED exactly once.
     */
    public function test_reliability_case_2_timeout_after_issue_is_safely_reconciled(): void
    {
        $policy = $this->createValidatedPolicy([
            'client_request_id' => 'AGT-TIMEOUT-001',
        ]);

        // Simulate TIMEOUT_AFTER_ISSUE via header
        $response = $this->withHeaders(['X-Mock-Scenario' => 'TIMEOUT_AFTER_ISSUE'])
            ->postJson("/api/policy-requests/{$policy->uuid}/submit");

        // The submission service catches the timeout, sets NEEDS_RECONCILIATION,
        // and immediately executes reconciliation to recover the policy.
        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('is_recovered', true);

        $policyNumber = $response->json('policy_number');
        $this->assertNotNull($policyNumber);

        // Verify the recovered policy number matches what the mock provider created
        $mockProviderRecord = MockProviderPolicy::where('idempotency_key', "IDEM-POL-{$policy->uuid}")->first();
        $this->assertNotNull($mockProviderRecord);
        $this->assertSame($mockProviderRecord->policy_number, $policyNumber);

        // Verify attempt logged TIMEOUT
        $this->assertDatabaseHas('issuance_attempts', [
            'policy_request_id' => $policy->id,
            'outcome' => 'TIMEOUT',
            'error_category' => 'TIMEOUT',
        ]);

        // Verify state history shows transition to NEEDS_RECONCILIATION, then ISSUED via RECONCILIATION
        $this->assertDatabaseHas('policy_state_histories', [
            'policy_request_id' => $policy->id,
            'new_status' => PolicyStatus::NEEDS_RECONCILIATION->value,
        ]);

        $this->assertDatabaseHas('policy_state_histories', [
            'policy_request_id' => $policy->id,
            'new_status' => PolicyStatus::ISSUED->value,
            'source' => 'RECONCILIATION',
        ]);
    }

    /**
     * RELIABILITY CASE 3: DUPLICATE CALLBACK DEDUPLICATION & IMMUTABILITY
     *
     * Deliver the same provider event repeatedly.
     * Expected behavior:
     * - The event is processed at most once.
     * - The policy number remains unchanged.
     * - The audit trail does not show duplicate successful issuance transitions.
     * - An ISSUED state is not downgraded.
     */
    public function test_reliability_case_3_duplicate_callbacks_are_deduplicated(): void
    {
        $policy = $this->createValidatedPolicy([
            'client_request_id' => 'AGT-ASYNC-001',
        ]);

        // Step 1: Submit under DELAYED_CALLBACK scenario -> Status becomes PENDING_CONFIRMATION
        $subResponse = $this->withHeaders(['X-Mock-Scenario' => 'DELAYED_CALLBACK'])
            ->postJson("/api/policy-requests/{$policy->uuid}/submit");

        $subResponse->assertStatus(202)
            ->assertJsonPath('data.status', 'PENDING_CONFIRMATION');

        $providerRef = $subResponse->json('data.provider_reference');
        $this->assertNotNull($providerRef);

        $callbackPayload = [
            'provider_event_id' => 'evt-callback-unique-999',
            'provider_reference' => $providerRef,
            'event_type' => 'POLICY_ISSUED',
            'policy_number' => 'POL-ASYNC-99999',
        ];

        // Step 2: First delivery of callback -> PROCESSED (200), policy becomes ISSUED
        $cb1 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $cb1->assertStatus(200)
            ->assertJsonPath('status', 'PROCESSED');

        $policyFresh = $policy->fresh();
        $this->assertSame(PolicyStatus::ISSUED, $policyFresh->status);
        $this->assertSame('POL-ASYNC-99999', $policyFresh->policy_number);

        // Step 3: Second delivery of identical callback event -> DUPLICATE_EVENT_IGNORED (200)
        $cb2 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $cb2->assertStatus(200)
            ->assertJsonPath('status', 'DUPLICATE_EVENT_IGNORED');

        // Step 4: Third delivery of identical callback event -> DUPLICATE_EVENT_IGNORED (200)
        $cb3 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $cb3->assertStatus(200)
            ->assertJsonPath('status', 'DUPLICATE_EVENT_IGNORED');

        // Step 5: Deliver a contradictory rejection event to an already ISSUED policy:
        // Must NEVER regress or overwrite an authoritative ISSUED state
        $contradictoryPayload = [
            'provider_event_id' => 'evt-contradictory-late-001',
            'provider_reference' => $providerRef,
            'event_type' => 'POLICY_REJECTED',
        ];

        $cbLate = $this->postJson('/api/mock-insurer/callback', $contradictoryPayload);
        $cbLate->assertStatus(200);

        // Verify status remains ISSUED and policy number is untouched
        $policyFinal = $policy->fresh();
        $this->assertSame(PolicyStatus::ISSUED, $policyFinal->status);
        $this->assertSame('POL-ASYNC-99999', $policyFinal->policy_number);

        // Verify only 1 callback transition exists in state history
        $callbackTransitions = $policyFinal->stateHistories()
            ->where('source', 'CALLBACK')
            ->count();
        $this->assertSame(1, $callbackTransitions);
    }

    /**
     * RELIABILITY CASE 4: SAFE RETRY AFTER TEMPORARY FAILURE
     *
     * Expected behavior:
     * - First attempt experiences TEMPORARY_FAILURE (503).
     * - Policy marked as FAILED with error category TEMPORARY_FAILURE.
     * - No policy number is assigned.
     * - Re-submitting the same request reuses the immutable pricing snapshot.
     * - Second attempt succeeds and transitions to ISSUED.
     */
    public function test_safe_retry_after_temporary_failure(): void
    {
        $policy = $this->createValidatedPolicy([
            'client_request_id' => 'AGT-RETRY-001',
        ]);
        $originalPremium = (string) $policy->premium_amount;

        // Attempt 1: Simulate TEMPORARY_FAILURE
        $res1 = $this->withHeaders(['X-Mock-Scenario' => 'TEMPORARY_FAILURE'])
            ->postJson("/api/policy-requests/{$policy->uuid}/submit");

        $res1->assertStatus(503)
            ->assertJsonPath('error_category', 'TEMPORARY_FAILURE')
            ->assertJsonPath('data.status', 'FAILED');

        $this->assertNull($policy->fresh()->policy_number);
        $this->assertSame(1, $policy->fresh()->attempt_count);

        // Attempt 2: Safe retry without error scenario -> SUCCESS
        $this->flushHeaders();
        $res2 = $this->postJson("/api/policy-requests/{$policy->uuid}/submit");

        $res2->assertStatus(200)
            ->assertJsonPath('data.status', 'ISSUED');

        $fresh = $policy->fresh();
        $this->assertSame(PolicyStatus::ISSUED, $fresh->status);
        $this->assertNotNull($fresh->policy_number);
        $this->assertSame(2, $fresh->attempt_count);
        // Original premium must be preserved exactly
        $this->assertSame($originalPremium, (string) $fresh->premium_amount);

        // Verify two attempt records: 1 FAILED, 2 SUCCESS
        $this->assertDatabaseHas('issuance_attempts', [
            'policy_request_id' => $policy->id,
            'attempt_number' => 1,
            'outcome' => 'FAILED',
        ]);
        $this->assertDatabaseHas('issuance_attempts', [
            'policy_request_id' => $policy->id,
            'attempt_number' => 2,
            'outcome' => 'SUCCESS',
        ]);
    }

    /**
     * Test provider lookup endpoint:
     * GET /api/mock-insurer/policies/{identifier}
     */
    public function test_provider_status_lookup(): void
    {
        $policy = $this->createValidatedPolicy();
        $res = $this->postJson("/api/policy-requests/{$policy->uuid}/submit");
        $provRef = $res->json('data.provider_reference');

        $lookup = $this->getJson("/api/mock-insurer/policies/{$provRef}");
        $lookup->assertStatus(200)
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.provider_reference', $provRef);

        // Unknown identifier returns 404
        $this->getJson('/api/mock-insurer/policies/UNKNOWN-PROV-999')
            ->assertStatus(404);
    }
}
