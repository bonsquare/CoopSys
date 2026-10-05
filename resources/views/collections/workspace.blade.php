@extends('layouts.app')

@php
    $c = $collection;
    $types = config('collections.types');
    $methods = config('coop.payment_methods');
    $allocations = config('collections.allocations');
    $user = auth()->user();

    // What the screen starts with: a saved receipt, what was typed before an error, or a blank slate
    $initial = [
        'member_id' => old('member_id', $c?->member_id ?? request('member')),
        'type' => old('collection_type', $c?->collection_type ?? request('type', 'loan_payment')),
        'method' => old('payment_method', $c?->payment_method ?? 'cash'),
        'amount' => old('total_amount', $c && $c->total_amount > 0 ? (float) $c->total_amount : ''),
        'cash_tendered' => old('cash_tendered', $c?->cash_tendered),
        'ref' => old('payment_ref', $c?->payment_ref),
        'receipt_no' => old('receipt_no', $c?->receipt_no),
        'remarks' => old('remarks', $c?->remarks),
        'date' => old('collection_date', ($c?->collection_date ?? now())->toDateString()),
        'lines' => old('lines', $c ? $c->lines->map(fn ($l) => [
            'allocation_type' => $l->allocation_type, 'loan_id' => $l->loan_id, 'loan_no' => $l->loan?->loan_no, 'amount' => (float) $l->amount,
            'due_amount' => $l->due_amount, 'balance_after' => $l->balance_after,
        ])->all() : []),
    ];
    $ro = $readonly;
    $tellerConfig = [
        'readonly' => $ro,
        'status' => $c?->status,
        'initial' => $initial,
        'allocations' => $allocations,
        'types' => collect($types)->map(fn ($t) => ['label' => $t['label'], 'row' => $t['row']])->all(),
        'canManage' => $canManage,
        'urls' => [
            'members' => route('collections.members'),
            'overview' => route('collections.overview', ['member' => '__ID__']),
            'suggest' => route('collections.suggest'),
            'ledger' => route('members.ledger', ['member' => '__ID__']),
            'create' => route('collections.create'),
        ],
    ];
    $ro = $readonly;
@endphp

@section('title', 'Collection')

@section('content')
<form method="POST" action="{{ route('collections.store') }}" id="collection-form" class="flex flex-col gap-3" autocomplete="off">
    @csrf
    <input type="hidden" name="collection_id" value="{{ $c?->id }}">
    <input type="hidden" name="member_id" id="member_id" value="{{ $initial['member_id'] }}">

    {{-- Status of a saved receipt --}}
    @if ($c)
        <div class="tl-panel tl-body flex flex-wrap items-center gap-3 {{ $c->status === 'cancelled' ? 'border-error' : '' }}">
            <span class="tl-badge {{ $c->statusColor() }}">{{ strtoupper($c->statusLabel()) }}</span>
            <strong class="font-mono">{{ $c->receipt_no }}</strong>
            @if ($c->status === 'posted')
                <span class="text-sm text-on-surface-variant">Posted {{ $c->posted_at->format('M d, Y h:i A') }} by {{ $c->collector?->name ?? '—' }}. A posted receipt cannot be edited. To correct it, use Reverse Transaction and enter a new one.</span>
            @elseif ($c->status === 'cancelled')
                <span class="text-sm text-error">Cancelled {{ $c->cancelled_at?->format('M d, Y h:i A') }} by {{ $c->canceller?->name ?? '—' }}: {{ $c->cancel_reason }}</span>
            @else
                <span class="text-sm text-on-surface-variant">Draft. Nothing has been posted yet.</span>
            @endif
        </div>
    @endif

    {{-- TOP BAR --}}
    <div class="tl-panel tl-body grid grid-cols-2 md:grid-cols-6 gap-3 items-end">
        <div class="tl-field">
            <label>Branch / Office</label>
            <div class="text-sm font-semibold truncate">{{ \App\Models\Setting::get('branch_name') }}</div>
        </div>
        <div class="tl-field">
            <label>Teller / Cashier</label>
            <div class="text-sm font-semibold truncate">{{ $c?->collector?->name ?? $user->name }}</div>
        </div>
        <div class="tl-field">
            <label for="collection_date">Transaction date</label>
            <input type="date" id="collection_date" name="collection_date" value="{{ $initial['date'] }}" max="{{ now()->toDateString() }}" class="input" required>
            <div class="text-[11px] text-outline" id="clock">{{ now()->format('h:i A') }}</div>
        </div>
        <div class="tl-field">
            <label>Reference no.</label>
            <div class="text-sm font-mono font-bold">{{ $nextReceipt }}</div>
        </div>
        <div class="tl-field col-span-2 relative">
            <label for="member-search">Search member / client</label>
            <input type="search" id="member-search" class="input" placeholder="Name, member ID or contact number" autofocus>
            <div id="member-results" class="hidden"></div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-3 items-start">

        {{-- LEFT: member and balances --}}
        <div class="lg:col-span-2 flex flex-col gap-3">
            <div class="tl-panel">
                <div class="tl-head">Member / Client Information</div>
                <div class="tl-body">
                    <div class="flex gap-3 items-start">
                        <div id="acc-avatar" class="w-14 h-14 rounded-full bg-surface-container-highest text-primary flex items-center justify-center text-lg font-bold shrink-0">?</div>
                        <div class="min-w-0">
                            <div id="acc-name" class="text-lg font-bold leading-tight">No member selected</div>
                            <div class="text-[12px] text-on-surface-variant"><span id="acc-no" class="font-mono">—</span></div>
                            <div class="flex flex-wrap gap-1 mt-1">
                                <span id="acc-status" class="tl-badge bg-surface-container text-on-surface-variant hidden"></span>
                                <span id="acc-flag" class="tl-badge bg-error-container text-on-error-container hidden"></span>
                            </div>
                        </div>
                    </div>
                    <dl class="grid grid-cols-3 gap-y-1 text-[12px] mt-3">
                        <dt class="text-on-surface-variant">Contact</dt><dd class="col-span-2" id="acc-contact">—</dd>
                        <dt class="text-on-surface-variant">Address</dt><dd class="col-span-2" id="acc-address">—</dd>
                        <dt class="text-on-surface-variant">Member since</dt><dd class="col-span-2" id="acc-joined">—</dd>
                    </dl>
                    <a id="acc-ledger" href="#" class="btn btn-secondary btn-sm mt-2 hidden">Open member ledger</a>
                </div>
            </div>

            <div class="tl-panel">
                <div class="tl-head">Loan Account</div>
                <div class="tl-body grid grid-cols-2 gap-2">
                    <div class="tl-bal" id="card-loan"><div class="k">Active loan balance</div><div class="v" id="bal-loan">₱0.00</div></div>
                    <div class="tl-bal" id="card-principal"><div class="k">Loan principal balance</div><div class="v" id="bal-principal">₱0.00</div></div>
                    <div class="tl-bal" id="card-interest"><div class="k">Interest due</div><div class="v" id="bal-interest">₱0.00</div></div>
                    <div class="tl-bal" id="card-penalty"><div class="k">Penalty due</div><div class="v" id="bal-penalty">₱0.00</div></div>
                    <div class="tl-bal col-span-2" id="card-total-due"><div class="k">Total loan due (today)</div><div class="v" id="bal-total-due">₱0.00</div></div>
                </div>
            </div>

            <div class="tl-panel">
                <div class="tl-head">Savings and Share Capital</div>
                <div class="tl-body grid grid-cols-2 gap-2">
                    <div class="tl-bal good"><div class="k">Regular savings balance</div><div class="v" id="bal-savings">₱0.00</div></div>
                    <div class="tl-bal"><div class="k">Available withdrawal balance</div><div class="v" id="bal-available">₱0.00</div></div>
                    <div class="tl-bal good col-span-2"><div class="k">Share capital balance</div><div class="v" id="bal-shares">₱0.00</div></div>
                </div>
            </div>

            <div class="tl-panel">
                <div class="tl-head">Active loan accounts</div>
                <div class="tl-body text-[13px]" id="loan-list"><span class="text-on-surface-variant">Select a member.</span></div>
            </div>
        </div>

        {{-- RIGHT: entry and breakdown --}}
        <div class="lg:col-span-3 flex flex-col gap-3">
            <div class="tl-panel">
                <div class="tl-head">Transaction Entry</div>
                <div class="tl-body grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="tl-field col-span-2">
                        <label for="collection_type">Transaction type</label>
                        <select id="collection_type" name="collection_type" class="input">
                            @foreach ($types as $key => $t) <option value="{{ $key }}" @selected($initial['type'] === $key)>{{ $t['label'] }}</option> @endforeach
                        </select>
                    </div>
                    <div class="tl-field col-span-2">
                        <label for="payment_method">Payment method</label>
                        <select id="payment_method" name="payment_method" class="input">
                            @foreach ($methods as $key => $text) <option value="{{ $key }}" @selected($initial['method'] === $key)>{{ $text }}</option> @endforeach
                        </select>
                    </div>
                    <div class="tl-field col-span-2">
                        <label for="total_amount" id="amount-label">Amount received</label>
                        <input type="number" step="0.01" min="0" id="total_amount" name="total_amount" value="{{ $initial['amount'] }}" class="input tl-big" placeholder="0.00">
                    </div>
                    <div class="tl-field col-span-2" id="tendered-box">
                        <label for="cash_tendered">Cash received from member</label>
                        <input type="number" step="0.01" min="0" id="cash_tendered" name="cash_tendered" value="{{ $initial['cash_tendered'] }}" class="input tl-big" placeholder="same as amount">
                    </div>
                    <div class="tl-field col-span-2 hidden" id="ref-box">
                        <label for="payment_ref">Check / transfer / wallet no.</label>
                        <input id="payment_ref" name="payment_ref" value="{{ $initial['ref'] }}" maxlength="60" class="input">
                    </div>
                    <div class="tl-field col-span-2">
                        <label for="receipt_no">Reference / OR number</label>
                        <input id="receipt_no" name="receipt_no" value="{{ $initial['receipt_no'] }}" maxlength="40" class="input" placeholder="{{ $nextReceipt }} (automatic)">
                    </div>
                    <div class="tl-field col-span-2">
                        <label for="remarks">Remarks</label>
                        <input id="remarks" name="remarks" value="{{ $initial['remarks'] }}" maxlength="255" class="input">
                    </div>
                </div>
            </div>

            <div class="tl-panel">
                <div class="tl-head flex flex-wrap items-center justify-between gap-2">
                    <span>Payment Breakdown</span>
                    <span class="flex flex-wrap gap-1 normal-case tracking-normal" id="breakdown-tools">
                        <button type="button" class="btn btn-primary btn-sm" id="btn-auto">Auto Allocate</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="btn-manual">Manual Allocation</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="btn-add-row">+ Add Allocation Row</button>
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table class="tl-table">
                        <thead>
                            <tr><th style="min-width:170px">Allocation type</th><th style="min-width:150px">Account / loan</th><th class="text-right">Due amount</th><th style="min-width:120px" class="text-right">Amount applied</th><th class="text-right">Balance after</th><th></th></tr>
                        </thead>
                        <tbody id="breakdown-body"></tbody>
                    </table>
                </div>
                <div class="px-3 pb-2 text-[12px]" id="messages"></div>
            </div>
        </div>
    </div>

    {{-- BOTTOM: summary and actions --}}
    <div class="tl-panel">
        <div class="tl-head flex items-center justify-between">
            <span>Transaction Summary</span>
            <span id="alloc-badge" class="tl-badge bg-surface-container text-on-surface-variant normal-case tracking-normal">Nothing to allocate</span>
        </div>
        <div class="tl-body grid grid-cols-1 lg:grid-cols-3 gap-x-6 gap-y-3">
            <div>
                <div class="tl-sum"><span>Total amount received</span><strong id="s-received">₱0.00</strong></div>
                <div class="tl-sum"><span>Total allocated</span><strong id="s-allocated">₱0.00</strong></div>
                <div class="tl-sum"><span>Remaining unallocated</span><strong id="s-remaining">₱0.00</strong></div>
                <div class="tl-sum"><span>Total deductions / charges</span><strong id="s-charges">₱0.00</strong></div>
            </div>
            <div>
                <div class="tl-sum"><span>Net cash in</span><strong id="s-in" class="text-secondary">₱0.00</strong></div>
                <div class="tl-sum"><span>Net cash out</span><strong id="s-out">₱0.00</strong></div>
                <div class="tl-sum"><span>Change due</span><strong id="s-change">₱0.00</strong></div>
            </div>
            <div>
                <div class="tl-sum"><span>New loan balance</span><strong id="s-loan">—</strong></div>
                <div class="tl-sum"><span>New savings balance</span><strong id="s-savings">—</strong></div>
                <div class="tl-sum"><span>New share capital balance</span><strong id="s-shares">—</strong></div>
            </div>
        </div>
        <div class="px-3 pb-3 flex flex-wrap gap-2 border-t border-outline-variant pt-3">
            <a href="{{ route('collections.create') }}" class="btn btn-secondary btn-lg">New Transaction</a>
            <button type="submit" name="action" value="draft" class="btn btn-secondary btn-lg" id="btn-draft">Save as Draft</button>
            <button type="submit" name="action" value="post" class="btn btn-success btn-lg" id="btn-post" disabled>Post Transaction</button>
            @if ($c && ! $c->isDraft())
                <a href="{{ route('collections.receipt', $c) }}" class="btn btn-primary btn-lg" id="btn-print">Print Receipt</a>
            @else
                <button type="button" class="btn btn-primary btn-lg" disabled id="btn-print">Print Receipt</button>
            @endif
            <button type="button" class="btn btn-secondary btn-lg" id="btn-cancel">Cancel</button>
            <button type="button" class="btn btn-danger btn-lg" id="btn-reverse" @disabled(! ($c && $c->status === 'posted' && $canReverse))>Reverse Transaction</button>
        </div>
    </div>
</form>

@if ($c)
    {{-- Cancelling a draft or reversing a posted receipt (needs a reason) --}}
    <form method="POST" action="{{ route('collections.cancel', $c) }}" id="cancel-form" class="hidden">
        @csrf
        <input type="hidden" name="reason" id="cancel-reason">
    </form>
@endif
@endsection

@push('scripts')
<script>window.TELLER = @json($tellerConfig);</script>
<script src="{{ asset('assets/js/collection.js') }}"></script>
@endpush
