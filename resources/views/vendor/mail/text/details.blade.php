@props([
    'rows' => [],
    'title' => null,
])
@if (filled($title))
{{ $title }}
@endif
@foreach ($rows as $label => $value)
{{ $label }}: {{ $value }}
@endforeach
