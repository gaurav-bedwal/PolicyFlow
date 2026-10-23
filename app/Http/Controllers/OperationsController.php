<?php

namespace App\Http\Controllers;

use App\Enums\PolicyStatus;
use App\Models\PolicyRequest;
use App\Services\PolicyIssuanceService;
use App\Services\PolicyReconciliationService;
use App\Services\PremiumCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class OperationsController extends Controller
{
    public function __construct(
        protected PolicyIssuanceService $issuanceService,
        protected PolicyReconciliationService $reconciliationService,
        protected PremiumCalculator $calculator
    ) {}

    /**
     * Dashboard listing of policy requests.
     */
    public function index(Request $request): View
    {
        $query = PolicyRequest::query();

        if ($request->filled('status')) {
            $status = strtoupper((string) $request->query('status'));
            if (in_array($status, PolicyStatus::values(), true)) {
                $query->where('status', $status);
            }
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where(function ($q) use ($search) {
                $q->where('client_request_id', 'like', "%{$search}%")
                  ->orWhere('agent_id', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('policy_number', 'like', "%{$search}%")
                  ->orWhere('uuid', 'like', "%{$search}%");
            });
        }

        $policies = $query->orderBy('id', 'desc')->paginate(15)->withQueryString();

        $stats = [
            'total' => PolicyRequest::count(),
            'validated' => PolicyRequest::where('status', PolicyStatus::VALIDATED)->count(),
            'submitted' => PolicyRequest::where('status', PolicyStatus::SUBMITTED)->count(),
            'pending' => PolicyRequest::where('status', PolicyStatus::PENDING_CONFIRMATION)->count(),
            'issued' => PolicyRequest::where('status', PolicyStatus::ISSUED)->count(),
            'needs_recon' => PolicyRequest::where('status', PolicyStatus::NEEDS_RECONCILIATION)->count(),
            'failed' => PolicyRequest::where('status', PolicyStatus::FAILED)->count(),
        ];

        return view('operations.index', [
            'policies' => $policies,
            'stats' => $stats,
            'currentStatus' => $request->query('status'),
            'search' => $request->query('search'),
            'statuses' => PolicyStatus::values(),
        ]);
    }

    /**
     * Show single policy request details, attempts, and audit trail.
     */
    public function show(string $id): View
    {
        $policy = PolicyRequest::with(['stateHistories', 'attempts', 'events'])
            ->where('uuid', $id)
            ->orWhere('id', is_numeric($id) ? (int) $id : 0)
            ->firstOrFail();

        return view('operations.show', [
            'policy' => $policy,
        ]);
    }

    /**
     * Web action to submit issuance with chosen scenario.
     */
    public function submit(Request $request, string $id): RedirectResponse
    {
        $policy = PolicyRequest::where('uuid', $id)->firstOrFail();
        $scenario = $request->input('scenario');

        $result = $this->issuanceService->submit($policy, $scenario);

        $flashType = in_array($result['status_code'], [200, 201, 202]) ? 'success' : 'error';

        return redirect()->route('operations.show', $policy->uuid)
            ->with($flashType, $result['message']);
    }

    /**
     * Web action to trigger reconciliation.
     */
    public function reconcile(string $id): RedirectResponse
    {
        $policy = PolicyRequest::where('uuid', $id)->firstOrFail();

        $result = $this->reconciliationService->reconcile($policy);

        return redirect()->route('operations.show', $policy->uuid)
            ->with('success', $result['message']);
    }

    /**
     * Quick demo request creation for reviewer convenience.
     */
    public function createDemo(Request $request): RedirectResponse
    {
        $clientReqId = 'AGT-' . date('Y') . '-' . str_pad((string) rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $agentId = 'AGENT-' . rand(10, 99);
        $dob = '1996-06-15';
        $tripStart = date('Y-m-d', strtotime('+7 days'));
        $tripEnd = date('Y-m-d', strtotime('+12 days'));
        $plan = 'PLUS';
        $destination = 'INTERNATIONAL';
        $sumInsured = 300000;

        $pricing = $this->calculator->calculate(
            planCode: $plan,
            destination: $destination,
            sumInsured: $sumInsured,
            dateOfBirth: $dob,
            tripStart: $tripStart,
            tripEnd: $tripEnd
        );

        $policy = PolicyRequest::create([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => $clientReqId,
            'agent_id' => $agentId,
            'customer_name' => 'Demo Traveler ' . rand(100, 999),
            'date_of_birth' => $dob,
            'destination' => $destination,
            'trip_start' => $tripStart,
            'trip_end' => $tripEnd,
            'plan_code' => $plan,
            'sum_insured' => $sumInsured,
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

        $policy->stateHistories()->create([
            'previous_status' => null,
            'new_status' => PolicyStatus::VALIDATED->value,
            'reason' => 'Created via web dashboard demo intake.',
            'source' => 'INTAKE',
            'metadata' => ['pricing' => $pricing],
            'created_at' => now(),
        ]);

        return redirect()->route('operations.show', $policy->uuid)
            ->with('success', "New demo policy request [{$clientReqId}] generated and validated.");
    }
}
