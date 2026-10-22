{{-- Shown only on paper: who the statement is for, and what it is --}}
<div class="hidden print:block text-center mb-2">
    <div class="text-lg font-bold">{{ \App\Models\Setting::get('coop_name') }}</div>
    <div class="text-sm">{{ \App\Models\Setting::get('branch_name') }}</div>
    <div class="font-semibold mt-1">{{ $title }}</div>
    @isset($subtitle)<div class="text-sm">{{ $subtitle }}</div>@endisset
    <div class="text-xs text-outline">Printed {{ now()->format('M d, Y h:i A') }} by {{ auth()->user()->name }}</div>
</div>
