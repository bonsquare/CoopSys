@php
    $tabs = [
        ['Overview', 'accounting.index', ['accounting.index']],
        ['Journal', 'accounting.journal.index', ['accounting.journal.*']],
        ['Chart of Accounts', 'accounting.accounts.index', ['accounting.accounts.*']],
        ['General Ledger', 'accounting.ledger', ['accounting.ledger']],
        ['Trial Balance', 'accounting.trial', ['accounting.trial']],
        ['Income Statement', 'accounting.income', ['accounting.income']],
        ['Balance Sheet', 'accounting.balance', ['accounting.balance']],
        ['Cash Flow', 'accounting.cashflow', ['accounting.cashflow']],
        ['Reports', 'accounting.reports', ['accounting.reports', 'accounting.equity', 'accounting.highlights', 'accounting.aging', 'accounting.schedules', 'accounting.daybook', 'accounting.cashbook']],
        ['Closing', 'accounting.closing', ['accounting.closing']],
    ];
@endphp
<nav class="no-print flex flex-wrap gap-1 border-b border-outline-variant -mt-2" aria-label="Accounting">
    @foreach ($tabs as [$label, $routeName, $patterns])
        @php $active = request()->routeIs(...$patterns); @endphp
        <a href="{{ route($routeName) }}"
           class="px-space-md py-2 font-label-md text-label-md border-b-2 {{ $active ? 'border-primary text-primary font-semibold' : 'border-transparent text-on-surface-variant hover:text-on-surface' }}">{{ $label }}</a>
    @endforeach
</nav>
