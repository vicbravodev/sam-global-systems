@props([
    'rows' => [],
    'title' => null,
])
<table class="details" width="100%" cellpadding="0" cellspacing="0" role="presentation">
@if (filled($title))
<tr>
<td colspan="2" class="details-title">{{ $title }}</td>
</tr>
@endif
@foreach ($rows as $label => $value)
<tr>
<td class="details-label" width="38%" valign="top">{{ $label }}</td>
<td class="details-value" valign="top">{{ $value }}</td>
</tr>
@endforeach
</table>
