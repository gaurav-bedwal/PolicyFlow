<?php

namespace App\Services;

use App\Enums\PolicyStatus;
use App\Exceptions\ProviderTemporaryException;
use App\Exceptions\ProviderTimeoutException;
use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use Illuminate\Support\Facades\DB;
use Throwable;

class PolicyIssuanceService
{
    public function __construct(
        protected MockInsurerService $mockInsurer,
        protected PolicyReconciliationService $reconciler
    ) {}

    /**
     * Coordinate policy submission workflow.
     *
     * @param PolicyRequest $policy
     * @param string|null $scenario Development scenario override
     * @return array
     */
    public function submit(PolicyRequest $policy, ?string $scenario = null): array
    {
        // 1. Guard against duplicate issuance if already issued
        if ($policy->isIssued()) {
            return [
                'status_code' => 200,
                'message' => 'Policy has already been issued.',
                'policy' => $policy,
                'policy_number' => $policy->policy_number,
                'is_idempotent' => true,
            ];
        }

        // 2. If already in an uncertain state, trigger reconciliation instead of a blind re-submission
        if (in_array($policy->status, [PolicyStatus::PENDING_CONFIRMATION, PolicyStatus::NEEDS_RECONCILIATION], true)) {
            $reconciliation = $this->reconciler->reconcile($policy);

            return [
                'status_code' => $policy->isIssued() ? 200 : 202,
                'message' => $reconciliation['message'],
                'policy' => $policy->fresh(['stateHistories', 'attempts']),
                'policy_number' => $policy->policy_number,
                'is_reconciled' => $reconciliation['reconciled'],
            ];
        }

        // 3. Atomically initialize the attempt record and transition to SUBMITTED inside a short transaction
        $idempotencyKey = "IDEM-POL-{$policy->uuid}";
        $attempt = null;

        DB::transaction(function () use ($policy, $idempotencyKey, &$attempt) {
            // Lock row for update to prevent concurrent submissions
            $lockedPolicy = PolicyRequest::where('id', $policy->id)->lockForUpdate()->first();

            if ($lockedPolicy->isIssued()) {
                return;
            }

            $lockedPolicy->attempt_count = $lockedPolicy->attempt_count + 1;
            $lockedPolicy->save();

            $attempt = IssuanceAttempt::create([
                'policy_request_id' => $lockedPolicy->id,
                'attempt_number' => $lockedPolicy->attempt_count,
                'idempotency_key' => $idempotencyKey,
                'outcome' => 'IN_PROGRESS',
                'request_metadata' => [
                    'plan_code' => $lockedPolicy->plan_code,
                    'destination' => $lockedPolicy->destination,
                    'sum_insured' => (string) $lockedPolicy->sum_insured,
                    'premium_amount' => (string) $lockedPolicy->premium_amount,
                ],
                'started_at' => now(),
            ]);

            $lockedPolicy->transitionTo(
                PolicyStatus::SUBMITTED,
                "Issuance attempt #{$attempt->attempt_number} initiated with idempotency key [{$idempotencyKey}].",
                'SUBMISSION',
                ['attempt_number' => $attempt->attempt_number, 'idempotency_key' => $idempotencyKey]
            );
        });

        $policy = $policy->fresh();

        // If the transaction found it was already issued
        if ($policy->isIssued()) {
            return [
                'status_code' => 200,
                'message' => 'Policy has already been issued.',
                'policy' => $policy,
                'policy_number' => $policy->policy_number,
                'is_idempotent' => true,
            ];
        }

        // 4. Send request to mock insurer outside database transaction (network isolation)
        $payload = [
            'idempotency_key' => $idempotencyKey,
            'policy_uuid' => $policy->uuid,
            'customer_name' => $policy->customer_name,
            'date_of_birth' => $policy->date_of_birth->format('Y-m-d'),
            'destination' => $policy->destination,
            'trip_start' => $policy->trip_start->format('Y-m-d'),
            'trip_end' => $policy->trip_end->format('Y-m-d'),
            'plan_code' => $policy->plan_code,
            'sum_insured' => (string) $policy->sum_insured,
            'premium_amount' => (string) $policy->premium_amount, // Reuses immutable snapshot
            'currency' => $policy->currency,
        ];

        try {
            $providerResponse = $this->mockInsurer->issue($payload, $scenario);

            // Handle successful provider issuance
            if (($providerResponse['status'] ?? '') === 'ISSUED') {
                $attempt->update([
                    'outcome' => 'SUCCESS',
                    'response_metadata' => $providerResponse,
                    'completed_at' => now(),
                ]);

                $policy->provider_reference = $providerResponse['provider_reference'];
                $policy->policy_number = $providerResponse['policy_number'];
                $policy->last_provider_response = $providerResponse;
                $policy->last_error = null;
                $policy->save();

                $policy->transitionTo(
                    PolicyStatus::ISSUED,
                    'Authoritative issuance confirmation received from mock insurer.',
                    'SUBMISSION',
                    $providerResponse
                );

                return [
                    'status_code' => 200,
                    'message' => 'Policy successfully issued.',
                    'policy' => $policy->fresh(['stateHistories', 'attempts']),
                    'policy_number' => $policy->policy_number,
                ];
            }

            // Handle asynchronous delayed response (PENDING)
            if (($providerResponse['status'] ?? '') === 'PENDING') {
                $attempt->update([
                    'outcome' => 'PENDING',
                    'response_metadata' => $providerResponse,
                    'completed_at' => now(),
                ]);

                $policy->provider_reference = $providerResponse['provider_reference'];
                $policy->last_provider_response = $providerResponse;
                $policy->save();

                $policy->transitionTo(
                    PolicyStatus::PENDING_CONFIRMATION,
                    'Provider accepted request; awaiting asynchronous callback confirmation.',
                    'SUBMISSION',
                    $providerResponse
                );

                return [
                    'status_code' => 202,
                    'message' => 'Policy issuance accepted and pending confirmation.',
                    'policy' => $policy->fresh(['stateHistories', 'attempts']),
                ];
            }

            throw new \RuntimeException('Unexpected response from insurer: ' . json_encode($providerResponse));

        } catch (ProviderTimeoutException $e) {
            // Handle timeout after issue: Do NOT mark as definitively failed!
            $attempt->update([
                'outcome' => 'TIMEOUT',
                'error_category' => 'TIMEOUT',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            $policy->last_error = $e->getMessage();
            $policy->save();

            $policy->transitionTo(
                PolicyStatus::NEEDS_RECONCILIATION,
                'Submission timed out. State is uncertain; provider query required.',
                'SUBMISSION',
                ['error' => $e->getMessage()]
            );

            // Immediately run reconciliation to recover the policy if the provider succeeded
            $reconciliation = $this->reconciler->reconcile($policy);

            if ($policy->fresh()->isIssued()) {
                return [
                    'status_code' => 200,
                    'message' => 'Timeout occurred during submission, but policy was successfully recovered via reconciliation.',
                    'policy' => $policy->fresh(['stateHistories', 'attempts']),
                    'policy_number' => $policy->policy_number,
                    'recovered' => true,
                ];
            }

            return [
                'status_code' => 504,
                'message' => 'Provider timed out. Policy flagged as NEEDS_RECONCILIATION.',
                'policy' => $policy->fresh(['stateHistories', 'attempts']),
                'error_category' => 'TIMEOUT',
            ];

        } catch (ProviderTemporaryException $e) {
            // Handle temporary retriable failure
            $attempt->update([
                'outcome' => 'FAILED',
                'error_category' => 'TEMPORARY_FAILURE',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            $policy->last_error = $e->getMessage();
            $policy->save();

            $policy->transitionTo(
                PolicyStatus::FAILED,
                'Temporary upstream failure. Safe retry permitted.',
                'SUBMISSION',
                ['error' => $e->getMessage()]
            );

            return [
                'status_code' => 503,
                'message' => 'Temporary insurer failure. Request can be safely retried.',
                'policy' => $policy->fresh(['stateHistories', 'attempts']),
                'error_category' => 'TEMPORARY_FAILURE',
            ];

        } catch (Throwable $e) {
            $attempt->update([
                'outcome' => 'FAILED',
                'error_category' => 'UNEXPECTED_ERROR',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            $policy->last_error = $e->getMessage();
            $policy->save();

            $policy->transitionTo(
                PolicyStatus::NEEDS_RECONCILIATION,
                'Unexpected error during submission: ' . $e->getMessage(),
                'SUBMISSION',
                ['error' => $e->getMessage()]
            );

            return [
                'status_code' => 500,
                'message' => 'Submission encountered an unexpected error: ' . $e->getMessage(),
                'policy' => $policy->fresh(['stateHistories', 'attempts']),
            ];
        }
    }
}
