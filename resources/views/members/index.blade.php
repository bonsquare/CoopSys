@extends('layouts.app')

@section('title', 'Members')

@section('content')
<x-page-header title="Member Management" subtitle="Register and manage cooperative members.">
    @allowed('members', 'manage')
        <a href="{{ route('members.create') }}" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">person_add</span> Add Member</a>
    @endallowed
</x-page-header>

<x-card>
    <form method="GET" class="p-space-md flex flex-wrap items-end gap-space-sm no-print">
        <div class="min-w-[260px]">
            <label class="field-label" for="q">Search</label>
            <input id="q" name="q" value="{{ $search }}" placeholder="Name, member no., or contact" class="input">
        </div>
        <x-select label="Status" name="status" :value="$status" :options="['active' => 'Active', 'inactive' => 'Inactive']" placeholder="All" />
        <button class="btn btn-secondary">Filter</button>
        @if ($search || $status) <a href="{{ route('members.index') }}" class="btn btn-secondary">Clear</a> @endif
    </form>

    <div class="overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr><th>Member No.</th><th>Name</th><th>Contact</th><th>Date Joined</th><th class="num">Subscribed</th><th>Status</th><th class="text-center no-print">Action</th></tr>
            </thead>
            <tbody>
                @forelse ($members as $m)
                    <tr>
                        <td class="font-mono text-[12px] font-semibold text-primary">{{ $m->member_no }}</td>
                        <td class="font-medium text-on-surface">{{ $m->last_name }}, {{ $m->first_name }}</td>
                        <td>{{ $m->contact_no ?: '—' }}</td>
                        <td>{{ $m->date_joined->format('M d, Y') }}</td>
                        <td class="num">@peso($m->subscribed_shares)</td>
                        <td>
                            <span class="inline-flex px-2 py-0.5 rounded text-[11px] font-semibold {{ $m->status === 'active' ? 'bg-secondary-fixed text-on-secondary-fixed' : 'bg-surface-container text-outline' }}">{{ ucfirst($m->status) }}</span>
                        </td>
                        <td class="text-center no-print whitespace-nowrap">
                            <a href="{{ route('members.show', $m) }}" class="btn btn-secondary btn-sm">View</a>
                            @allowed('members', 'manage')
                                <a href="{{ route('members.edit', $m) }}" class="btn btn-secondary btn-sm">Edit</a>
                            @endallowed
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-on-surface-variant py-8">No members found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="p-space-md no-print">{{ $members->links() }}</div>
</x-card>
@endsection
