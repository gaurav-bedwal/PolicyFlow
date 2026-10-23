@extends('operations.layout')

@section('title', 'PolicyFlow — Request Dashboard')

@section('content')
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Total Requests</div>
        <div class="stat-value">{{ number_format($stats['total']) }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Validated</div>
        <div class="stat-value" style="color: #c084fc;">{{ number_format($stats['validated']) }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Issued</div>
        <div class="stat-value" style="color: #34d399;">{{ number_format($stats['issued']) }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Pending Callback</div>
        <div class="stat-value" style="color: #fcd34d;">{{ number_format($stats['pending']) }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Needs Recon</div>
        <div class="stat-value" style="color: #f472b6;">{{ number_format($stats['needs_recon']) }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Failed</div>
        <div class="stat-value" style="color: #f87171;">{{ number_format($stats['failed']) }}</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Policy Requests Stream</h2>
        <span class="mono" style="color: var(--text-muted); font-size: 12px;">Showing {{ $policies->count() }} of {{ $policies->total() }}</span>
    </div>

    <form method="GET" action="{{ route('operations.index') }}" class="filter-bar">
        <input type="text" name="search" value="{{ $search }}" placeholder="Search client ID, agent, customer..." class="input-text" style="min-width: 260px;">
        <select name="status">
            <option value="">All Statuses</option>
            @foreach ($statuses as $st)
                <option value="{{ $st }}" {{ $currentStatus === $st ? 'selected' : '' }}>{{ $st }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        @if ($search || $currentStatus)
            <a href="{{ route('operations.index') }}" class="btn btn-secondary btn-sm" style="text-decoration: none;">Reset</a>
        @endif
    </form>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Request Ref</th>
                    <th>Agent</th>
                    <th>Customer</th>
                    <th>Dates & Days</th>
                    <th>Plan / Coverage</th>
                    <th>Premium (₹)</th>
                    <th>Status</th>
                    <th>Policy #</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($policies as $policy)
                    <tr>
                        <td>
                            <strong class="mono" style="color: #60a5fa;">{{ $policy->client_request_id }}</strong><br>
                            <span class="mono" style="font-size: 11px; color: var(--text-muted);">{{ substr($policy->uuid, 0, 13) }}...</span>
                        </td>
                        <td class="mono">{{ $policy->agent_id }}</td>
                        <td>
                            {{ $policy->customer_name }}<br>
                            <span style="font-size: 11px; color: var(--text-muted);">Age at trip: {{ $policy->customer_age_at_trip_start }}y</span>
                        </td>
                        <td>
                            {{ $policy->trip_start->format('d M Y') }} &rarr; {{ $policy->trip_end->format('d M Y') }}<br>
                            <span class="badge" style="background: #1e293b; color: #94a3b8; font-size: 10px;">{{ $policy->travel_days }} Day(s)</span>
                        </td>
                        <td>
                            <strong>{{ $policy->plan_code }}</strong> ({{ $policy->destination }})<br>
                            <span style="font-size: 11px; color: var(--text-muted);">₹{{ number_format($policy->sum_insured) }}</span>
                        </td>
                        <td>
                            <strong style="color: #34d399; font-size: 14px;">₹{{ $policy->premium_amount }}</strong>
                        </td>
                        <td>
                            <span class="badge badge-{{ $policy->status->value }}">{{ $policy->status->value }}</span>
                        </td>
                        <td class="mono">
                            {{ $policy->policy_number ?? '—' }}
                        </td>
                        <td>
                            <a href="{{ route('operations.show', $policy->uuid) }}" class="btn btn-secondary btn-sm">Inspect &rarr;</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                            No policy requests found. Click <strong>"+ Create Demo Request"</strong> above or use <code>POST /api/policy-requests</code> to submit intake.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top: 1.5rem;">
        {{ $policies->links() }}
    </div>
</div>
@endsection
