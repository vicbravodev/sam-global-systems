<?php

/*
|--------------------------------------------------------------------------
| Extracción de fotogramas de video para el modelo de visión
|--------------------------------------------------------------------------
|
| Los clips (MediaType Clip/Video) no llegan al modelo de visión, que sólo
| acepta imágenes. `ExtractVideoFramesJob` saca N fotogramas clave con ffmpeg
| y los registra como Snapshot para que entren al pipeline multimodal.
|
| Requiere el binario `ffmpeg` en el worker de la cola `context` (viene en las
| imágenes Docker de Sail). Si no está, el job registra un warning y no hace nada.
|
*/

return [

    'enabled' => (bool) env('MEDIA_FRAMES_ENABLED', true),

    'ffmpeg_binary' => env('MEDIA_FFMPEG_BINARY', 'ffmpeg'),

    // Posiciones relativas (0-1) de la duración del clip donde se toma cada fotograma.
    'positions' => [0.1, 0.5, 0.9],

    // Ancho máximo del JPEG resultante (se conserva la proporción; nunca se amplía).
    'max_width' => (int) env('MEDIA_FRAMES_MAX_WIDTH', 1280),

    // Calidad JPEG de ffmpeg (-q:v): 2 = mejor, 31 = peor.
    'jpeg_quality' => 3,

    // Timeout en segundos de cada invocación de ffmpeg.
    'process_timeout' => 60,

    // Cuánto se recuerda (segundos) si el binario está disponible.
    'availability_cache_seconds' => 600,

];
