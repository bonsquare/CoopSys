@extends('layouts.app')

@section('title', 'Loan Products')

@section('content')
<x-page-header title="Loan Product Management" subtitle="Define the loan types you offer: terms, interest, deductions, penalties and requirements.">
    @allowed('loan_products', 'manage')
        <a href="{{ route('loan-products.create') }}" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">add</span> Add Loan Product</a>
    @endallowed
</x-page-header>

<x-card>
    <form method="GET" class="p-space-md grid grid-cols-2 md:grid-cols-5 gap-3 items-end no-print">
        <div class="col-span-2">
            <label class="field-label" for="q">Search product</label>
            <input id="q" name="q" value="{{ $search }}" placeholder="Name or code" class="input">
        </div>
        <x-select label="Category" name="category" :value="$category" :options="$categories" placeholder="All categories" />
        <x-select label="Status" name="status" :value="$status" :options="['active' => 'Active', 'inactive' => 'Inactive']" placeholder="All" />
        <div class="flex gap-2">
            <button class="btn btn-secondary">Filter</button>
            @if ($search || $category || $status) <a href="{{ route('loan-products.index') }}" class="btn btn-secondary">Clear</a> @endif
        </div>
    </form>

    <div class="overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Product</th><th>Code</th><th>Category</th><th>Interest type</th><th class="num">Rate</th>
                    <th>Term</th><th class="num">Deductions</th><th>Status</th><th class="no-print">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($products as $p)
                    <tr>
                        <td class="font-medium"><a href="{{ route('loan-products.show', $p) }}" class="text-primary">{{ $p->name }}</a></td>
                        <td class="font-mono text-[12px] font-semibold">{{ $p->code }}</td>
                        <td>{{ $p->category ?: '—' }}</td>
                        <td>{{ \App\Models\LoanProduct::label('interest_types', $p->interest_type) }}</td>
                        <td class="num">{{ $p->rateText() }}</td>
                        <td class="whitespace-nowrap">{{ $p->termRange() }}</td>
                        <td class="num">{{ $p->deductions_count }}</td>
                        <td><span class="inline-flex px-2 py-0.5 rounded text-[11px] font-semibold {{ $p->is_active ? 'bg-secondary-fixed text-on-secondary-fixed' : 'bg-surface-container text-outline' }}">{{ $p->is_active ? 'Active' : 'Inactive' }}</span></td>
                        <td class="no-print whitespace-nowrap">
                            <a href="{{ route('loan-products.show', $p) }}" class="btn btn-secondary btn-sm">View</a>
                            @allowed('loan_products', 'manage')
                                <a href="{{ route('loan-products.edit', $p) }}" class="btn btn-secondary btn-sm">Edit</a>
                                <form method="POST" action="{{ route('loan-products.toggle', $p) }}" class="inline" onsubmit="return confirm('{{ $p->is_active ? 'Deactivate' : 'Activate' }} this loan product?')">
                                    @csrf <button class="btn btn-sm {{ $p->is_active ? 'btn-danger' : 'btn-success' }}">{{ $p->is_active ? 'Deactivate' : 'Activate' }}</button>
                                </form>
                                @if ($p->loans_count === 0)
                                    <form method="POST" action="{{ route('loan-products.destroy', $p) }}" class="inline" onsubmit="return confirm('Delete this product permanently?')">
                                        @csrf @method('DELETE') <button class="btn btn-danger btn-sm" title="Only products without loans can be deleted">Delete</button>
                                    </form>
                                @endif
                            @endallowed
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-on-surface-variant py-8">No loan products found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-card>
@endsection
