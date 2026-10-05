{{-- Change and % change cells. $change, $pct; $good = 'up' when an increase is good news, 'down' when it is bad news --}}
@php
    $tone = abs($change ?? 0) < 0.005 ? '' : ((($change > 0) === (($good ?? 'up') === 'up')) ? 'text-secondary' : 'text-error');
@endphp
<td class="num {{ $tone }}">{{ $change === null ? '' : (abs($change) < 0.005 ? '—' : ($change > 0 ? '+' : '') . number_format($change, 2)) }}</td>
<td class="num {{ $tone }}">{{ $pct === null ? '' : ($pct > 0 ? '+' : '') . $pct . '%' }}</td>
