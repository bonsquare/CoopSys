@extends('layouts.app')

@section('title', 'Reports')

@section('content')
<x-page-header title="Reports" subtitle="Choose a report, then print it or download it for Excel.">
    <button type="button" onclick="window.print()" class="btn btn-secondary"><span class="material-symbols-outlined text-[18px]">print</span> Print / Save as PDF</button>
    <a href="{{ route('reports.export', ['report' => $report, 'from' => $from, 'to' => $to]) }}" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">download</span> Export Excel/CSV</a>
</x-page-header>

<x-card>
    <form method="GET" class="p-space-md grid grid-cols-2 md:grid-cols-5 gap-3 items-end no-print">
        <x-select label="Report" name="report" :options="$reports" :value="$report" class="col-span-2" />
        <x-field label="From" name="from" type="date" :value="$from" />
        <x-field label="To" name="to" type="date" :value="$to" />
        <button class="btn btn-primary justify-center">Run report</button>
    </form>
</x-card>

<x-card title="Ledgers" subtitle="Open the ledger of one member or client" class="no-print">
    <div class="p-space-md pt-0 flex flex-wrap items-end gap-3" id="ledger-nav">
        <div class="min-w-[260px]">
            <label class="field-label" for="ledger-member">Member / client</label>
            <select id="ledger-member" class="input">
                <option value="">Choose…</option>
                @foreach (\App\Models\Member::orderBy('last_name')->orderBy('first_name')->get() as $m)
                    <option value="{{ $m->id }}">{{ $m->member_no }} — {{ $m->last_name }}, {{ $m->first_name }}</option>
                @endforeach
            </select>
        </div>
        <button type="button" class="btn btn-secondary" data-ledger="{{ url('/members/__ID__/ledger') }}">Member ledger</button>
        <button type="button" class="btn btn-secondary" data-ledger="{{ url('/savings/__ID__/statement') }}">Savings ledger</button>
        <button type="button" class="btn btn-secondary" data-ledger="{{ url('/share-capital/__ID__/ledger') }}">Share capital ledger</button>
        <span class="text-[12px] text-on-surface-variant">The loan ledger is on each loan page.</span>
    </div>
    <script>
        document.querySelectorAll('#ledger-nav [data-ledger]').forEach((button) => button.addEventListener('click', () => {
            const id = document.getElementById('ledger-member').value;
            if (id) window.location = button.dataset.ledger.replace('__ID__', id);
        }));
    </script>
</x-card>

<x-card :title="$data['title']" :subtitle="'Generated ' . now()->format('M d, Y h:i A') . ' · ' . count($data['rows']) . ' rows'">
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr>
                    @foreach ($data['headers'] as $i => $h)
                        <th class="{{ in_array($i, $data['money']) ? 'num' : '' }}">{{ $h }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($data['rows'] as $row)
                    <tr class="{{ (in_array('TOTAL', $row, true) || str_starts_with((string) ($row[0] ?? ''), 'TOTAL')) ? 'font-bold bg-surface-container-low' : '' }}">
                        @foreach ($row as $i => $cell)
                            <td class="{{ in_array($i, $data['money']) ? 'num' : '' }}">{{ in_array($i, $data['money']) && $cell !== '' ? '₱' . $cell : $cell }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($data['headers']) }}" class="text-center text-on-surface-variant py-8">No data for this report.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-card>
@endsection
