@props(['title', 'subtitle' => null])

<div class="flex flex-col md:flex-row md:items-end justify-between gap-space-md">
    <div>
        <h1 class="font-headline-lg text-headline-lg text-on-surface tracking-tight">{{ $title }}</h1>
        @if ($subtitle) <p class="font-body-md text-body-md text-on-surface-variant mt-0.5">{{ $subtitle }}</p> @endif
    </div>
    <div class="flex flex-wrap items-center gap-space-sm no-print">{{ $slot }}</div>
</div>
