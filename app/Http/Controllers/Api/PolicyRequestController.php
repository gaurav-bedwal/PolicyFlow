<?php

namespace App\Http\Controllers\Api;

use App\Enums\PolicyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePolicyRequest;
use App\Http\Resources\PolicyRequestResource;
use App\Models\PolicyRequest;
use App\Services\PremiumCalculator;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PolicyRequestController extends Controller
{
    public function __construct(
        protected PremiumCalculator $calculator
    ) {}

    /**
     * Intake endpoint: Accept and validate policy request with database-enforced idempotency.
     *
     * POST /api/policy-requests
     */
    public function store(StorePolicyRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $agentId = $validated['agent_id'];
        $clientRequestId = $validated['client_request_id'];

        // 1. Check if logical request already exists before attempting insert
        $existing = PolicyRequest::where('agent_id', $agentId)
            ->where('client_request_id', $clientRequestId)
            ->first();

        if ($existing) {
            return $this->handleExistingRequest($existing, $validated);
        }

        // 2. Calculate deterministic pricing snapshot
        $pricing = $this->calculator->calculate(
            planCode: $validated['plan_code'],
            destination: $validated['destination'],
            sumInsured: $validated['sum_insured'],
            dateOfBirth: $validated['date_of_birth'],
            tripStart: $validated['trip_start'],
            tripEnd: $validated['trip_end']
        );

        // 3. Atomically persist with race-condition handling
        try {
            $policyRequest = DB::transaction(function () use ($validated, $pricing) {
                $policy = PolicyRequest::create([
                    'uuid' => (string) Str::uuid(),
                    'client_request_id' => $validated['client_request_id'],
                    'agent_id' => $validated['agent_id'],
                    'customer_name' => $validated['customer_name'],
                    'date_of_birth' => $validated['date_of_birth'],
                    'destination' => $validated['destination'],
                    'trip_start' => $validated['trip_start'],
                    'trip_end' => $validated['trip_end'],
                    'plan_code' => $validated['plan_code'],
                    'sum_insured' => $validated['sum_insured'],
                    'travel_days' => $pricing['travel_days'],
                    'customer_age_at_trip_start' => $pricing['customer_age_at_trip_start'],
                    'base_daily_rate' => $pricing['base_daily_rate'],
                    'age_multiplier' => $pricing['age_multiplier'],
                    'destination_multiplier' => $pricing['destination_multiplier'],
                    'coverage_multiplier' => $pricing['coverage_multiplier'],
                    'premium_amount' => $pricing['premium_amount'],
                    'currency' => $pricing['currency'],
                    'status' => PolicyStatus::VALIDATED,
                ]);

                // Initial audit trail entry
                $policy->stateHistories()->create([
                    'previous_status' => null,
                    'new_status' => PolicyStatus::VALIDATED->value,
                    'reason' => 'Policy request received, validated, and pricing snapshot generated.',
                    'source' => 'INTAKE',
                    'metadata' => [
                        'pricing' => $pricing,
                        'client_request_id' => $validated['client_request_id'],
                        'agent_id' => $validated['agent_id'],
                    ],
                    'created_at' => now(),
                ]);

                return $policy;
            });

            return response()->json([
                'message' => 'Policy request accepted and validated.',
                'data' => (new PolicyRequestResource($policyRequest))->resolve(),
            ], 201);

        } catch (QueryException $e) {
            // Handle unique constraint race condition (MySQL error 1062 / SQLSTATE 23000)
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                $raced = PolicyRequest::where('agent_id', $agentId)
                    ->where('client_request_id', $clientRequestId)
                    ->first();

                if ($raced) {
                    return $this->handleExistingRequest($raced, $validated);
                }
            }

            throw $e;
        }
    }

    /**
     * Retrieve single policy request by UUID or ID, with audit trail and attempts.
     *
     * GET /api/policy-requests/{id}
     */
    public function show(string $id): JsonResponse
    {
        $policy = PolicyRequest::with(['stateHistories', 'attempts'])
            ->where('uuid', $id)
            ->orWhere('id', is_numeric($id) ? (int) $id : 0)
            ->first();

        if (! $policy) {
            return response()->json([
                'message' => "Policy request not found for identifier [{$id}].",
            ], 404);
        }

        return response()->json([
            'data' => (new PolicyRequestResource($policy))->resolve(),
        ], 200);
    }

    /**
     * Paginated list of policy requests with optional status filtering.
     *
     * GET /api/policy-requests?status=ISSUED&per_page=15
     */
    public function index(Request $request): JsonResponse
    {
        $query = PolicyRequest::query();

        // Optional status filter
        if ($request->filled('status')) {
            $status = strtoupper((string) $request->query('status'));
            if (! in_array($status, PolicyStatus::values(), true)) {
                return response()->json([
                    'message' => "Invalid status filter [{$status}]. Allowed values: " . implode(', ', PolicyStatus::values()),
                ], 422);
            }
            $query->where('status', $status);
        }

        // Pagination constraints
        $perPage = (int) $request->query('per_page', 15);
        if ($perPage < 1) {
            $perPage = 15;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }

        $paginated = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'data' => PolicyRequestResource::collection($paginated->items()),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * Safely resolve existing request: return 200 if identical payload, 409 if conflicting payload.
     */
    protected function handleExistingRequest(PolicyRequest $existing, array $newPayload): JsonResponse
    {
        if ($existing->matchesIntakePayload($newPayload)) {
            return response()->json([
                'message' => 'Idempotent replay: identical policy request already accepted.',
                'data' => (new PolicyRequestResource($existing))->resolve(),
            ], 200);
        }

        return response()->json([
            'message' => 'Idempotency conflict: A policy request with this agent_id and client_request_id already exists with different payload attributes.',
            'error_code' => 'IDEMPOTENCY_PAYLOAD_MISMATCH',
            'existing_request_id' => $existing->uuid,
        ], 409);
    }
}
