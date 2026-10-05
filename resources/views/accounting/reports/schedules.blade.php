@extends('layouts.app')

@section('title', 'Subsidiary Schedules')

@section('content')
<x-page-header title="Subsidiary Schedules" :subtitle="'As of ' . \Illuminate\Support\Carbon::parse($schedules['as_of'])->format('F d, Y') . '. The member and loan accounts behind the ledger.'">
    <button type="button" onclick="window.print()" class="btn btn-secondary"><span class="material-symbols-outlined text-[18px]">print</span> Print</button>
    <a href="{{ route('accounting.schedules', ['export' => 'csv']) }}" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">download</span> Export Excel/CSV</a>
</x-page-header>

@include('accounting._nav')
@include('accounting._print-header', ['title' => 'Subsidiary Schedules', 'subtitle' => 'As of ' . \Illuminate\Support\Carbon::parse($schedules['as_of'])->format('M d, Y')])

@foreach (['loans', 'savings', 'shares'] as $key)
    @php $s = $schedules[$key]; @endphp
    <x-card :title="$s['title']" :subtitle="'Control account ' . $s['code']">
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr><th>Reference</th><th>Name</th><th>Detail</th><th class="num">Amount</th></tr></thead>
                <tbody>
                    @forelse ($s['rows'] as $r)
                        <tr>
                            <td class="font-mono text-[12px]">{{ $r['ref'] }}</td>
                            <td>{{ $r['name'] }}@if ($r['note'])<div class="text-[11px] text-outline">{{ $r['note'] }}</div>@endif</td>
                            <td>{{ $r['extra'] }}</td>
                            <td class="num">@acct($r['amount'])</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-on-surface-variant py-6">Nothing to list.</td></tr>
                    @endforelse
                    <tr class="font-bold bg-surface-container-low"><td colspan="3" class="text-right">Total of the accounts</td><td class="num">@acct($s['total'])</td></tr>
                    <tr class="font-bold"><td colspan="3" class="text-right">General ledger balance ({{ $s['code'] }})</td><td class="num">@acct($s['control'])</td></tr>
                    <tr class="font-bold {{ $s['agrees'] ? 'text-secondary' : 'text-error' }}">
                        <td colspan="3" class="text-right">Difference</td>
                        <td class="num">@acct($s['difference']) {{ $s['agrees'] ? '✔' : '✖' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </x-card>
@endforeach
@endsection
