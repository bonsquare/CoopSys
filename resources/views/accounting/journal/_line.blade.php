<tr data-line>
    <td>
        <select aria-label="Account" name="lines[{{ $i }}][account_id]" class="input">
            <option value="">Choose account…</option>
            @foreach ($accounts as $id => $text)
                <option value="{{ $id }}" @selected((string) ($line['account_id'] ?? '') === (string) $id)>{{ $text }}</option>
            @endforeach
        </select>
        @error("lines.$i.account_id") <p class="field-error">{{ $message }}</p> @enderror
    </td>
    <td><input aria-label="Memo" name="lines[{{ $i }}][memo]" value="{{ $line['memo'] ?? '' }}" maxlength="150" class="input" placeholder="Optional"></td>
    <td><input aria-label="Debit" type="number" step="0.01" min="0" name="lines[{{ $i }}][debit]" value="{{ $line['debit'] ?? '' }}" class="input text-right" data-debit></td>
    <td><input aria-label="Credit" type="number" step="0.01" min="0" name="lines[{{ $i }}][credit]" value="{{ $line['credit'] ?? '' }}" class="input text-right" data-credit></td>
    <td><button type="button" class="btn btn-danger btn-sm" data-remove title="Remove line">✕</button></td>
</tr>
