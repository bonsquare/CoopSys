@extends('layouts.app')

@section('title', 'Settings')

@section('content')
<x-page-header title="Settings" subtitle="Cooperative information and business rules." />

<x-card class="max-w-3xl" title="Your organization" subtitle="Everyone on your team needs the Organization ID to sign in.">
    <div class="p-space-md grid grid-cols-2 md:grid-cols-4 gap-4">
        <div><div class="field-label">Organization ID</div><div class="font-mono font-bold text-primary text-lg">{{ $organization->code }}</div></div>
        <div><div class="field-label">Plan</div><div class="font-semibold">{{ config("coop.plans.{$organization->plan}.label", ucfirst($organization->plan)) }}</div></div>
        <div><div class="field-label">Status</div><div class="font-semibold">{{ ucfirst($organization->status) }}</div></div>
        <div><div class="field-label">{{ $organization->onTrial() ? 'Trial ends' : 'Member since' }}</div><div class="font-semibold">{{ ($organization->onTrial() ? $organization->trial_ends_at : $organization->created_at)?->format('M d, Y') }}</div></div>
    </div>
</x-card>

<x-card class="max-w-3xl">
    <form method="POST" action="{{ route('settings.update') }}" class="p-space-md grid grid-cols-1 md:grid-cols-2 gap-4">
        @csrf @method('PUT')
        <x-field label="Cooperative name" name="coop_name" :value="$values['coop_name']" required />
        <x-field label="Branch name" name="branch_name" :value="$values['branch_name']" required />
        <x-field label="Default loan interest (% per year)" name="default_interest" type="number" step="0.01" min="0" max="100" :value="$values['default_interest']" required />
        <x-field label="PAR warning level (%)" name="par_threshold" type="number" step="0.01" min="0" max="100" :value="$values['par_threshold']" required hint="Dashboard shows 'Healthy' while past-due loans are below this share of the portfolio." />
        <x-field label="Collection efficiency target (%)" name="collection_target" type="number" step="0.01" min="0" max="200" :value="$values['collection_target']" required />
        <x-field label="Minimum savings balance (₱)" name="min_balance" type="number" step="0.01" min="0" :value="$values['min_balance']" required hint="A withdrawal cannot leave an account below this amount. Use 0 for no minimum." />
        <x-select label="Share capital withdrawal" name="share_withdrawal_policy" :value="$values['share_withdrawal_policy']" :options="config('collections.share_withdrawal_policies')" required
                  hint="Organization policy for taking share capital out through a collection receipt." />

        <div class="md:col-span-2 border-t border-outline-variant pt-3 mt-1">
            <div class="font-headline-sm text-headline-sm">Payment collection: allocation priority</div>
            <p class="field-hint">When the teller clicks Auto Allocate, a payment is used up in this order.</p>
        </div>
        <x-select label="Priority" name="allocation_preset" :value="$values['allocation_preset']" :options="collect(config('collections.priority_presets'))->map(fn ($p) => $p['label'])->all()" required id="allocation_preset" />
        <div class="md:col-span-2 grid grid-cols-2 md:grid-cols-6 gap-2" id="custom-order">
            @php $customOrder = array_pad(array_filter(explode(',', (string) $values['allocation_custom'])), 6, ''); @endphp
            @foreach (range(0, 5) as $i)
                <div>
                    <label class="field-label">{{ $i + 1 }}.</label>
                    <select name="allocation_custom[]" class="input">
                        @foreach (config('collections.priority_items') as $key => $text)
                            <option value="{{ $key }}" @selected(($customOrder[$i] ?? array_keys(config('collections.priority_items'))[$i]) === $key)>{{ $text }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
            <p class="field-hint col-span-2 md:col-span-6">Used only when the priority is "Custom order".</p>
        </div>
        <x-field label="Regular savings per collection (₱)" name="regular_savings" type="number" step="0.01" min="0" :value="$values['regular_savings']" required hint="Optional. Auto Allocate sets this much aside for savings when 'Savings' comes up in the priority. Use 0 to skip." />
        <x-field label="Regular share capital per collection (₱)" name="regular_share" type="number" step="0.01" min="0" :value="$values['regular_share']" required hint="Optional. Same idea for share capital. Use 0 to skip." />

        <x-field label="Next board meeting" name="board_meeting" type="date" :value="$values['board_meeting']" />
        <x-field label="Board meeting note" name="board_note" :value="$values['board_note']" class="md:col-span-2" />
        <div class="md:col-span-2"><button class="btn btn-primary">Save settings</button></div>
    </form>
</x-card>

<x-card title="Audit Trail" subtitle="Latest 30 actions in the system">
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>When</th><th>User</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="whitespace-nowrap">{{ $log->created_at->format('M d, Y h:i A') }}</td>
                        <td>{{ $log->user?->name ?? 'System' }}</td>
                        <td class="font-mono text-[12px]">{{ $log->action }}</td>
                        <td>{{ $log->description }}</td>
                        <td class="text-outline">{{ $log->ip_address }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-on-surface-variant py-6">No activity yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-card>
@endsection
