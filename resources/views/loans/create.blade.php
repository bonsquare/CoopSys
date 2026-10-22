@extends('layouts.app')

@section('title', 'New Loan')

@section('content')
<x-page-header title="New Loan Application" subtitle="The loan product decides the interest, deductions, limits and payment schedule.">
    <a href="{{ route('loans.index') }}" class="btn btn-secondary">Cancel</a>
</x-page-header>

@if ($products->isEmpty())
    <x-card class="p-space-md max-w-xl">
        <p class="text-sm">There are no active loan products yet. @allowed('loan_products', 'manage')<a class="text-primary font-semibold" href="{{ route('loan-products.create') }}">Add one first.</a>@endallowed</p>
    </x-card>
@else
<div class="grid grid-cols-1 lg:grid-cols-3 gap-gutter items-start">
    <x-card class="lg:col-span-2">
        <form method="POST" action="{{ route('loans.store') }}" class="p-space-md grid grid-cols-1 md:grid-cols-2 gap-4">
            @csrf
            <x-select label="Member" name="member_id" :options="$options" placeholder="Choose member…" required class="md:col-span-2" />

            <div class="md:col-span-2">
                <label class="field-label" for="loan_product_id">Loan product <span class="text-error">*</span></label>
                <select id="loan_product_id" name="loan_product_id" required class="input">
                    <option value="">Choose loan product…</option>
                    @foreach ($products as $p)
                        <option value="{{ $p->id }}"
                                data-min="{{ (float) $p->min_amount }}" data-max="{{ (float) $p->max_amount }}"
                                data-min-term="{{ $p->min_term }}" data-max-term="{{ $p->max_term }}"
                                data-unit="{{ strtolower(\App\Models\LoanProduct::label('term_units', $p->term_unit)) }}"
                                data-frequency="{{ strtolower(\App\Models\LoanProduct::label('frequencies', $p->payment_frequency)) }}"
                                @selected((string) old('loan_product_id') === (string) $p->id)>{{ $p->name }} — {{ $p->rateText() }}</option>
                    @endforeach
                </select>
                @error('loan_product_id') <p class="field-error">{{ $message }}</p> @enderror
                <p class="field-hint" id="product-hint">Pick a product to see its limits.</p>
            </div>

            <x-field label="Loan amount (₱)" name="principal" type="number" step="0.01" min="100" required />
            <x-field label="Term" name="term" type="number" step="1" min="1" required hint="Counted in the unit of the chosen product." />
            <x-field label="Purpose" name="purpose" class="md:col-span-2" />
            <div class="md:col-span-2"><button class="btn btn-primary">Submit application</button></div>
        </form>
    </x-card>

    <x-card title="Computation Summary" subtitle="Updates as you type">
        <dl class="p-space-md pt-0 grid grid-cols-2 gap-y-2 text-sm" id="estimate">
            <dt>Approved Loan Amount</dt><dd class="text-right font-semibold" data-key="approved_amount">—</dd>
            <dt>Less: Total Deductions</dt><dd class="text-right" data-key="total_deductions">—</dd>
            <dt class="font-bold">Net Proceeds</dt><dd class="text-right font-bold" data-key="net_proceeds">—</dd>
            <dt>Add: Interest</dt><dd class="text-right" data-key="interest">—</dd>
            <dt>Add: Fees Added to Payable</dt><dd class="text-right" data-key="fees_payable">—</dd>
            <dt class="font-bold">Total Amount Payable</dt><dd class="text-right font-bold" data-key="total_payable">—</dd>
            <dt>Payment Per Schedule</dt><dd class="text-right font-bold text-primary" data-key="payment_amount">—</dd>
        </dl>
        <p class="px-space-md pb-space-md text-xs text-outline" id="estimate-note"></p>
    </x-card>
</div>
@endif
@endsection

@push('scripts')
<script>
    // Live computation summary: asks the server, so it always matches what will be saved
    (function () {
        const product = document.getElementById('loan_product_id');
        const amount = document.getElementById('principal');
        const term = document.getElementById('term');
        if (!product || !amount || !term) return;

        const urlTemplate = @json(route('loan-products.compute', ['loanProduct' => '__ID__']));
        const peso = (n) => '₱' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        let timer;

        function showHint() {
            const o = product.options[product.selectedIndex];
            document.getElementById('product-hint').textContent = o.value
                ? 'Amount ' + peso(o.dataset.min) + ' to ' + peso(o.dataset.max) + ' · Term ' + o.dataset.minTerm + ' to ' + o.dataset.maxTerm + ' ' + o.dataset.unit
                : 'Pick a product to see its limits.';
            if (o.value) { amount.min = o.dataset.min; amount.max = o.dataset.max; term.min = o.dataset.minTerm; term.max = o.dataset.maxTerm; }
        }

        async function refresh() {
            showHint();
            const o = product.options[product.selectedIndex];
            const note = document.getElementById('estimate-note');
            if (!o.value || !(parseFloat(amount.value) > 0) || !(parseInt(term.value) > 0)) { note.textContent = ''; return; }

            const res = await fetch(urlTemplate.replace('__ID__', o.value) + '?amount=' + encodeURIComponent(amount.value) + '&term=' + encodeURIComponent(term.value), { headers: { 'Accept': 'application/json' } });
            if (!res.ok) return;
            const d = await res.json();
            document.querySelectorAll('#estimate [data-key]').forEach((el) => { el.textContent = peso(d[el.dataset.key]); });
            note.textContent = d.payments + ' ' + o.dataset.frequency + ' payment(s)' + (d.payments_vary ? '; the first payment is ' + peso(d.first_payment) : '');
        }

        function schedule() { clearTimeout(timer); timer = setTimeout(refresh, 250); }
        [product, amount, term].forEach((el) => el.addEventListener('input', schedule));
        showHint();
        schedule();
    })();
</script>
@endpush
