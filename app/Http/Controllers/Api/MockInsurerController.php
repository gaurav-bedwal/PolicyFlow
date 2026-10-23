<?php

namespace App\Http\Controllers\Api;

use App\Enums\PolicyStatus;
use App\Http\Controllers\Controller;
use App\Models\MockProviderPolicy;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use App\Services\MockInsurerService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MockInsurerController extends Controller
{
    public function __construct(
        protected MockInsurerService $mockInsurer
    ) {}

    /**
     * Provider Callback Endpoint: Receives and deduplicates asynchronous provider events.
     *
     * POST /api/mock-insurer/callback
     */
    public function handleCallback(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'provider_event_id' => ['required', 'string', 'max:100'],
            'provider_reference' => ['required', 'string', 'max:100'],
            'event_type' => ['required', 'string', 'in:POLICY_ISSUED,POLICY_REJECTED'],
            'policy_number' => ['nullable', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid callback payload.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $eventId = (string) $request->input('provider_event_id');
        $providerRef = (string) $request->input('provider_reference');
        $eventType = (string) $request->input('event_type');
        $policyNumber = $request->input('policy_number');

        // 1. Check for duplicate event using database unique constraint
        $existingEvent = ProviderEvent::where('provider_event_id', $eventId)->first();
        if ($existingEvent) {
            return response()->json([
                'status' => 'DUPLICATE_EVENT_IGNORED',
                'message' => 'Event has already been received and processed.',
                'provider_event_id' => $eventId,
            ], 200);
        }

        // 2. Resolve matching PolicyRequest safely
        $policy = PolicyRequest::where('provider_reference', $providerRef)->first();

        // Fallback: If policy didn't have provider_reference saved yet, try resolving via mock provider policy
        if (! $policy) {
            $mockPolicy = MockProviderPolicy::where('provider_reference', $providerRef)->first();
            if ($mockPolicy) {
                // idempotency_key is IDEM-POL-{uuid}
                $uuid = str_replace('IDEM-POL-', '', $mockPolicy->idempotency_key);
                $policy = PolicyRequest::where('uuid', $uuid)->first();
                if ($policy && ! $policy->provider_reference) {
                    $policy->provider_reference = $providerRef;
                    $policy->save();
                }
            }
        }

        if (! $policy) {
            try {
                ProviderEvent::create([
                    'provider_event_id' => $eventId,
                    'provider_reference' => $providerRef,
                    'event_type' => $eventType,
                    'payload' => $request->all(),
                    'processing_status' => 'UNRESOLVED',
                    'received_at' => now(),
                ]);
            } catch (QueryException) {
                // If concurrent callback with same event_id was inserted
            }

            return response()->json([
                'message' => "No policy request found matching provider_reference [{$providerRef}].",
                'status' => 'UNRESOLVED',
            ], 422);
        }

        // 3. Apply state transition with deduplication and immutability protection
        try {
            DB::transaction(function () use ($policy, $eventId, $providerRef, $eventType, $policyNumber, $request) {
                // Record event record atomically
                ProviderEvent::create([
                    'policy_request_id' => $policy->id,
                    'provider_event_id' => $eventId,
                    'provider_reference' => $providerRef,
                    'event_type' => $eventType,
                    'payload' => $request->all(),
                    'processing_status' => 'PROCESSED',
                    'received_at' => now(),
                    'processed_at' => now(),
                ]);

                // ISSUED is terminal and authoritative - never overwrite or regress
                if ($policy->isIssued()) {
                    // Stale or duplicate callback for already issued policy
                    return;
                }

                if ($eventType === 'POLICY_ISSUED') {
                    if ($policyNumber) {
                        $policy->policy_number = $policyNumber;
                    }
                    $policy->last_error = null;
                    $policy->save();

                    $policy->transitionTo(
                        PolicyStatus::ISSUED,
                        "Confirmed via asynchronous provider callback event [{$eventId}].",
                        'CALLBACK',
                        $request->all()
                    );
                } elseif ($eventType === 'POLICY_REJECTED') {
                    $policy->last_error = 'Underwriting rejected by insurer via callback.';
                    $policy->save();

                    $policy->transitionTo(
                        PolicyStatus::FAILED,
                        "Underwriting rejection received via callback event [{$eventId}].",
                        'CALLBACK',
                        $request->all()
                    );
                }
            });

            return response()->json([
                'status' => 'PROCESSED',
                'message' => 'Provider callback processed successfully.',
                'policy_status' => $policy->fresh()->status->value,
            ], 200);

        } catch (QueryException $e) {
            // Handle race on duplicate provider_event_id
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                return response()->json([
                    'status' => 'DUPLICATE_EVENT_IGNORED',
                    'message' => 'Duplicate event race detected: event already processed.',
                    'provider_event_id' => $eventId,
                ], 200);
            }

            throw $e;
        }
    }

    /**
     * Provider Status Lookup: Query the mock insurer's internal state directly.
     *
     * GET /api/mock-insurer/policies/{identifier}
     */
    public function lookup(string $identifier): JsonResponse
    {
        $status = $this->mockInsurer->lookupStatus($identifier);

        if (! $status) {
            return response()->json([
                'message' => "No mock policy found for identifier [{$identifier}].",
            ], 404);
        }

        return response()->json([
            'data' => $status,
        ], 200);
    }

    /**
     * Direct Mock Insurer Issuance Endpoint: For isolated provider testing.
     *
     * POST /api/mock-insurer/issue
     */
    public function issue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string'],
        ]);

        $scenario = $request->header('X-Mock-Scenario') ?? $request->input('scenario');

        $result = $this->mockInsurer->issue($request->all(), $scenario);

        return response()->json($result, 200);
    }
}
