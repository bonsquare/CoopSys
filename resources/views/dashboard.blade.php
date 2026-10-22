@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
@php
    $periods = ['today' => 'Today', 'month' => 'This Month', 'quarter' => 'Quarter'];
@endphp

    <!-- Page header with controls -->
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-space-md">
        <div>
            <div class="flex items-center gap-space-xs mb-space-xs">
                <span class="inline-block w-2 h-2 rounded-full bg-secondary"></span>
                <span class="font-label-sm text-label-sm text-secondary uppercase font-semibold tracking-wider">Live System Data · {{ $periodLabel }}</span>
            </div>
            <h1 class="font-headline-lg text-headline-lg text-on-surface tracking-tight">Cooperative Executive Overview</h1>
            <p class="font-body-md text-body-md text-on-surface-variant mt-0.5">Summary of cooperative operations, capital, and loan portfolio.</p>
        </div>

        <div class="flex flex-wrap items-center gap-space-sm no-print">
            <!-- Period filter -->
            <div class="flex items-center bg-surface-container p-0.5 rounded-lg shadow-sm">
                @foreach ($periods as $key => $text)
                    <a href="{{ route('dashboard', ['period' => $key]) }}"
                       class="px-space-md py-1.5 rounded-md font-label-md text-label-md transition-colors {{ $period === $key ? 'bg-surface-container-lowest text-primary font-semibold shadow-sm' : 'text-on-surface-variant hover:text-on-surface' }}">
                        {{ $key === 'quarter' ? 'Q' . now()->quarter . ' ' . now()->year : $text }}
                    </a>
                @endforeach
            </div>

            <div class="h-6 w-px bg-outline-variant hidden sm:block"></div>

            <button type="button" onclick="window.print()" class="flex items-center gap-space-xs px-space-md py-2 bg-surface-container-lowest hover:bg-surface-container-high text-on-surface font-label-md text-label-md rounded-lg shadow-sm transition-colors">
                <span class="material-symbols-outlined text-[18px] text-on-surface-variant">print</span>
                <span>Print Summary</span>
            </button>

            <div class="relative inline-block text-left">
                <button type="button" id="exportDropdownBtn" class="flex items-center gap-space-xs px-space-md py-2 bg-primary hover:bg-primary-container text-on-primary font-label-md text-label-md rounded-lg shadow-sm transition-colors">
                    <span class="material-symbols-outlined text-[18px]">download</span>
                    <span>Export PDF/Excel</span>
                    <span class="material-symbols-outlined text-[16px]">expand_more</span>
                </button>
                <div id="exportDropdownMenu" class="hidden absolute right-0 mt-1 w-56 bg-surface-container-lowest rounded-lg shadow-md z-30 py-1">
                    <button type="button" onclick="window.print()" class="w-full text-left px-space-md py-2 font-label-md text-label-md text-on-surface hover:bg-surface-container-high flex items-center gap-space-sm">
                        <span class="material-symbols-outlined text-[18px] text-error">picture_as_pdf</span>
                        <span>Executive Brief (Save as PDF)</span>
                    </button>
                    <a href="{{ route('transactions.export', ['period' => $period]) }}" class="w-full text-left px-space-md py-2 font-label-md text-label-md text-on-surface hover:bg-surface-container-high flex items-center gap-space-sm">
                        <span class="material-symbols-outlined text-[18px] text-secondary">table_view</span>
                        <span>Ledger Summary (Excel/CSV)</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Top metric cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-gutter">
        <!-- Total Members -->
        <a href="{{ route('members.index') }}" class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col justify-between hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between">
                <span class="font-label-sm text-label-sm text-on-surface-variant uppercase tracking-wider font-semibold">Total Members</span>
                <span class="p-1 rounded-md bg-surface-container text-primary"><span class="material-symbols-outlined text-[18px]">group</span></span>
            </div>
            <div class="mt-space-sm">
                <div class="flex items-baseline gap-space-sm">
                    <span class="font-headline-lg text-headline-lg text-on-surface font-bold">{{ number_format($stats['members']['total']) }}</span>
                    <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-xs font-semibold bg-secondary-fixed text-on-secondary-fixed">
                        <span class="material-symbols-outlined text-[12px]">trending_up</span> +{{ $stats['members']['new'] }} this mo
                    </span>
                </div>
                <div class="mt-space-xs bg-surface-container-low px-space-sm py-1 rounded flex items-center justify-between font-label-sm text-label-sm text-on-surface-variant">
                    <span>Active: <strong class="text-on-surface font-semibold">{{ number_format($stats['members']['active']) }}</strong></span>
                    <span class="text-outline">•</span>
                    <span>Inactive: <strong class="text-outline font-semibold">{{ number_format($stats['members']['inactive']) }}</strong></span>
                </div>
            </div>
        </a>

        <!-- Total Share Capital -->
        <a href="{{ route('share.index') }}" class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col justify-between hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between">
                <span class="font-label-sm text-label-sm text-on-surface-variant uppercase tracking-wider font-semibold">Total Share Capital</span>
                <span class="p-1 rounded-md bg-surface-container text-primary"><span class="material-symbols-outlined text-[18px]">pie_chart</span></span>
            </div>
            <div class="mt-space-sm">
                <div class="font-headline-lg text-headline-lg text-on-surface font-bold">@peso($stats['paid_up'])</div>
                <div class="mt-space-xs bg-surface-container-low px-space-sm py-1 rounded flex items-center justify-between font-label-sm text-label-sm text-on-surface-variant">
                    <span>Subscribed: <strong class="text-on-surface font-semibold">@peso($stats['subscribed'])</strong></span>
                    <span class="text-outline">•</span>
                    <span>Paid-Up: <strong class="text-secondary font-semibold">{{ $stats['paid_up_pct'] }}%</strong></span>
                </div>
            </div>
        </a>

        <!-- Total Savings & Deposits -->
        <a href="{{ route('savings.index') }}" class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col justify-between hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between">
                <span class="font-label-sm text-label-sm text-on-surface-variant uppercase tracking-wider font-semibold">Total Savings &amp; Deposits</span>
                <span class="p-1 rounded-md bg-surface-container text-primary"><span class="material-symbols-outlined text-[18px]">account_balance_wallet</span></span>
            </div>
            <div class="mt-space-sm">
                <div class="font-headline-lg text-headline-lg text-on-surface font-bold">@peso($stats['savings'])</div>
                <div class="mt-space-xs bg-surface-container-low px-space-sm py-1 rounded font-label-sm text-label-sm text-on-surface-variant">
                    Members' savings balance (all accounts)
                </div>
            </div>
        </a>

        <!-- Cash Balance -->
        <a href="{{ route('transactions.index') }}" class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col justify-between hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between">
                <span class="font-label-sm text-label-sm text-on-surface-variant uppercase tracking-wider font-semibold">Cash Balance</span>
                <span class="p-1 rounded-md bg-surface-container text-primary"><span class="material-symbols-outlined text-[18px]">payments</span></span>
            </div>
            <div class="mt-space-sm">
                <div class="font-headline-lg text-headline-lg text-on-surface font-bold">@peso($stats['cash'])</div>
                <div class="mt-space-xs bg-surface-container-low px-space-sm py-1 rounded flex items-center justify-between font-label-sm text-label-sm text-on-surface-variant truncate">
                    <span>In: <strong class="text-secondary font-semibold">@peso($stats['period_in'])</strong></span>
                    <span class="text-outline">•</span>
                    <span>Out: <strong class="text-on-surface font-semibold">@peso($stats['period_out'])</strong></span>
                </div>
            </div>
        </a>
    </div>

    <!-- Loan cards -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-gutter">
        <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-space-xs">
                    <span class="material-symbols-outlined text-primary text-[20px]">assignment_turned_in</span>
                    <span class="font-label-sm text-label-sm text-on-surface-variant uppercase tracking-wider font-semibold">Total Loan Releases (YTD)</span>
                </div>
                <span class="px-2 py-0.5 rounded text-xs font-semibold bg-surface-container text-primary">Cumulative</span>
            </div>
            <div class="mt-space-md flex items-end justify-between">
                <div>
                    <div class="font-headline-lg text-headline-lg text-on-surface font-bold">@peso($stats['releases_ytd'])</div>
                    <div class="font-body-sm text-body-sm text-on-surface-variant mt-0.5">Disbursed this year</div>
                </div>
                <a href="{{ route('loans.index', ['status' => 'released']) }}" class="font-label-md text-label-md text-primary font-semibold bg-surface-container-high px-2 py-1 rounded">{{ $stats['active_loans'] }} Loans Active</a>
            </div>
        </div>

        <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-space-xs">
                    <span class="material-symbols-outlined text-secondary text-[20px]">verified</span>
                    <span class="font-label-sm text-label-sm text-on-surface-variant uppercase tracking-wider font-semibold">Loan Collections ({{ $periodLabel }})</span>
                </div>
                <span class="px-2 py-0.5 rounded text-xs font-semibold {{ $stats['efficiency'] >= $stats['target'] ? 'bg-secondary-fixed text-on-secondary-fixed' : 'bg-error-container text-on-error-container' }} font-mono">{{ $stats['efficiency'] }}% Efficiency</span>
            </div>
            <div class="mt-space-md flex items-end justify-between">
                <div>
                    <div class="font-headline-lg text-headline-lg text-secondary font-bold">@peso($stats['collections'])</div>
                    <div class="font-body-sm text-body-sm text-on-surface-variant mt-0.5">Loan payments received vs. expected amortization</div>
                </div>
                <span class="font-label-sm text-label-sm text-outline">Target: {{ $stats['target'] }}%</span>
            </div>
        </div>

        <div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-space-xs">
                    <span class="material-symbols-outlined text-error text-[20px]">warning</span>
                    <span class="font-label-sm text-label-sm text-on-surface-variant uppercase tracking-wider font-semibold">Past Due Loans (PAR)</span>
                </div>
                <span class="px-2 py-0.5 rounded text-xs font-semibold {{ $stats['par_ok'] ? 'bg-secondary-fixed text-on-secondary-fixed' : 'bg-error-container text-on-error-container' }}">
                    {{ $stats['par_ok'] ? 'Healthy' : 'Attention' }} (&lt; {{ $stats['par_limit'] }}%)
                </span>
            </div>
            <div class="mt-space-md flex items-end justify-between">
                <div>
                    <div class="font-headline-lg text-headline-lg text-on-surface font-bold">@peso($stats['past_due'])</div>
                    <div class="font-body-sm text-body-sm text-on-surface-variant mt-0.5">PAR Ratio: <strong class="text-on-surface font-semibold">{{ $stats['par_ratio'] }}%</strong></div>
                </div>
                <a href="{{ route('loans.index', ['status' => 'pastdue']) }}" class="inline-flex items-center gap-1 font-label-sm text-label-sm text-primary bg-surface-container px-2 py-1 rounded">
                    <span class="material-symbols-outlined text-[14px]">visibility</span> View past due
                </a>
            </div>
        </div>
    </div>

    <!-- Recent transactions -->
    <div class="bg-surface-container-lowest rounded-xl shadow-sm flex flex-col">
        <div class="p-space-md flex flex-col md:flex-row md:items-center justify-between gap-space-md rounded-t-xl">
            <div>
                <div class="flex items-center gap-space-xs">
                    <span class="material-symbols-outlined text-primary text-[20px]">receipt_long</span>
                    <h2 class="font-headline-sm text-headline-sm text-on-surface">Recent Financial Transactions</h2>
                </div>
                <p class="font-body-sm text-body-sm text-on-surface-variant">Latest teller &amp; journal entries across all member accounts</p>
            </div>

            <div class="flex flex-wrap items-center gap-space-sm no-print">
                <div class="relative min-w-[240px]">
                    <span class="material-symbols-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-[18px] text-outline">search</span>
                    <input aria-label="Search transactions" type="text" id="tableSearchInput" placeholder="Search reference, member, or ID..."
                           class="w-full pl-8 pr-3 py-1.5 bg-surface-container-low text-on-surface font-body-sm text-body-sm rounded-lg focus:outline-none focus:bg-surface-container-lowest shadow-sm placeholder:text-outline">
                </div>
                <div class="relative">
                    <select aria-label="Filter by transaction type" id="typeFilterSelect" class="bg-surface-container-low text-on-surface font-label-md text-label-md py-1.5 pl-3 pr-8 rounded-lg focus:outline-none cursor-pointer shadow-sm appearance-none">
                        <option value="All">All Categories</option>
                        @foreach (\App\Models\Transaction::TYPES as $t)
                            <option value="{{ $t[0] }}">{{ $t[0] }}</option>
                        @endforeach
                    </select>
                    <span class="material-symbols-outlined absolute right-2 top-1/2 -translate-y-1/2 text-[16px] text-outline pointer-events-none">expand_more</span>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto w-full">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Reference No.</th><th>Date &amp; Time</th><th>Member Name &amp; ID</th><th>Transaction Type</th>
                        <th class="num">Amount</th><th>Handled By</th><th>Status</th><th class="text-center no-print">Action</th>
                    </tr>
                </thead>
                <tbody id="transactionTableBody">
                    @forelse ($recent as $t)
                        <tr>
                            <td class="font-mono text-[12px] font-semibold text-primary">{{ $t->reference_no }}</td>
                            <td class="text-on-surface-variant">{{ $t->posted_at->format('M d, Y h:i A') }}</td>
                            <td>
                                <div class="font-medium text-on-surface">{{ $t->member?->full_name ?? '—' }}</div>
                                <div class="text-xs text-outline">{{ $t->member?->member_no }}</div>
                            </td>
                            <td><span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold {{ $t->badge() }}">{{ $t->label() }}</span></td>
                            <td class="num font-bold {{ $t->direction === 'in' ? 'text-secondary' : 'text-on-surface' }} {{ $t->status === 'voided' ? 'line-through opacity-60' : '' }}">
                                {{ $t->direction === 'in' ? '+' : '-' }}@peso($t->amount)
                            </td>
                            <td>
                                <div class="text-on-surface">{{ $t->user?->name ?? 'System' }}</div>
                                <div class="text-xs text-outline">{{ $t->user?->roleLabel() }}</div>
                            </td>
                            <td>
                                <span class="inline-flex items-center gap-1 font-label-sm text-label-sm {{ $t->status === 'completed' ? 'text-secondary' : 'text-error' }} font-medium">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $t->status === 'completed' ? 'bg-secondary' : 'bg-error' }}"></span>{{ ucfirst($t->status) }}
                                </span>
                            </td>
                            <td class="text-center no-print">
                                <a href="{{ route('transactions.show', $t) }}" title="View Transaction" class="inline-block p-1 rounded hover:bg-surface-container-highest text-primary transition-colors">
                                    <span class="material-symbols-outlined text-[18px]">visibility</span>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-on-surface-variant py-8">No transactions yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-space-md bg-surface-container-low rounded-b-xl flex flex-col sm:flex-row items-center justify-between text-on-surface-variant font-body-sm text-body-sm gap-space-sm">
            <span>Showing <strong class="text-on-surface font-semibold">{{ $recent->count() }}</strong> latest · <strong class="text-on-surface font-semibold">{{ $todayCount }}</strong> posted today</span>
            <a href="{{ route('transactions.index') }}" class="text-primary font-label-md text-label-md hover:underline font-semibold flex items-center gap-1 no-print">
                <span>View Full Ledger</span>
                <span class="material-symbols-outlined text-[16px]">arrow_forward</span>
            </a>
        </div>
    </div>

    <!-- Notice banner -->
    <div class="bg-surface-container-lowest p-space-md rounded-xl shadow-sm flex flex-col md:flex-row items-start md:items-center justify-between gap-space-md">
        <div class="flex items-center gap-space-sm">
            <span class="p-2 rounded-lg bg-surface-container text-primary"><span class="material-symbols-outlined text-[20px]">campaign</span></span>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 font-body-md text-body-md text-on-surface">
                @if ($boardMeeting)
                    <span class="font-semibold text-primary flex items-center gap-1">
                        <span class="material-symbols-outlined text-[16px]">event</span>
                        Next Board Meeting: {{ \Illuminate\Support\Carbon::parse($boardMeeting)->format('M d, Y') }}
                    </span>
                    @if ($boardNote)
                        <span class="text-outline hidden md:inline">|</span>
                        <span class="text-on-surface-variant">{{ $boardNote }}</span>
                    @endif
                @else
                    <span class="text-on-surface-variant">No board meeting scheduled. Set one in Settings.</span>
                @endif
            </div>
        </div>
        <div class="flex items-center gap-space-xs text-outline font-label-sm text-label-sm self-end md:self-auto">
            <span>System Sync: OK</span>
            <span class="w-1.5 h-1.5 rounded-full bg-secondary"></span>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/dashboard.js') }}"></script>
@endpush
