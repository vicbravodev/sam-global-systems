<?php

namespace App\Domains\Context\Support;

use App\Support\RedactSensitiveLogData;
use App\Support\SystemLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Extrae fotogramas clave de un clip con ffmpeg (vía el facade `Process`,
 * sin ffprobe): la duración se lee del banner de `ffmpeg -i`, y cada
 * fotograma se toma con un seek a una posición relativa del clip, escalado a
 * un ancho máximo y codificado como JPEG.
 *
 * Todo el trabajo ocurre en un directorio temporal que se borra siempre al
 * salir, haya ido bien o no.
 */
class VideoFrameExtractor
{
    public function binary(): string
    {
        return (string) config('media-frames.ffmpeg_binary', 'ffmpeg');
    }

    /**
     * ¿Hay un ffmpeg ejecutable? Se comprueba una vez y se recuerda un rato
     * para no lanzar un proceso por cada clip.
     */
    public function isAvailable(): bool
    {
        $binary = $this->binary();

        return (bool) Cache::remember(
            'media-frames:ffmpeg-available:'.md5($binary),
            (int) config('media-frames.availability_cache_seconds', 600),
            function () use ($binary): bool {
                try {
                    return Process::timeout(10)->run([$binary, '-version'])->successful();
                } catch (Throwable) {
                    return false;
                }
            },
        );
    }

    /**
     * @param  string  $videoContents  Binario del clip.
     * @param  float|null  $durationSeconds  Duración conocida; si es null se lee del banner de ffmpeg.
     * @return list<array{offset_seconds: float, contents: string}>
     */
    public function extract(string $videoContents, ?float $durationSeconds = null, string $extension = 'mp4'): array
    {
        $workDir = rtrim(sys_get_temp_dir(), '/').'/sam-video-frames/'.Str::uuid()->toString();
        File::ensureDirectoryExists($workDir);

        try {
            $input = $workDir.'/input.'.(preg_replace('/[^a-z0-9]/i', '', $extension) ?: 'mp4');
            File::put($input, $videoContents);

            $duration = $durationSeconds !== null && $durationSeconds > 0
                ? $durationSeconds
                : $this->probeDuration($input);

            $frames = [];

            foreach ($this->offsets($duration) as $index => $offset) {
                $output = sprintf('%s/frame-%d.jpg', $workDir, $index);

                $result = Process::timeout((int) config('media-frames.process_timeout', 60))->run([
                    $this->binary(),
                    '-hide_banner',
                    '-loglevel', 'error',
                    '-y',
                    '-ss', number_format($offset, 3, '.', ''),
                    '-i', $input,
                    '-frames:v', '1',
                    '-vf', sprintf("scale='min(%d,iw)':-2", (int) config('media-frames.max_width', 1280)),
                    '-q:v', (string) config('media-frames.jpeg_quality', 3),
                    $output,
                ]);

                if (! $result->successful() || ! File::exists($output) || File::size($output) === 0) {
                    SystemLog::skipped('media.frames.offset_missing', reason: 'no_frame_at_offset', input: ['offset_seconds' => $offset, 'exit_code' => $result->exitCode(), 'stderr_excerpt' => Str::limit(RedactSensitiveLogData::sanitize($result->errorOutput()), 200)]);

                    continue;
                }

                $frames[] = [
                    'offset_seconds' => round($offset, 3),
                    'contents' => File::get($output),
                ];
            }

            return $frames;
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * Offsets en segundos donde tomar los fotogramas. Sin duración conocida
     * sólo se puede garantizar el primer fotograma.
     *
     * @return list<float>
     */
    public function offsets(?float $duration): array
    {
        if ($duration === null || $duration <= 0) {
            return [0.0];
        }

        /** @var array<int, float|int> $positions */
        $positions = config('media-frames.positions', [0.1, 0.5, 0.9]);

        return array_values(array_unique(array_map(
            static fn (float|int $position): float => round(max(0.0, min(1.0, (float) $position)) * $duration, 3),
            $positions,
        ), SORT_REGULAR));
    }

    /**
     * `ffmpeg -i input` sin salida termina con error, pero imprime la
     * duración en stderr ("Duration: 00:00:12.34"): suficiente sin ffprobe.
     */
    private function probeDuration(string $input): ?float
    {
        $result = Process::timeout(30)->run([$this->binary(), '-hide_banner', '-i', $input]);

        $banner = $result->errorOutput().$result->output();

        if (preg_match('/Duration:\s*(\d+):(\d{2}):(\d{2}(?:\.\d+)?)/', $banner, $m) !== 1) {
            return null;
        }

        return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (float) $m[3];
    }
}
