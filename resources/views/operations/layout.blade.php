<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'PolicyFlow — Insurance Operations Engine')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-main: #0b0f19;
            --bg-card: #111827;
            --bg-card-hover: #172033;
            --border-color: #1f293d;
            --text-primary: #f3f4f6;
            --text-secondary: #9ca3af;
            --text-muted: #6b7280;
            --accent-blue: #3b82f6;
            --accent-blue-hover: #2563eb;
            --badge-green-bg: #064e3b;
            --badge-green-text: #34d399;
            --badge-blue-bg: #1e3a8a;
            --badge-blue-text: #60a5fa;
            --badge-yellow-bg: #78350f;
            --badge-yellow-text: #fcd34d;
            --badge-red-bg: #7f1d1d;
            --badge-red-text: #f87171;
            --badge-purple-bg: #581c87;
            --badge-purple-text: #c084fc;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: var(--bg-main);
            color: var(--text-primary);
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            min-height: 100vh;
        }

        .navbar {
            background: #0d1322;
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: var(--text-primary);
        }

        .brand-badge {
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            color: #fff;
            font-weight: 700;
            font-size: 13px;
            padding: 4px 8px;
            border-radius: 6px;
            letter-spacing: 0.5px;
        }

        .brand-title {
            font-size: 18px;
            font-weight: 700;
            letter-spacing: -0.2px;
        }

        .brand-subtitle {
            font-size: 12px;
            color: var(--text-muted);
            margin-left: 4px;
        }

        .container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 2rem;
        }

        .flash-message {
            padding: 1rem 1.25rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .flash-success {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.4);
            color: #34d399;
        }

        .flash-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.4);
            color: #f87171;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 1.25rem;
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border-color);
        }

        .card-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        th {
            background: rgba(17, 24, 39, 0.7);
            color: var(--text-secondary);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 10px 14px;
            border-bottom: 1px solid var(--border-color);
        }

        td {
            padding: 12px 14px;
            border-bottom: 1px solid rgba(31, 41, 61, 0.6);
            color: var(--text-primary);
        }

        tr:hover td {
            background: var(--bg-card-hover);
        }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.3px;
            font-family: 'JetBrains Mono', monospace;
        }

        .badge-RECEIVED { background: var(--badge-blue-bg); color: var(--badge-blue-text); }
        .badge-VALIDATED { background: var(--badge-purple-bg); color: var(--badge-purple-text); }
        .badge-SUBMITTED { background: #1e293b; color: #94a3b8; }
        .badge-PENDING_CONFIRMATION { background: var(--badge-yellow-bg); color: var(--badge-yellow-text); }
        .badge-ISSUED { background: var(--badge-green-bg); color: var(--badge-green-text); }
        .badge-FAILED { background: var(--badge-red-bg); color: var(--badge-red-text); }
        .badge-NEEDS_RECONCILIATION { background: #831843; color: #f472b6; }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--accent-blue);
            color: white;
            padding: 8px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .btn:hover {
            background: var(--accent-blue-hover);
        }

        .btn-secondary {
            background: #1f293d;
            color: var(--text-primary);
            border: 1px solid #374151;
        }

        .btn-secondary:hover {
            background: #2d3748;
        }

        .btn-sm {
            padding: 4px 10px;
            font-size: 12px;
        }

        .mono {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
        }

        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .input-text, select {
            background: #111827;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 13px;
            outline: none;
        }

        .input-text:focus, select:focus {
            border-color: var(--accent-blue);
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <a href="{{ route('operations.index') }}" class="navbar-brand">
            <span class="brand-badge">PF</span>
            <div>
                <span class="brand-title">PolicyFlow</span>
                <span class="brand-subtitle">Operations & Reconciliation Engine</span>
            </div>
        </a>
        <div style="display: flex; gap: 1rem; align-items: center;">
            <a href="/api/policy-requests" target="_blank" class="btn btn-secondary btn-sm mono">REST API Docs (JSON)</a>
            <form action="{{ route('operations.demo') }}" method="POST" style="margin: 0;">
                @csrf
                <button type="submit" class="btn btn-sm">+ Create Demo Request</button>
            </form>
        </div>
    </nav>

    <div class="container">
        @if (session('success'))
            <div class="flash-message flash-success">
                <span>✓ {{ session('success') }}</span>
            </div>
        @endif

        @if (session('error'))
            <div class="flash-message flash-error">
                <span>✕ {{ session('error') }}</span>
            </div>
        @endif

        @yield('content')
    </div>
</body>
</html>
