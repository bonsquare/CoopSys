@props(['title' => null, 'subtitle' => null])

<div {{ $attributes->merge(['class' => 'bg-surface-container-lowest rounded-xl shadow-sm']) }}>
    @if ($title)
        <div class="px-space-md pt-space-md pb-space-sm">
            <h2 class="font-headline-sm text-headline-sm text-on-surface">{{ $title }}</h2>
            @if ($subtitle) <p class="font-body-sm text-body-sm text-on-surface-variant">{{ $subtitle }}</p> @endif
        </div>
    @endif
    {{ $slot }}
</div>
