@props(['label', 'name', 'type' => 'text', 'value' => null, 'required' => false, 'hint' => null])

<div {{ $attributes->only('class') }}>
    <label for="{{ $attributes->get('id', $name) }}" class="field-label">{{ $label }}@if ($required) <span class="text-error">*</span>@endif</label>
    <input id="{{ $attributes->get('id', $name) }}" name="{{ $name }}" type="{{ $type }}"
           @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
           @required($required) {{ $attributes->except(['class', 'id']) }} class="input">
    @if ($hint) <p class="field-hint">{{ $hint }}</p> @endif
    @error($name) <p class="field-error">{{ $message }}</p> @enderror
</div>
