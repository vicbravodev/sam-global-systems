<x-mail::message :preheader="$bodyPreview">
<x-mail::eyebrow>Aviso de SAM</x-mail::eyebrow>

# {{ $subjectLine }}

{{-- Cuerpo ya escapado y en una sola línea HTML: Markdown no lo interpreta. --}}
{!! $bodyHtml !!}

Saludos,<br>
**Equipo SAM Global Systems**
</x-mail::message>
