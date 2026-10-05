@extends('layouts.app')

@section('title', 'Financial Highlights')

@php
    // Chart geometry: one pair of bars (income, expenses) per month
    $max = max(1, collect($trend)->max(fn ($m) => max($m['income'], $m['expenses'])));
    $w = 760; $h = 220; $padL = 8; $padB = 26; $slot = ($w - $padL) / max(count($trend), 1); $barW = $slot * 0.32;
    $y = fn ($v) => $h - $padB - ($v / $max) * ($h - $padB - 14);
    $fmtValue = fn ($r) => $r['value'] === null ? '—' : ($r['kind'] === 'amount' ? '₱' . number_format($r['value'], 2) : $r['value'] . '%');
@endphp

@section('content')
<x-page-header title="Financial Highlights" subtitle="Key figures for management, and how income and expenses have moved over the last 12 months.">
    <button type="button" onclick="window.print()" class="btn btn-secondary"><span class="material-symbols-outlined text-[18px]">print</span> Print</button>
</x-page-header>

@include('accounting._nav')
@include('accounting._print-header', ['title' => 'Financial Highlights', 'subtitle' => 'As of ' . now()->format('M d, Y')])

<x-card title="Income, Expenses and Surplus" subtitle="Last 12 months">
    <div class="p-space-md pt-0 overflow-x-auto">
        <svg viewBox="0 0 {{ $w }} {{ $h }}" class="w-full min-w-[560px]" role="img" aria-label="Monthly income and expenses">
            <line x1="{{ $padL }}" y1="{{ $h - $padB }}" x2="{{ $w }}" y2="{{ $h - $padB }}" stroke="#c5c5d3" />
            @foreach ($trend as $i => $m)
                @php $x = $padL + $i * $slot + $slot * 0.14; @endphp
                <rect x="{{ $x }}" y="{{ $y($m['income']) }}" width="{{ $barW }}" height="{{ max(($h - $padB) - $y($m['income']), 0) }}" fill="#006e2d"><title>{{ $m['month'] }} income: {{ number_format($m['income'], 2) }}</title></rect>
                <rect x="{{ $x + $barW + 2 }}" y="{{ $y($m['expenses']) }}" width="{{ $barW }}" height="{{ max(($h - $padB) - $y($m['expenses']), 0) }}" fill="#ba1a1a"><title>{{ $m['month'] }} expenses: {{ number_format($m['expenses'], 2) }}</title></rect>
                <text x="{{ $x + $barW }}" y="{{ $h - 8 }}" text-anchor="middle" font-size="10" fill="#444651">{{ $m['short'] }}</text>
            @endforeach
        </svg>
        <div class="flex gap-4 text-[12px] mt-1">
            <span><span class="inline-block w-3 h-3 align-middle" style="background:#006e2d"></span> Income</span>
            <span><span class="inline-block w-3 h-3 align-middle" style="background:#ba1a1a"></span> Expenses</span>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Month</th><th class="num">Income</th><th class="num">Expenses</th><th class="num">Net surplus</th></tr></thead>
            <tbody>
                @foreach (array_reverse($trend) as $m)
                    <tr>
                        <td>{{ $m['month'] }}</td>
                        <td class="num">@peso($m['income'])</td>
                        <td class="num">@peso($m['expenses'])</td>
                        <td class="num font-semibold {{ $m['net'] < 0 ? 'text-error' : '' }}">@acct($m['net'])</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-card>

@foreach ($ratios as $group => $items)
    <div>
        <h2 class="font-headline-sm text-headline-sm mb-2">{{ $group }}</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-gutter">
            @foreach ($items as $r)
                <x-card class="p-space-md">
                    <div class="field-label">{{ $r['label'] }}</div>
                    <div class="font-headline-lg text-headline-lg font-bold">{{ $fmtValue($r) }}</div>
                    <div class="text-[12px] text-on-surface-variant mt-1">{{ $r['note'] }}</div>
                </x-card>
            @endforeach
        </div>
    </div>
@endforeach
<p class="text-[11px] text-outline">Earnings figures are for {{ $year }} so far. On the cash basis, income is recorded when it is collected.</p>
@endsection
