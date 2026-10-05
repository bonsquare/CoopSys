@props(['label', 'name', 'options' => [], 'value' => null, 'required' => false, 'placeholder' => null, 'hint' => null])

<div {{ $attributes->only('class') }}>
    <label for="{{ $attributes->get('id', $name) }}" class="field-label">{{ $label }}@if ($required) <span class="text-error">*</span>@endif</label>
    <select id="{{ $attributes->get('id', $name) }}" name="{{ $name }}" @required($required) {{ $attributes->except(['class', 'id']) }} class="input">
        @if ($placeholder !== null) <option value="">{{ $placeholder }}</option> @endif
        @foreach ($options as $key => $text)
            <option value="{{ $key }}" @selected((string) old($name, $value) === (string) $key)>{{ $text }}</option>
        @endforeach
    </select>
    @if ($hint) <p class="field-hint">{{ $hint }}</p> @endif
    @error($name) <p class="field-error">{{ $message }}</p> @enderror
</div>
