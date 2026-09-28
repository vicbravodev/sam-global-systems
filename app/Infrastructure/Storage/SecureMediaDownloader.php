<?php

namespace App\Infrastructure\Storage;

use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * Downloads provider media (pre-signed dashcam clips/stills) under a strict
 * policy before it ever reaches storage:
 *
 * - `https` only, and only to hosts in `ai.media.allowed_download_hosts`
 *   (suffix match, or a `*` glob); redirects are followed only to https
 *   allowlisted hosts;
 * - streamed to a temp file (`sink`), never buffered in memory;
 * - size capped by `ai.media.max_download_bytes`: rejected up-front from
 *   Content-Length, aborted mid-stream past the cap, and re-checked on disk;
 * - bounded by `ai.media.download_timeout`.
 *
 * Every rejection or transport failure raises `MediaDownloadException`; the
 * caller decides whether it is final or worth another poll.
 */
class SecureMediaDownloader
{
    public const int DEFAULT_MAX_BYTES = 200 * 1024 * 1024;

    public const int DEFAULT_TIMEOUT_SECONDS = 120;

    /** @var list<string> */
    public const array DEFAULT_ALLOWED_HOSTS = ['samsara.com', 'samsara-*.s3.amazonaws.com', 'amazonaws.com', 'cloudfront.net'];

    public function download(string $url): DownloadedMedia
    {
        $this->assertAllowed($url);

        $maxBytes = $this->maxBytes();
        $tempPath = tempnam(sys_get_temp_dir(), 'sam-media-');

        if ($tempPath === false) {
            throw new MediaDownloadException('No se pudo crear el archivo temporal de descarga.');
        }

        try {
            $response = Http::timeout($this->timeout())
                ->connectTimeout(10)
                ->withOptions([
                    'allow_redirects' => [
                        'max' => 3,
                        'strict' => true,
                        'protocols' => ['https'],
                        'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri): void {
                            $this->assertAllowed((string) $uri);
                        },
                    ],
                    'on_headers' => function (ResponseInterface $response) use ($maxBytes): void {
                        $length = $response->getHeaderLine('Content-Length');

                        if ($length !== '' && is_numeric($length) && (int) $length > $maxBytes) {
                            throw new MediaDownloadException(sprintf('Content-Length %d excede el máximo de %d bytes.', (int) $length, $maxBytes));
                        }
                    },
                    'progress' => function (int $downloadTotal, int $downloaded) use ($maxBytes): void {
                        if ($downloaded > $maxBytes) {
                            throw new MediaDownloadException(sprintf('La descarga excede el máximo de %d bytes.', $maxBytes));
                        }
                    },
                ])
                ->sink($tempPath)
                ->get($url);
        } catch (Throwable $exception) {
            @unlink($tempPath);

            throw $this->unwrap($exception);
        }

        if (! $response->successful()) {
            @unlink($tempPath);

            throw new MediaDownloadException('El proveedor respondió HTTP '.$response->status().'.', status: $response->status());
        }

        $length = $response->header('Content-Length');

        if ($length !== '' && is_numeric($length) && (int) $length > $maxBytes) {
            @unlink($tempPath);

            throw new MediaDownloadException(sprintf('Content-Length %d excede el máximo de %d bytes.', (int) $length, $maxBytes));
        }

        clearstatcache(true, $tempPath);
        $size = (int) @filesize($tempPath);

        // A sink left empty while the response still exposes a body (stubbed
        // responses reused across requests): materialize it from the body.
        if ($size === 0) {
            $body = $response->body();

            if ($body !== '' && strlen($body) <= $maxBytes) {
                file_put_contents($tempPath, $body);
                $size = strlen($body);
            }
        }

        if ($size === 0) {
            @unlink($tempPath);

            throw new MediaDownloadException('El proveedor devolvió un cuerpo vacío.');
        }

        if ($size > $maxBytes) {
            @unlink($tempPath);

            throw new MediaDownloadException(sprintf('La descarga pesa %d bytes y excede el máximo de %d bytes.', $size, $maxBytes));
        }

        $contentType = $response->header('Content-Type');

        return new DownloadedMedia($tempPath, $size, $contentType !== '' ? $contentType : null);
    }

    public function isAllowed(string $url): bool
    {
        try {
            $this->assertAllowed($url);

            return true;
        } catch (MediaDownloadException) {
            return false;
        }
    }

    private function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https') {
            throw new MediaDownloadException('Solo se permiten descargas de media por https.');
        }

        if ($host === '' || ! $this->hostAllowed($host)) {
            throw new MediaDownloadException('Host de descarga no permitido: '.($host !== '' ? $host : '(vacío)'));
        }
    }

    private function hostAllowed(string $host): bool
    {
        foreach ($this->allowedHosts() as $allowed) {
            $allowed = strtolower(trim($allowed));

            if ($allowed === '') {
                continue;
            }

            if (str_contains($allowed, '*')) {
                if (fnmatch($allowed, $host)) {
                    return true;
                }

                continue;
            }

            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function allowedHosts(): array
    {
        $hosts = config('ai.media.allowed_download_hosts', self::DEFAULT_ALLOWED_HOSTS);

        if (is_string($hosts)) {
            $hosts = explode(',', $hosts);
        }

        return array_values(array_filter(array_map('strval', (array) $hosts)));
    }

    private function maxBytes(): int
    {
        return max(1, (int) config('ai.media.max_download_bytes', self::DEFAULT_MAX_BYTES));
    }

    private function timeout(): int
    {
        return max(1, (int) config('ai.media.download_timeout', self::DEFAULT_TIMEOUT_SECONDS));
    }

    /**
     * Guzzle wraps exceptions thrown from `on_headers`/`progress`/`on_redirect`
     * callbacks; surface our own policy exception when it is in the chain.
     */
    private function unwrap(Throwable $exception): MediaDownloadException
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof MediaDownloadException) {
                return $current;
            }
        }

        $status = $exception instanceof GuzzleRequestException ? $exception->getResponse()?->getStatusCode() : null;

        return new MediaDownloadException('Falló la descarga de media: '.$exception->getMessage(), status: $status, previous: $exception);
    }
}
