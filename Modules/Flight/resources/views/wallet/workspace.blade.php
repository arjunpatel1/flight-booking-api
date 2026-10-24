<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flight Financial Wallet & Double-Entry Ledger</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-base: #0f172a;
            --bg-card: rgba(30, 41, 59, 0.7);
            --bg-card-hover: rgba(51, 65, 85, 0.8);
            --border-card: rgba(255, 255, 255, 0.08);
            --primary: #3b82f6;
            --primary-glow: rgba(59, 130, 246, 0.25);
            --success: #10b981;
            --success-glow: rgba(16, 185, 129, 0.25);
            --danger: #ef4444;
            --warning: #f59e0b;
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

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        .kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 1rem;
            padding: 1.25rem 1.5rem;
            backdrop-filter: blur(12px);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.36);
            transition: all 0.2s ease;
        }

        .kpi-card:hover {
            border-color: rgba(96, 165, 250, 0.4);
            transform: translateY(-2px);
        }

        .kpi-card .label {
            font-size: 0.85rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }

        .kpi-card .value {
            font-size: 1.75rem;
            font-weight: 700;
        }

        .kpi-card .badge {
            display: inline-block;
            margin-top: 0.5rem;
            font-size: 0.75rem;
            padding: 0.2rem 0.6rem;
            border-radius: 9999px;
            font-weight: 600;
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.15);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.15);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .badge-danger {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .section-card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 1rem;
            padding: 1.5rem;
            backdrop-filter: blur(12px);
            margin-bottom: 2rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
        }

        .section-header h2 {
            font-size: 1.25rem;
            font-weight: 600;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.9rem;
        }

        th {
            background: rgba(15, 23, 42, 0.6);
            color: var(--text-muted);
            font-weight: 600;
            padding: 0.85rem 1rem;
            border-bottom: 1px solid var(--border-card);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }

        td {
            padding: 0.95rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            color: #e2e8f0;
        }

        tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }

        .type-pill {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-weight: 600;
        }

        .type-recharge { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
        .type-booking { background: rgba(239, 68, 68, 0.2); color: #f87171; }
        .type-refund { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        .type-commission { background: rgba(168, 85, 247, 0.2); color: #c084fc; }
        .type-adjustment { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
        .type-transfer { background: rgba(14, 165, 233, 0.2); color: #38bdf8; }

        .account-badge {
            font-family: monospace;
            background: rgba(0, 0, 0, 0.3);
            padding: 0.2rem 0.4rem;
            border-radius: 0.25rem;
            font-size: 0.8rem;
            color: #cbd5e1;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="header-title">
                <h1>Flight Financial Wallet & Double-Entry Ledger</h1>
                <p>Enterprise Hierarchy (Tenant &rarr; Distributor &rarr; Agent) with Zero-Sum Double-Entry Balancing</p>
            </div>
            <div>
                <span class="badge {{ ($consistency['is_consistent'] ?? false) ? 'badge-success' : 'badge-danger' }}" style="padding: 0.5rem 1rem; font-size: 0.85rem;">
                    {{ ($consistency['is_consistent'] ?? false) ? '✓ Double-Entry Ledger Balanced (0.00 Imbalance)' : '⚠ Ledger Imbalance Detected' }}
                </span>
            </div>
        </header>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="label">Tenant Liquid Balance</div>
                <div class="value" style="color: #60a5fa;">{{ number_format($tenantWallet->balance ?? 0, 2) }} {{ $tenantWallet->currency ?? 'INR' }}</div>
                <span class="badge badge-success">Live Cash Vault</span>
            </div>
            <div class="kpi-card">
                <div class="label">Total Available Purchasing Power</div>
                <div class="value" style="color: #34d399;">{{ number_format($tenantWallet->available_credit ?? 0, 2) }} {{ $tenantWallet->currency ?? 'INR' }}</div>
                <span class="badge badge-success">Liquid + Approved Credit Line</span>
            </div>
            <div class="kpi-card">
                <div class="label">Approved Credit Line</div>
                <div class="value" style="color: #fbbf24;">{{ number_format($tenantWallet->credit_limit ?? 0, 2) }} {{ $tenantWallet->currency ?? 'INR' }}</div>
                <div class="label" style="margin-top: 0.4rem;">Used: {{ number_format($tenantWallet->credit_used ?? 0, 2) }}</div>
            </div>
            <div class="kpi-card">
                <div class="label">Double-Entry Journal Entries</div>
                <div class="value" style="color: #c084fc;">{{ $consistency['total_ledger_entries'] ?? 0 }}</div>
                <span class="badge badge-success">{{ $consistency['total_transactions'] ?? 0 }} Completed Transactions</span>
            </div>
        </div>

        <!-- Wallets Grid -->
        <div class="section-card">
            <div class="section-header">
                <h2>Network Entity Wallets (Tenant, Distributors & Agents)</h2>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Account ID</th>
                        <th>Owner Type</th>
                        <th>Owner ID</th>
                        <th>Liquid Balance</th>
                        <th>Credit Limit</th>
                        <th>Credit Used</th>
                        <th>Available Credit</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($wallets as $wallet)
                        <tr>
                            <td><span class="account-badge">{{ $wallet->account_identifier }}</span></td>
                            <td><strong style="color: #93c5fd;">{{ $wallet->owner_type }}</strong></td>
                            <td>#{{ $wallet->owner_id }}</td>
                            <td><strong>{{ number_format($wallet->balance, 2) }} {{ $wallet->currency }}</strong></td>
                            <td>{{ number_format($wallet->credit_limit, 2) }}</td>
                            <td style="color: #f87171;">{{ number_format($wallet->credit_used, 2) }}</td>
                            <td style="color: #34d399; font-weight: 700;">{{ number_format($wallet->available_credit, 2) }}</td>
                            <td>
                                <span class="badge {{ $wallet->is_active ? 'badge-success' : 'badge-danger' }}">
                                    {{ $wallet->is_active ? 'Active' : 'Suspended' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" style="text-align: center; color: var(--text-muted);">No wallets registered.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Double-Entry Transaction Journal -->
        <div class="section-card">
            <div class="section-header">
                <h2>Recent Double-Entry Financial Journal</h2>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Txn ID</th>
                        <th>Reference</th>
                        <th>Type</th>
                        <th>Debit Account</th>
                        <th>Credit Account</th>
                        <th>Amount</th>
                        <th>Opening</th>
                        <th>Closing</th>
                        <th>Actor</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentTransactions as $txn)
                        <tr>
                            <td><span class="account-badge">{{ $txn->transaction_id }}</span></td>
                            <td><code>{{ $txn->reference }}</code></td>
                            <td>
                                <span class="type-pill type-{{ strtolower(str_replace('_', '', $txn->type)) }}">
                                    {{ $txn->type }}
                                </span>
                            </td>
                            <td><span class="account-badge" style="color: #fca5a5;">{{ $txn->debit_account }}</span></td>
                            <td><span class="account-badge" style="color: #86efac;">{{ $txn->credit_account }}</span></td>
                            <td><strong>{{ number_format($txn->amount, 2) }} {{ $txn->currency }}</strong></td>
                            <td>{{ number_format($txn->opening_balance, 2) }}</td>
                            <td>{{ number_format($txn->closing_balance, 2) }}</td>
                            <td>{{ $txn->actor }}</td>
                            <td style="color: var(--text-muted); font-size: 0.8rem;">{{ $txn->created_at?->format('Y-m-d H:i:s') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" style="text-align: center; color: var(--text-muted);">No financial transactions recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
