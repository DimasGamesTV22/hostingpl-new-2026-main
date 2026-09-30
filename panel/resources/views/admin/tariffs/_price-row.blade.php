{{--
    Строка таблицы «докупки». Используется дважды:
    • как Blade-partial с $row (TariffPrice|null)
    • как <template> для JS — тогда вместо $row подставляются значения __INDEX__/пустые
--}}
@php
    $index = $row?->id ?? '__INDEX__';
    $isTemplate = $row === null;
@endphp

<tr>
    <td>
        <select name="prices[{{ $index }}][resource]" class="select !py-1 !text-xs" @if ($isTemplate) required @endif>
            <option value="">—</option>
            @foreach ($resourceOptions as $key => $label)
                <option value="{{ $key }}" @selected(! $isTemplate && $row?->resource === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </td>
    <td>
        <input type="text" name="prices[{{ $index }}][label]" value="{{ $isTemplate ? '' : $row?->label }}"
               class="input !py-1 !text-xs" maxlength="120" placeholder="Название">
    </td>
    <td>
        <input type="text" name="prices[{{ $index }}][unit]" value="{{ $isTemplate ? 'шт' : ($row?->unit ?? 'шт') }}"
               class="input !py-1 !text-xs" maxlength="16">
    </td>
    <td>
        <input type="number" name="prices[{{ $index }}][unit_quantity]"
               value="{{ $isTemplate ? 1 : ($row?->unit_quantity ?? 1) }}"
               class="input !py-1 !text-xs" min="1">
    </td>
    <td>
        <input type="number" name="prices[{{ $index }}][price]"
               value="{{ $isTemplate ? '' : $row?->price }}" step="0.01" min="0"
               class="input !py-1 !text-xs" placeholder="0">
    </td>
    <td>
        <input type="number" name="prices[{{ $index }}][min_quantity]"
               value="{{ $isTemplate ? 0 : ($row?->min_quantity ?? 0) }}"
               class="input !py-1 !text-xs" min="0">
    </td>
    <td>
        <input type="number" name="prices[{{ $index }}][max_quantity]"
               value="{{ $isTemplate ? 0 : ($row?->max_quantity ?? 0) }}"
               class="input !py-1 !text-xs" min="0">
    </td>
    <td>
        <input type="number" name="prices[{{ $index }}][sort]"
               value="{{ $isTemplate ? 0 : ($row?->sort ?? 0) }}"
               class="input !py-1 !text-xs" min="0" max="999">
    </td>
    <td>
        <input type="hidden" name="prices[{{ $index }}][is_recurring]" value="1"
               @checked(! $isTemplate && $row?->is_recurring)>
        <button type="button" class="btn btn-ghost btn-sm text-red-400"
                onclick="this.closest('tr').remove()">
            @include('partials.icon', ['name' => 'trash', 'class' => 'w-4 h-4'])
        </button>
    </td>
</tr>
