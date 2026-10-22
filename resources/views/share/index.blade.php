@extends('layouts.app')

@section('title', 'Share Capital')

@section('content')
<x-page-header title="Share Capital" subtitle="Member share subscriptions and paid-up capital.">
    <button type="button" onclick="window.print()" class="btn btn-secondary"><span class="material-symbols-outlined text-[18px]">print</span> Print</button>
</x-page-header>

<div class="grid grid-cols-1 md:grid-cols-3 gap-gutter">
    <x-card class="p-space-md"><div class="field-label">Total Subscribed</div><div class="font-headline-md text-headline-md font-bold">@peso($totalSubscribed)</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Total Paid-up</div><div class="font-headline-md text-headline-md font-bold text-secondary">@peso($totalPaid)</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Unpaid Subscription</div><div class="font-headline-md text-headline-md font-bold">@peso($totalSubscribed - $totalPaid)</div></x-card>
</div>

@allowed('share', 'manage')
<x-card title="Receive Share Payment" subtitle="Posts a cash receipt and updates the accounting books automatically." class="no-print">
    <form method="POST" action="{{ route('share.payment') }}" class="p-space-md pt-0 grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
        @csrf
        <x-select label="Member" name="member_id" :options="$options" placeholder="Choose member…" required class="md:col-span-2" />
        <x-field label="Amount (₱)" name="amount" type="number" step="0.01" min="0.01" required />
        <x-field label="Remarks" name="remarks" />
        <div><button class="btn btn-primary">Receive payment</button></div>
    </form>
</x-card>
@endallowed

<x-card title="Members' Capital">
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Member</th><th class="num">Subscribed</th><th class="num">Paid-up</th><th class="num">Unpaid</th><th>%</th>@allowed('share', 'manage')<th class="no-print">Set subscription</th>@endallowed</tr></thead>
            <tbody>
                @foreach ($members as $m)
                    @php $pct = $m->subscribed_shares > 0 ? min(100, round($m->paid_up / $m->subscribed_shares * 100)) : 0; @endphp
                    <tr>
                        <td><a href="{{ route('members.show', $m) }}" class="font-medium text-primary">{{ $m->last_name }}, {{ $m->first_name }}</a><div class="text-xs text-outline">{{ $m->member_no }}</div></td>
                        <td class="num">@peso($m->subscribed_shares)</td>
                        <td class="num text-secondary font-semibold">@peso($m->paid_up)</td>
                        <td class="num">@peso($m->subscribed_shares - $m->paid_up)</td>
                        <td class="w-32">
                            <div class="h-2 bg-surface-container rounded-full overflow-hidden"><div class="h-2 bg-secondary" style="width: {{ $pct }}%"></div></div>
                            <div class="text-xs text-outline mt-0.5">{{ $pct }}%</div>
                        </td>
                        @allowed('share', 'manage')
                        <td class="no-print">
                            <form method="POST" action="{{ route('share.subscription', $m) }}" class="flex gap-1">
                                @csrf
                                <input aria-label="Subscribed shares for {{ $m->first_name }} {{ $m->last_name }}" type="number" step="0.01" min="0" name="subscribed_shares" value="{{ $m->subscribed_shares }}" class="input w-32 py-1">
                                <button class="btn btn-secondary btn-sm">Save</button>
                            </form>
                        </td>
                        @endallowed
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-card>
@endsection
