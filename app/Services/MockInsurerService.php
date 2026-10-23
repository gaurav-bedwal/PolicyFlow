<?php

namespace App\Services;

use App\Exceptions\ProviderTemporaryException;
use App\Exceptions\ProviderTimeoutException;
use App\Models\MockProviderPolicy;
use Illuminate\Support\Str;

class MockInsurerService
{
    public const SCENARIO_SUCCESS = 'SUCCESS';
    public const SCENARIO_TEMPORARY_FAILURE = 'TEMPORARY_FAILURE';
    public const SCENARIO_TIMEOUT_AFTER_ISSUE = 'TIMEOUT_AFTER_ISSUE';
    public const SCENARIO_DELAYED_CALLBACK = 'DELAYED_CALLBACK';

    public const ALLOWED_SCENARIOS = [
        self::SCENARIO_SUCCESS,
        self::SCENARIO_TEMPORARY_FAILURE,
        self::SCENARIO_TIMEOUT_AFTER_ISSUE,
        self::SCENARIO_DELAYED_CALLBACK,
    ];

    /**
     * Submit policy issuance to the mock insurer.
     *
     * @param array $payload
     * @param string|null $scenario
     * @return array
     *
     * @throws ProviderTimeoutException
     * @throws ProviderTemporaryException
     */
    public function issue(array $payload, ?string $scenario = null): array
    {
        $idempotencyKey = $payload['idempotency_key'] ?? throw new \InvalidArgumentException('Missing idempotency_key in provider payload.');

        // 1. Stable provider-side idempotency: If this key has already been processed by the provider,
        // return the existing provider outcome rather than issuing a new policy.
        $existing = MockProviderPolicy::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return [
                'status' => $existing->status,
                'provider_reference' => $existing->provider_reference,
                'policy_number' => $existing->policy_number,
                'message' => 'Idempotent provider response: existing record returned.',
                'is_idempotent_replay' => true,
            ];
        }

        // 2. Determine simulation scenario
        $scenario = strtoupper($scenario ?? request()?->header('X-Mock-Scenario') ?? self::SCENARIO_SUCCESS);
        if (! in_array($scenario, self::ALLOWED_SCENARIOS, true)) {
            $scenario = self::SCENARIO_SUCCESS;
        }

        // 3. Handle Scenario: TEMPORARY_FAILURE
        if ($scenario === self::SCENARIO_TEMPORARY_FAILURE) {
            throw new ProviderTemporaryException('Upstream mock insurer returned 503 Service Unavailable (retriable).', 503);
        }

        // Generate stable identifiers
        $providerReference = 'PROV-' . strtoupper(Str::random(10));
        $policyNumber = 'POL-' . strtoupper(Str::random(12));

        // 4. Handle Scenario: TIMEOUT_AFTER_ISSUE
        // The mock insurer creates the policy internally, but network fails before response reaches client.
        if ($scenario === self::SCENARIO_TIMEOUT_AFTER_ISSUE) {
            MockProviderPolicy::create([
                'idempotency_key' => $idempotencyKey,
                'provider_reference' => $providerReference,
                'policy_number' => $policyNumber,
                'status' => 'ISSUED',
                'payload' => $payload,
                'scenario' => $scenario,
            ]);

            throw new ProviderTimeoutException(
                "Mock insurer connection timed out while awaiting HTTP response (simulated upstream 504 Gateway Timeout).",
                504
            );
        }

        // 5. Handle Scenario: DELAYED_CALLBACK
        if ($scenario === self::SCENARIO_DELAYED_CALLBACK) {
            $policyRecord = MockProviderPolicy::create([
                'idempotency_key' => $idempotencyKey,
                'provider_reference' => $providerReference,
                'policy_number' => $policyNumber, // Assigned on provider side, confirmed later via callback
                'status' => 'PENDING',
                'payload' => $payload,
                'scenario' => $scenario,
            ]);

            return [
                'status' => 'PENDING',
                'provider_reference' => $providerReference,
                'policy_number' => null, // Hidden from caller until authoritative callback
                'message' => 'Issuance accepted for asynchronous underwriting. Awaiting callback.',
            ];
        }

        // 6. Handle Scenario: SUCCESS (Default)
        $policyRecord = MockProviderPolicy::create([
            'idempotency_key' => $idempotencyKey,
            'provider_reference' => $providerReference,
            'policy_number' => $policyNumber,
            'status' => 'ISSUED',
            'payload' => $payload,
            'scenario' => $scenario,
        ]);

        return [
            'status' => 'ISSUED',
            'provider_reference' => $providerReference,
            'policy_number' => $policyNumber,
            'message' => 'Policy successfully issued by insurer.',
        ];
    }

    /**
     * Provider status lookup endpoint: Queries insurer's internal state.
     * Can query by idempotency_key or provider_reference.
     */
    public function lookupStatus(string $identifier): ?array
    {
        $policy = MockProviderPolicy::where('idempotency_key', $identifier)
            ->orWhere('provider_reference', $identifier)
            ->first();

        if (! $policy) {
            return null;
        }

        return [
            'found' => true,
            'status' => $policy->status,
            'provider_reference' => $policy->provider_reference,
            'policy_number' => $policy->policy_number,
            'scenario' => $policy->scenario,
            'created_at' => $policy->created_at?->toIso8601String(),
        ];
    }
}
