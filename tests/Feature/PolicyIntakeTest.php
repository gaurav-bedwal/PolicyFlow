<?php

namespace Tests\Feature;

use App\Enums\PolicyStatus;
use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PolicyIntakeTest extends TestCase
{
    use DatabaseTransactions;

    protected array $validPayload = [
        'client_request_id' => 'AGT-2026-0001',
        'agent_id' => 'AGENT-17',
        'customer_name' => 'Sample Customer',
        'date_of_birth' => '1998-04-10',
        'destination' => 'INTERNATIONAL',
        'trip_start' => '2026-11-10',
        'trip_end' => '2026-11-14',
        'plan_code' => 'PLUS',
        'sum_insured' => 300000,
    ];

    /**
     * Test valid policy request intake returns HTTP 201 and correct snapshot.
     */
    public function test_valid_intake_returns_201_created(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload);

        $response->assertStatus(201)
            ->assertJsonPath('data.client_request_id', 'AGT-2026-0001')
            ->assertJsonPath('data.agent_id', 'AGENT-17')
            ->assertJsonPath('data.status', 'VALIDATED')
            ->assertJsonPath('data.travel_days', 5)
            ->assertJsonPath('data.customer_age_at_trip_start', 28)
            ->assertJsonPath('data.premium_amount', '225.00')
            ->assertJsonPath('data.currency', 'INR');

        $this->assertDatabaseHas('policy_requests', [
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'status' => PolicyStatus::VALIDATED->value,
            'premium_amount' => '225.00',
        ]);

        $this->assertDatabaseHas('policy_state_histories', [
            'new_status' => PolicyStatus::VALIDATED->value,
            'source' => 'INTAKE',
        ]);
    }

    /**
     * Test identical duplicate intake returns HTTP 200 and does NOT create a duplicate row.
     */
    public function test_identical_duplicate_intake_returns_200_without_duplication(): void
    {
        // First submission -> 201
        $first = $this->postJson('/api/policy-requests', $this->validPayload);
        $first->assertStatus(201);
        $uuid = $first->json('data.id');

        // Second submission with identical payload -> 200
        $second = $this->postJson('/api/policy-requests', $this->validPayload);
        $second->assertStatus(200)
            ->assertJsonPath('data.id', $uuid)
            ->assertJsonPath('message', 'Idempotent replay: identical policy request already accepted.');

        // Verify only 1 record exists in database
        $count = PolicyRequest::where('agent_id', 'AGENT-17')
            ->where('client_request_id', 'AGT-2026-0001')
            ->count();

        $this->assertSame(1, $count);
    }

    /**
     * Test reusing same identity (agent_id + client_request_id) with different payload returns 409 Conflict.
     */
    public function test_conflicting_duplicate_intake_returns_409(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload)->assertStatus(201);

        // Different destination and plan
        $conflicting = array_merge($this->validPayload, [
            'destination' => 'DOMESTIC',
            'plan_code' => 'PREMIUM',
        ]);

        $response = $this->postJson('/api/policy-requests', $conflicting);

        $response->assertStatus(409)
            ->assertJsonPath('error_code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');
    }

    /**
     * Validation test: trip_end before trip_start.
     */
    public function test_trip_end_before_start_rejected(): void
    {
        $payload = array_merge($this->validPayload, [
            'trip_start' => '2026-11-15',
            'trip_end' => '2026-11-10',
        ]);

        $this->postJson('/api/policy-requests', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['trip_end']);
    }

    /**
     * Validation test: date_of_birth after trip_start.
     */
    public function test_dob_after_trip_start_rejected(): void
    {
        $payload = array_merge($this->validPayload, [
            'date_of_birth' => '2026-12-01',
            'trip_start' => '2026-11-10',
        ]);

        $this->postJson('/api/policy-requests', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date_of_birth']);
    }

    /**
     * Validation test: unsupported destination.
     */
    public function test_unsupported_destination_rejected(): void
    {
        $payload = array_merge($this->validPayload, [
            'destination' => 'MOON',
        ]);

        $this->postJson('/api/policy-requests', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['destination']);
    }

    /**
     * Validation test: unsupported plan.
     */
    public function test_unsupported_plan_rejected(): void
    {
        $payload = array_merge($this->validPayload, [
            'plan_code' => 'VIP',
        ]);

        $this->postJson('/api/policy-requests', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plan_code']);
    }

    /**
     * Validation test: unsupported coverage.
     */
    public function test_unsupported_coverage_rejected(): void
    {
        $payload = array_merge($this->validPayload, [
            'sum_insured' => 200000,
        ]);

        $this->postJson('/api/policy-requests', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sum_insured']);
    }

    /**
     * Retrieval test: Valid request returns details and state audit history.
     */
    public function test_retrieve_policy_request_returns_audit_trail(): void
    {
        $create = $this->postJson('/api/policy-requests', $this->validPayload);
        $uuid = $create->json('data.id');

        $response = $this->getJson("/api/policy-requests/{$uuid}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $uuid)
            ->assertJsonPath('data.status', 'VALIDATED')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'client_request_id',
                    'agent_id',
                    'customer_name',
                    'premium_amount',
                    'pricing_snapshot',
                    'state_history',
                ],
            ]);

        $this->assertNotEmpty($response->json('data.state_history'));
    }

    /**
     * Retrieval test: Unknown ID returns 404.
     */
    public function test_unknown_request_returns_404(): void
    {
        $this->getJson('/api/policy-requests/non-existent-uuid-1234')
            ->assertStatus(404);
    }

    /**
     * Listing test: Pagination and status filtering.
     */
    public function test_policy_listing_and_filtering(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload);

        // List all
        $list = $this->getJson('/api/policy-requests?per_page=5');
        $list->assertStatus(200)
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total']]);

        // Filter valid status
        $filtered = $this->getJson('/api/policy-requests?status=VALIDATED');
        $filtered->assertStatus(200);

        // Invalid status filter returns 422
        $invalid = $this->getJson('/api/policy-requests?status=INVALID_STATUS');
        $invalid->assertStatus(422);
    }
}
