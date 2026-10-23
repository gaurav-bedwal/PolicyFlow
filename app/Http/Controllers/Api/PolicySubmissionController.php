<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PolicyRequestResource;
use App\Models\PolicyRequest;
use App\Services\PolicyIssuanceService;
use App\Services\PolicyReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PolicySubmissionController extends Controller
{
    public function __construct(
        protected PolicyIssuanceService $issuanceService,
        protected PolicyReconciliationService $reconciliationService
    ) {}

    /**
     * Submit policy issuance to the mock insurer.
     *
     * POST /api/policy-requests/{id}/submit
     */
    public function submit(Request $request, string $id): JsonResponse
    {
        $policy = PolicyRequest::where('uuid', $id)
            ->orWhere('id', is_numeric($id) ? (int) $id : 0)
            ->first();

        if (! $policy) {
            return response()->json([
                'message' => "Policy request [{$id}] not found.",
            ], 404);
        }

        $scenario = $request->header('X-Mock-Scenario') ?? $request->input('scenario');

        $result = $this->issuanceService->submit($policy, $scenario);

        return response()->json([
            'message' => $result['message'],
            'data' => (new PolicyRequestResource($result['policy']))->resolve(),
            'policy_number' => $result['policy_number'] ?? null,
            'error_category' => $result['error_category'] ?? null,
            'is_idempotent_replay' => $result['is_idempotent'] ?? false,
            'is_recovered' => $result['recovered'] ?? false,
        ], $result['status_code']);
    }

    /**
     * Manually or automatically trigger reconciliation for uncertain policy requests.
     *
     * POST /api/policy-requests/{id}/reconcile
     */
    public function reconcile(string $id): JsonResponse
    {
        $policy = PolicyRequest::where('uuid', $id)
            ->orWhere('id', is_numeric($id) ? (int) $id : 0)
            ->first();

        if (! $policy) {
            return response()->json([
                'message' => "Policy request [{$id}] not found.",
            ], 404);
        }

        $reconciliation = $this->reconciliationService->reconcile($policy);

        return response()->json([
            'message' => $reconciliation['message'],
            'data' => (new PolicyRequestResource($policy->fresh(['stateHistories', 'attempts'])))->resolve(),
            'is_reconciled' => $reconciliation['reconciled'],
            'status' => $reconciliation['status'],
        ], 200);
    }
}
