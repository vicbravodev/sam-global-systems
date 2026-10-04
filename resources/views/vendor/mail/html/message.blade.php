@props([
    'tone' => 'primary',
    'preheader' => null,
])
<x-mail::layout :tone="$tone" :preheader="$preheader">
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
**SAM Global Systems** · Sistema Automatizado de Monitoreo<br>
Monitoreo de flotas 24/7 con inteligencia artificial.

<span class="footer-muted">Este es un correo automático de [{{ str_replace(['https://', 'http://'], '', rtrim((string) config('app.url'), '/')) }}]({{ config('app.url') }}). © {{ date('Y') }} SAM Global Systems.</span>
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
