@extends('operations.layout')

@section('title', "Policy {$policy->client_request_id} — PolicyFlow")

@section('content')
<div style="margin-bottom: 1rem;">
    <a href="{{ route('operations.index') }}" style="color: var(--text-secondary); text-decoration: none; font-size: 13px;">&larr; Back to Dashboard</a>
</div>

<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="font-size: 22px; font-weight: 700;">Policy Request: <span class="mono" style="color: #60a5fa;">{{ $policy->client_request_id }}</span></h1>
        <p style="color: var(--text-muted); font-size: 12px; margin-top: 2px;" class="mono">UUID: {{ $policy->uuid }} &bull; Created: {{ $policy->created_at->format('Y-m-d H:i:s') }} UTC</p>
    </div>
    <div style="display: flex; gap: 0.75rem; align-items: center;">
        <span class="badge badge-{{ $policy->status->value }}" style="font-size: 14px; padding: 6px 12px;">{{ $policy->status->value }}</span>
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
    <!-- Core Details -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header">
            <h3 class="card-title">Applicant & Trip Details</h3>
        </div>
        <table style="font-size: 13px;">
            <tr>
                <td style="color: var(--text-secondary); width: 40%;">Agent ID</td>
                <td class="mono"><strong>{{ $policy->agent_id }}</strong></td>
            </tr>
            <tr>
                <td style="color: var(--text-secondary);">Customer Name</td>
                <td><strong>{{ $policy->customer_name }}</strong></td>
            </tr>
            <tr>
                <td style="color: var(--text-secondary);">Date of Birth</td>
                <td>{{ $policy->date_of_birth->format('d M Y') }} (Age at trip: <strong>{{ $policy->customer_age_at_trip_start }}</strong>)</td>
            </tr>
            <tr>
                <td style="color: var(--text-secondary);">Destination</td>
                <td><span class="badge" style="background: #1e3a8a; color: #93c5fd;">{{ $policy->destination }}</span></td>
            </tr>
            <tr>
                <td style="color: var(--text-secondary);">Trip Duration</td>
                <td>{{ $policy->trip_start->format('d M Y') }} &rarr; {{ $policy->trip_end->format('d M Y') }} (<strong>{{ $policy->travel_days }}</strong> days)</td>
            </tr>
            <tr>
                <td style="color: var(--text-secondary);">Plan / Sum Insured</td>
                <td><strong>{{ $policy->plan_code }}</strong> / ₹{{ number_format($policy->sum_insured) }}</td>
            </tr>
        </table>
    </div>

    <!-- Pricing Snapshot & Provider Identifiers -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header">
            <h3 class="card-title">Immutable Pricing Snapshot</h3>
            <span class="badge" style="background: rgba(16, 185, 129, 0.2); color: #34d399; font-size: 13px;">₹{{ $policy->premium_amount }} {{ $policy->currency }}</span>
        </div>
        <div style="background: #0d1322; border-radius: 8px; padding: 1rem; margin-bottom: 1rem; border: 1px solid var(--border-color);">
            <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px;">Pricing Formula Verification:</div>
            <div class="mono" style="font-size: 12px; color: #93c5fd; line-height: 1.6;">
                ₹{{ $policy->base_daily_rate }} <span style="color: var(--text-muted);">(Base)</span> &times; 
                {{ $policy->travel_days }} <span style="color: var(--text-muted);">(Days)</span> &times; 
                {{ $policy->age_multiplier }} <span style="color: var(--text-muted);">(Age)</span> &times; 
                {{ $policy->destination_multiplier }} <span style="color: var(--text-muted);">(Dest)</span> &times; 
                {{ $policy->coverage_multiplier }} <span style="color: var(--text-muted);">(Coverage)</span><br>
                = <strong style="color: #34d399; font-size: 14px;">₹{{ $policy->premium_amount }} {{ $policy->currency }}</strong>
            </div>
        </div>

        <table style="font-size: 13px;">
            <tr>
                <td style="color: var(--text-secondary); width: 40%;">Policy Number</td>
                <td class="mono">
                    @if ($policy->policy_number)
                        <strong style="color: #34d399;">{{ $policy->policy_number }}</strong>
                    @else
                        <span style="color: var(--text-muted);">Not issued yet</span>
                    @endif
                </td>
            </tr>
            <tr>
                <td style="color: var(--text-secondary);">Provider Ref</td>
                <td class="mono">{{ $policy->provider_reference ?? '—' }}</td>
            </tr>
            <tr>
                <td style="color: var(--text-secondary);">Attempts Count</td>
                <td>{{ $policy->attempt_count }} attempt(s)</td>
            </tr>
            @if ($policy->last_error)
                <tr>
                    <td style="color: #f87171;">Last Error</td>
                    <td style="color: #fca5a5; font-size: 12px;">{{ $policy->last_error }}</td>
                </tr>
            @endif
        </table>
    </div>
</div>

<!-- Operator Controls -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Operator Actions & Simulation Controls</h3>
        <span style="font-size: 12px; color: var(--text-muted);">Development & Testing Mode</span>
    </div>
    <div style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: center;">
        <form action="{{ route('operations.submit', $policy->uuid) }}" method="POST" style="display: flex; gap: 0.5rem; align-items: center;">
            @csrf
            <select name="scenario" class="input-text" style="font-size: 12px;">
                <option value="SUCCESS">Scenario: SUCCESS (Normal 200)</option>
                <option value="TIMEOUT_AFTER_ISSUE">Scenario: TIMEOUT_AFTER_ISSUE (504 Timeout Simulation)</option>
                <option value="TEMPORARY_FAILURE">Scenario: TEMPORARY_FAILURE (503 Retriable)</option>
                <option value="DELAYED_CALLBACK">Scenario: DELAYED_CALLBACK (Async 202)</option>
            </select>
            <button type="submit" class="btn" {{ $policy->isIssued() ? 'disabled' : '' }}>
                {{ $policy->isIssued() ? 'Already Issued' : 'Submit Issuance' }}
            </button>
        </form>

        <form action="{{ route('operations.reconcile', $policy->uuid) }}" method="POST">
            @csrf
            <button type="submit" class="btn btn-secondary">
                &#x21bb; Query Provider Status & Reconcile
            </button>
        </form>
    </div>
</div>

<!-- Issuance Attempts Table -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Issuance Attempts Audit ({{ $policy->attempts->count() }})</h3>
        <span class="mono" style="font-size: 11px; color: var(--text-muted);">Stable Idempotency Key: IDEM-POL-{{ $policy->uuid }}</span>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Idempotency Key</th>
                    <th>Outcome</th>
                    <th>Error Category</th>
                    <th>Started At</th>
                    <th>Completed At</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($policy->attempts as $att)
                    <tr>
                        <td class="mono"><strong>#{{ $att->attempt_number }}</strong></td>
                        <td class="mono" style="color: #93c5fd;">{{ $att->idempotency_key }}</td>
                        <td>
                            @if ($att->outcome === 'SUCCESS')
                                <span class="badge badge-ISSUED">SUCCESS</span>
                            @elseif ($att->outcome === 'TIMEOUT')
                                <span class="badge badge-NEEDS_RECONCILIATION">TIMEOUT</span>
                            @elseif ($att->outcome === 'FAILED')
                                <span class="badge badge-FAILED">FAILED</span>
                            @else
                                <span class="badge badge-PENDING_CONFIRMATION">{{ $att->outcome }}</span>
                            @endif
                        </td>
                        <td class="mono" style="color: #f87171;">{{ $att->error_category ?? '—' }}</td>
                        <td>{{ $att->started_at->format('Y-m-d H:i:s') }}</td>
                        <td>{{ $att->completed_at ? $att->completed_at->format('Y-m-d H:i:s') : 'In Flight' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 1.5rem;">
                            No issuance attempts recorded yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<!-- State Transition History Audit Trail -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Append-Only State Transition History ({{ $policy->stateHistories->count() }})</h3>
        <span style="font-size: 12px; color: var(--text-muted);">Authoritative Audit Trail</span>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Timestamp (UTC)</th>
                    <th>Previous Status</th>
                    <th>New Status</th>
                    <th>Source</th>
                    <th>Reason / Detail</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($policy->stateHistories as $h)
                    <tr>
                        <td class="mono">{{ $h->created_at->format('Y-m-d H:i:s') }}</td>
                        <td>
                            @if ($h->previous_status)
                                <span class="badge badge-{{ $h->previous_status }}">{{ $h->previous_status }}</span>
                            @else
                                <span style="color: var(--text-muted);">None (Intake)</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge badge-{{ $h->new_status }}">{{ $h->new_status }}</span>
                        </td>
                        <td>
                            <strong class="mono" style="color: #c084fc;">{{ $h->source }}</strong>
                        </td>
                        <td style="color: var(--text-primary);">{{ $h->reason }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 1.5rem;">
                            No state transition history records.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
