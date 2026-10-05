@extends('layouts.app')

@section('title', 'Year-end Closing')

@section('content')
<x-page-header title="Year-end Closing" subtitle="Move a finished year's surplus into Retained Surplus and lock the books so the year can no longer change." />

@include('accounting._nav')

<div class="px-space-md py-space-sm rounded-lg font-label-md text-label-md font-semibold {{ $lockedUntil ? 'bg-surface-container-high text-primary' : 'bg-surface-container text-on-surface-variant' }}">
    @if ($lockedUntil)
        <span class="material-symbols-outlined text-[16px] align-middle">lock</span> The books are locked up to {{ $lockedUntil->format('F d, Y') }}. Nothing can be posted on or before that date.
    @else
        <span class="material-symbols-outlined text-[16px] align-middle">lock_open</span> No year has been closed. The books are open for every date.
    @endif
</div>

<x-card title="Fiscal Years" subtitle="A year can be closed once it has ended, and after the years before it.">
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Year</th><th class="num">Net surplus / (deficit)</th><th>Status</th><th>Closing entry</th><th class="no-print"></th></tr></thead>
            <tbody>
                @forelse ($years as $y)
                    <tr>
                        <td class="font-semibold">{{ $y['year'] }}</td>
                        <td class="num font-semibold {{ $y['net'] < 0 ? 'text-error' : '' }}">@acct($y['net'])</td>
                        <td>
                            @if ($y['closed']) <span class="inline-flex px-2 py-0.5 rounded text-[11px] font-semibold bg-secondary-fixed text-on-secondary-fixed">Closed</span>
                            @else <span class="inline-flex px-2 py-0.5 rounded text-[11px] font-semibold bg-surface-container text-on-surface-variant">Open</span> @endif
                        </td>
                        <td>@if ($y['closing']) <a href="{{ route('accounting.journal.show', $y['closing']) }}" class="font-mono text-[12px] text-primary">{{ $y['closing']->entry_no }}</a> @else — @endif</td>
                        <td class="no-print whitespace-nowrap">
                            @if ($y['can_close'])
                                <a href="{{ route('accounting.closing', ['preview' => $y['year']]) }}" class="btn btn-secondary btn-sm">Preview</a>
                            @endif
                            @if ($y['closed'] && $latestClosed && $latestClosed['year'] === $y['year'])
                                @allowed('accounting', 'close')
                                    <form method="POST" action="{{ route('accounting.closing.reopen', $y['year']) }}" class="inline" onsubmit="return confirm('Reopen {{ $y['year'] }}? The closing entry is reversed and the lock is lifted.')">
                                        @csrf <button class="btn btn-danger btn-sm">Reopen</button>
                                    </form>
                                @endallowed
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-on-surface-variant py-6">There are no entries yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-card>

@if ($preview)
    <x-card :title="'Closing ' . $preview['year']" subtitle="These income and expense accounts will be cleared to zero.">
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr><th>Account</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
                <tbody>
                    @forelse ($preview['lines'] as $l)
                        <tr>
                            <td>{{ $l['account']->label() }}</td>
                            <td class="num">{{ $l['debit'] > 0 ? '₱' . number_format($l['debit'], 2) : '' }}</td>
                            <td class="num">{{ $l['credit'] > 0 ? '₱' . number_format($l['credit'], 2) : '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-on-surface-variant">There is nothing to close in this year.</td></tr>
                    @endforelse
                    <tr class="font-bold bg-surface-container-low">
                        <td>3020 · Retained Surplus <span class="font-normal text-[11px] text-outline">({{ $preview['net'] >= 0 ? 'surplus added' : 'deficit deducted' }})</span></td>
                        <td class="num">{{ $preview['net'] < 0 ? '₱' . number_format(-$preview['net'], 2) : '' }}</td>
                        <td class="num">{{ $preview['net'] > 0 ? '₱' . number_format($preview['net'], 2) : '' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        @allowed('accounting', 'close')
            <form method="POST" action="{{ route('accounting.closing.close', $preview['year']) }}" class="p-space-md flex gap-2 no-print"
                  onsubmit="return confirm('Close {{ $preview['year'] }}? The books will be locked up to Dec 31, {{ $preview['year'] }}.')">
                @csrf
                <button class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">lock</span> Close {{ $preview['year'] }} and lock the books</button>
                <a href="{{ route('accounting.closing') }}" class="btn btn-secondary">Cancel</a>
            </form>
        @endallowed
    </x-card>
@endif

<p class="text-[12px] text-on-surface-variant">After closing, the income statement for that year still shows its income and expenses (closing entries are left out of it). The balance sheet shows the surplus in Retained Surplus. Reopening reverses the closing entry and removes the lock.</p>
@endsection
