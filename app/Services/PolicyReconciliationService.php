<?php

namespace App\Services;

use App\Enums\PolicyStatus;
use App\Models\PolicyRequest;

class PolicyReconciliationService
{
    public function __construct(
        protected MockInsurerService $mockInsurer
    ) {}

    /**
     * Reconcile an uncertain policy request against upstream provider state.
     */
    public function reconcile(PolicyRequest $policy): array
    {
        // 1. If already issued, nothing to reconcile
        if ($policy->isIssued()) {
            return [
                'reconciled' => true,
                'status' => PolicyStatus::ISSUED->value,
                'policy' => $policy,
                'message' => 'Policy is already authoritatively ISSUED.',
            ];
        }

        // 2. Query provider by provider_reference (if known) or by stable idempotency key
        $idempotencyKey = "IDEM-POL-{$policy->uuid}";
        $providerRecord = null;

        if ($policy->provider_reference) {
            $providerRecord = $this->mockInsurer->lookupStatus($policy->provider_reference);
        }

        if (! $providerRecord) {
            $providerRecord = $this->mockInsurer->lookupStatus($idempotencyKey);
        }

        // 3. Process provider record
        if ($providerRecord && ($providerRecord['found'] ?? false)) {
            $providerStatus = strtoupper($providerRecord['status']);

            if ($providerStatus === 'ISSUED') {
                $policy->provider_reference = $providerRecord['provider_reference'];
                $policy->policy_number = $providerRecord['policy_number'];
                $policy->last_error = null;

                $policy->transitionTo(
                    PolicyStatus::ISSUED,
                    'Policy outcome recovered via upstream provider status reconciliation.',
                    'RECONCILIATION',
                    $providerRecord
                );

                return [
                    'reconciled' => true,
                    'status' => PolicyStatus::ISSUED->value,
                    'policy' => $policy,
                    'message' => 'Policy successfully reconciled and confirmed as ISSUED.',
                ];
            }

            if ($providerStatus === 'FAILED') {
                $policy->last_error = 'Provider verified policy rejection during reconciliation.';
                $policy->transitionTo(
                    PolicyStatus::FAILED,
                    'Definitive failure confirmed by provider during reconciliation.',
                    'RECONCILIATION',
                    $providerRecord
                );

                return [
                    'reconciled' => true,
                    'status' => PolicyStatus::FAILED->value,
                    'policy' => $policy,
                    'message' => 'Provider confirmed policy is FAILED.',
                ];
            }

            if ($providerStatus === 'PENDING') {
                if ($policy->status !== PolicyStatus::PENDING_CONFIRMATION) {
                    $policy->transitionTo(
                        PolicyStatus::PENDING_CONFIRMATION,
                        'Provider verified issuance is pending asynchronous processing.',
                        'RECONCILIATION',
                        $providerRecord
                    );
                }

                return [
                    'reconciled' => false,
                    'status' => PolicyStatus::PENDING_CONFIRMATION->value,
                    'policy' => $policy,
                    'message' => 'Issuance remains pending upstream confirmation.',
                ];
            }
        }

        // If provider has no record of the idempotency key or reference
        return [
            'reconciled' => false,
            'status' => $policy->status->value,
            'policy' => $policy,
            'message' => 'No authoritative record found at provider for this policy request.',
        ];
    }
}
