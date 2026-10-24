<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flight SaaS Subscriptions & Quota Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-base: #0f172a;
            --bg-card: rgba(30, 41, 59, 0.7);
            --border-card: rgba(255, 255, 255, 0.08);
            --primary: #3b82f6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background: radial-gradient(circle at top right, #1e1b4b, #0f172a 60%, #020617);
            color: var(--text-main);
            min-height: 100vh;
            padding: 2rem 1.5rem;
        }

        .container {
            max-width: 1380px;
            margin: 0 auto;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--border-card);
        }

        .header-title h1 {
            font-size: 1.875rem;
            font-weight: 700;
            background: linear-gradient(to right, #60a5fa, #a855f7);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .header-title p {
            color: var(--text-muted);
            font-size: 0.95rem;
            margin-top: 0.25rem;
        }

        .badge {
            display: inline-block;
            font-size: 0.75rem;
            padding: 0.25rem 0.65rem;
            border-radius: 9999px;
            font-weight: 600;
        }

        .badge-success { background: rgba(16, 185, 129, 0.15); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-warning { background: rgba(245, 158, 11, 0.15); color: var(--warning); border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge-danger { background: rgba(239, 68, 68, 0.15); color: var(--danger); border: 1px solid rgba(239, 68, 68, 0.3); }

        .plans-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }

        .plan-card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 1.25rem;
            padding: 2rem;
            backdrop-filter: blur(12px);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            transition: all 0.2s ease;
        }

        .plan-card.current {
            border-color: #60a5fa;
            box-shadow: 0 0 24px rgba(96, 165, 250, 0.25);
        }

        .plan-card h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .plan-price {
            font-size: 2.25rem;
            font-weight: 800;
            color: #60a5fa;
            margin: 1rem 0;
        }

        .plan-price span {
            font-size: 1rem;
            color: var(--text-muted);
            font-weight: 400;
        }

        .feature-list {
            list-style: none;
            margin: 1.5rem 0;
            font-size: 0.9rem;
        }

        .feature-list li {
            padding: 0.4rem 0;
            color: #cbd5e1;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .feature-list li::before {
            content: "✓";
            color: var(--success);
            font-weight: 700;
        }

        .meter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2.5rem;
        }

        .meter-card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 1rem;
            padding: 1.25rem 1.5rem;
            backdrop-filter: blur(12px);
        }

        .meter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }

        .meter-label {
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .meter-stats {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .progress-bar-bg {
            height: 8px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 9999px;
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(to right, #3b82f6, #60a5fa);
            border-radius: 9999px;
        }

        .progress-bar-fill.overage {
            background: linear-gradient(to right, #f59e0b, #ef4444);
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="header-title">
                <h1>SaaS Subscriptions & Quota Management</h1>
                <p>Multi-Tier Pricing (Starter, Pro, Enterprise) with Real-Time Usage Tracking & Overage Protection</p>
            </div>
            <div>
                <span class="badge {{ ($summary['status'] ?? 'active') === 'active' ? 'badge-success' : 'badge-danger' }}" style="padding: 0.5rem 1rem; font-size: 0.85rem;">
                    Plan: {{ strtoupper($summary['plan_name'] ?? 'No Subscription') }} ({{ strtoupper($summary['status'] ?? 'None') }})
                </span>
            </div>
        </header>

        <!-- Quota Usage Meters -->
        <h2 style="font-size: 1.25rem; margin-bottom: 1rem;">Live Quota Utilization</h2>
        <div class="meter-grid">
            @foreach($summary['quotas'] ?? [] as $metric => $q)
                @php
                    $isUnlimited = $q['is_unlimited'] ?? false;
                    $limit = $q['limit'] ?? 1;
                    $used = $q['used'] ?? 0;
                    $percent = $isUnlimited ? 0 : min(100, round(($used / max(1, $limit)) * 100));
                    $isOverage = ($q['overage_units'] ?? 0) > 0;
                @endphp
                <div class="meter-card">
                    <div class="meter-header">
                        <span class="meter-label">{{ str_replace('_', ' ', $metric) }}</span>
                        <span class="meter-stats">
                            @if($isUnlimited)
                                <strong>{{ $used }}</strong> (Unlimited)
                            @else
                                <strong>{{ $used }}</strong> / {{ $limit }}
                            @endif
                        </span>
                    </div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-fill {{ $isOverage ? 'overage' : '' }}" style="width: {{ $isUnlimited ? '15%' : $percent.'%' }};"></div>
                    </div>
                    <div style="margin-top: 0.5rem; display: flex; justify-content: space-between; font-size: 0.75rem;">
                        <span style="color: var(--text-muted);">
                            {{ $isUnlimited ? 'Uncapped' : ($q['remaining'].' remaining') }}
                        </span>
                        @if($isOverage)
                            <span style="color: var(--danger); font-weight: 700;">+{{ $q['overage_units'] }} overage units</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Plan Cards -->
        <h2 style="font-size: 1.25rem; margin-bottom: 1rem;">Subscription Tiers</h2>
        <div class="plans-grid">
            @foreach($plans ?? [] as $p)
                @php
                    $isCurrent = ($summary['plan_code'] ?? '') === $p->code;
                @endphp
                <div class="plan-card {{ $isCurrent ? 'current' : '' }}">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <h3>{{ $p->name }}</h3>
                            @if($isCurrent)
                                <span class="badge badge-success">Current Plan</span>
                            @endif
                        </div>
                        <div class="plan-price">${{ number_format($p->price, 0) }}<span>/month</span></div>
                        <p style="color: var(--text-muted); font-size: 0.85rem;">{{ $p->description }}</p>

                        <ul class="feature-list">
                            @foreach($p->features ?? [] as $f)
                                <li>{{ ucwords(str_replace('_', ' ', $f)) }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</body>
</html>
