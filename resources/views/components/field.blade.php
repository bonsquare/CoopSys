@props(['label', 'name', 'type' => 'text', 'value' => null, 'required' => false, 'hint' => null])

<div {{ $attributes->only('class') }}>
    <label for="{{ $attributes->get('id', $name) }}" class="field-label">{{ $label }}@if ($required) <span class="text-error">*</span>@endif</label>
    <input id="{{ $attributes->get('id', $name) }}" name="{{ $name }}" @if ($errors->has($name)) aria-invalid="true" aria-describedby="{{ $attributes->get('id', $name) }}-error" @elseif ($hint) aria-describedby="{{ $attributes->get('id', $name) }}-hint" @endif type="{{ $type }}"
           @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
           @required($required) {{ $attributes->except(['class', 'id']) }} class="input">
    @if ($hint) <p id="{{ $attributes->get('id', $name) }}-hint" class="field-hint">{{ $hint }}</p> @endif
    @error($name) <p id="{{ $attributes->get('id', $name) }}-error" class="field-error">{{ $message }}</p> @enderror
</div>
