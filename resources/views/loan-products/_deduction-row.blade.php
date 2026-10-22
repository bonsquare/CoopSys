@php
    $yesNo = ['1' => 'Yes', '0' => 'No'];
    $get = fn ($key, $default = null) => $d[$key] ?? $default;
@endphp
<div class="deduction-row border border-outline-variant rounded-lg p-space-md bg-surface-container-low" data-row>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 items-start">
        <div class="col-span-2 md:col-span-1">
            <label class="field-label">Deduction name</label>
            <input aria-label="Deduction name" name="deductions[{{ $i }}][name]" value="{{ $get('name') }}" list="deduction-names" required class="input" placeholder="e.g. Service Fee">
            @error("deductions.$i.name") <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="field-label">Type</label>
            <select aria-label="Deduction type" name="deductions[{{ $i }}][type]" class="input" data-deduction-type>
                @foreach (config('loan_products.deduction_types') as $key => $text)
                    <option value="{{ $key }}" @selected($get('type', 'fixed') === $key)>{{ $text }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="field-label">Value</label>
            <input aria-label="Deduction value" type="number" step="0.01" min="0" name="deductions[{{ $i }}][value]" value="{{ $get('value', 0) }}" class="input" data-deduction-value>
            @error("deductions.$i.value") <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="field-label">Basis</label>
            <select aria-label="Deduction basis" name="deductions[{{ $i }}][basis]" class="input">
                @foreach (config('loan_products.deduction_bases') as $key => $text)
                    <option value="{{ $key }}" @selected($get('basis', 'loan_amount') === $key)>{{ $text }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-span-2">
            <label class="field-label">Timing</label>
            <select aria-label="Deduction timing" name="deductions[{{ $i }}][timing]" class="input">
                @foreach (config('loan_products.deduction_timings') as $key => $text)
                    <option value="{{ $key }}" @selected($get('timing', 'upon_release') === $key)>{{ $text }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="field-label">Required</label>
            <select aria-label="Required" name="deductions[{{ $i }}][is_required]" class="input">
                @foreach ($yesNo as $key => $text) <option value="{{ $key }}" @selected((string) (int) $get('is_required', true) === (string) $key)>{{ $text }}</option> @endforeach
            </select>
        </div>
        <div>
            <label class="field-label">Editable in application</label>
            <select aria-label="Editable" name="deductions[{{ $i }}][is_editable]" class="input">
                @foreach ($yesNo as $key => $text) <option value="{{ $key }}" @selected((string) (int) $get('is_editable', false) === (string) $key)>{{ $text }}</option> @endforeach
            </select>
        </div>
        <div>
            <label class="field-label">Status</label>
            <select aria-label="Active" name="deductions[{{ $i }}][is_active]" class="input">
                <option value="1" @selected((string) (int) $get('is_active', true) === '1')>Active</option>
                <option value="0" @selected((string) (int) $get('is_active', true) === '0')>Inactive</option>
            </select>
        </div>
        <div class="flex items-end h-full">
            <button type="button" class="btn btn-danger btn-sm" data-remove-row>Remove</button>
        </div>
    </div>
</div>
