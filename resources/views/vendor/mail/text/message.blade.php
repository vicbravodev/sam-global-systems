@props([
    'tone' => 'primary',
    'preheader' => null,
])
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
SAM · Sistema Automatizado de Monitoreo
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{{ $slot }}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{{ $subcopy }}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
SAM Global Systems · Monitoreo de flotas 24/7 con inteligencia artificial.
Este es un correo automático. © {{ date('Y') }} SAM Global Systems.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
