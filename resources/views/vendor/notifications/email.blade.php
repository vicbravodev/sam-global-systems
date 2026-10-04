@php
    $tone = $tone ?? match ($level) {
        'error' => 'danger',
        'success' => 'success',
        default => 'primary',
    };
    $buttonColor = match ($tone) {
        'danger' => 'error',
        'success' => 'success',
        default => 'primary',
    };
    $preheader = $preheader ?? ($introLines[0] ?? null);
@endphp
<x-mail::message :tone="$tone" :preheader="$preheader">
{{-- Eyebrow --}}
@if (! empty($eyebrow))
<x-mail::eyebrow :tone="$tone">{{ $eyebrow }}</x-mail::eyebrow>
@endif

{{-- Greeting --}}
@if (! empty($greeting))
# {{ $greeting }}
@elseif ($level === 'error')
# Necesitamos tu atención
@else
# ¡Hola!
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Details --}}
@if (! empty($details))
<x-mail::details :rows="$details" :title="$detailsTitle ?? null" />

@endif

{{-- Action Button --}}
@isset($actionText)
<x-mail::button :url="$actionUrl" :color="$buttonColor">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
Saludos,<br>
**Equipo SAM Global Systems**
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
¿El botón «{{ $actionText }}» no funciona? Copia y pega este enlace en tu navegador: <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
