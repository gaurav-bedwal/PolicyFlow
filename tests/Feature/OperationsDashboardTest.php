<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OperationsDashboardTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Test operations dashboard index renders correctly.
     */
    public function test_dashboard_renders(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200)
            ->assertSee('PolicyFlow')
            ->assertSee('Policy Requests Stream');
    }

    /**
     * Test demo request creation action.
     */
    public function test_demo_creation_redirects_to_show_page(): void
    {
        $response = $this->post('/operations/demo');

        $response->assertStatus(302);
        $this->assertDatabaseHas('policy_requests', [
            'plan_code' => 'PLUS',
            'destination' => 'INTERNATIONAL',
        ]);
    }

    /**
     * Test policy show page displays pricing breakdown and attempts.
     */
    public function test_show_page_displays_policy_details(): void
    {
        $this->post('/operations/demo');
        $policy = PolicyRequest::latest('id')->firstOrFail();

        $response = $this->get("/operations/policies/{$policy->uuid}");

        $response->assertStatus(200)
            ->assertSee($policy->client_request_id)
            ->assertSee('Immutable Pricing Snapshot')
            ->assertSee('Append-Only State Transition History');
    }
}
