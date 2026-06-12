<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'dark') !== 'light'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script: dark is the operator default. Only switch out of dark
             if the user explicitly chose light, or chose system on a light OS. --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "dark" }}';

                if (appearance === 'light') {
                    document.documentElement.classList.remove('dark');
                } else if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                    document.documentElement.classList.toggle('dark', prefersDark);
                } else {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(0.985 0.002 260);
            }

            html.dark {
                background-color: oklch(0.155 0.006 260);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- Metadatos OG básicos (F3.6) --}}
        <meta property="og:site_name" content="{{ config('app.name') }}">
        <meta property="og:title" content="{{ config('app.name') }} — Monitorista virtual para flotas">
        <meta property="og:description" content="SAM investiga cada alerta de tu flota, descarta el ruido y escala lo real: media, IA y verificación por voz en un solo protocolo.">
        <meta property="og:type" content="website">
        <meta property="og:image" content="{{ url('/apple-touch-icon.png') }}">
        <meta name="description" content="SAM investiga cada alerta de tu flota, descarta el ruido y escala lo real.">

        {{-- Fuentes self-hosted (F1.6): variables Geist/Geist Mono, subset latin --}}
        <link rel="preload" href="/fonts/geist-latin.woff2" as="font" type="font/woff2" crossorigin>
        <link rel="preload" href="/fonts/geist-mono-latin.woff2" as="font" type="font/woff2" crossorigin>
        <style>
            @font-face {
                font-family: 'Geist';
                font-style: normal;
                font-weight: 100 900;
                font-display: swap;
                src: url('/fonts/geist-latin.woff2') format('woff2');
                unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
            }

            @font-face {
                font-family: 'Geist Mono';
                font-style: normal;
                font-weight: 100 900;
                font-display: swap;
                src: url('/fonts/geist-mono-latin.woff2') format('woff2');
                unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
            }
        </style>

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
