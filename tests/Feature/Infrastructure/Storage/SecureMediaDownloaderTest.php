<?php

namespace Tests\Feature\Infrastructure\Storage;

use App\Infrastructure\Storage\MediaDownloadException;
use App\Infrastructure\Storage\SecureMediaDownloader;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class SecureMediaDownloaderTest extends TestCase
{
    use AssertsSystemLog;

    public function test_downloads_allowlisted_https_media_to_a_temp_file(): void
    {
        Http::fake(['media.samsara.com/*' => Http::response('clip-bytes', 200, ['Content-Type' => 'video/mp4'])]);

        $download = app(SecureMediaDownloader::class)->download('https://media.samsara.com/evt-1/road.mp4?sig=abc');

        try {
            $this->assertFileExists($download->path);
            $this->assertSame('clip-bytes', file_get_contents($download->path));
            $this->assertSame(10, $download->size);
            $this->assertSame('video/mp4', $download->contentType);
        } finally {
            $download->cleanup();
        }

        $this->assertFileDoesNotExist($download->path);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function urls(): array
    {
        return [
            'samsara media' => ['https://media.samsara.com/x.mp4', true],
            'samsara s3 bucket' => ['https://samsara-dashcam-media.s3.amazonaws.com/x.jpg', true],
            'regional s3' => ['https://s3.us-west-2.amazonaws.com/bucket/x.jpg', true],
            'cloudfront' => ['https://d1234.cloudfront.net/x.jpg', true],
            'plain http' => ['http://media.samsara.com/x.mp4', false],
            'foreign host' => ['https://evil.example.com/x.mp4', false],
            'suffix trick' => ['https://media.samsara.com.evil.io/x.mp4', false],
            'lookalike' => ['https://notsamsara.com/x.mp4', false],
            'internal ip' => ['https://169.254.169.254/latest/meta-data', false],
            'garbage' => ['not a url', false],
        ];
    }

    #[DataProvider('urls')]
    public function test_only_https_allowlisted_hosts_are_accepted(string $url, bool $allowed): void
    {
        $this->assertSame($allowed, app(SecureMediaDownloader::class)->isAllowed($url));
    }

    public function test_disallowed_url_is_rejected_without_any_request(): void
    {
        Http::fake();

        try {
            app(SecureMediaDownloader::class)->download('http://media.samsara.com/x.mp4');
            $this->fail('Expected MediaDownloadException');
        } catch (MediaDownloadException $exception) {
            $this->assertStringContainsString('https', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_content_length_over_the_cap_is_rejected(): void
    {
        config()->set('ai.media.max_download_bytes', 5);
        Http::fake(['media.samsara.com/*' => Http::response('clip-bytes', 200, ['Content-Length' => '10'])]);

        $this->expectException(MediaDownloadException::class);
        $this->expectExceptionMessageMatches('/excede el máximo/');

        app(SecureMediaDownloader::class)->download('https://media.samsara.com/x.mp4');
    }

    public function test_body_over_the_cap_is_rejected_even_without_content_length(): void
    {
        config()->set('ai.media.max_download_bytes', 5);
        Http::fake(['media.samsara.com/*' => Http::response('clip-bytes-way-too-long', 200)]);

        $this->expectException(MediaDownloadException::class);

        app(SecureMediaDownloader::class)->download('https://media.samsara.com/x.mp4');
    }

    public function test_error_status_and_empty_body_are_failures(): void
    {
        Http::fake([
            'media.samsara.com/forbidden*' => Http::response('denied', 403),
            'media.samsara.com/empty*' => Http::response('', 200),
        ]);

        $failures = 0;

        foreach (['https://media.samsara.com/forbidden.mp4', 'https://media.samsara.com/empty.mp4'] as $url) {
            try {
                app(SecureMediaDownloader::class)->download($url);
                $this->fail('Expected MediaDownloadException for '.$url);
            } catch (MediaDownloadException) {
                $failures++;
            }
        }

        $this->assertSame(2, $failures);
    }

    public function test_a_completed_download_is_logged_with_host_bytes_and_duration_never_the_signed_url(): void
    {
        Http::fake(['media.samsara.com/*' => Http::response('clip-bytes', 200, ['Content-Type' => 'video/mp4'])]);

        app(SecureMediaDownloader::class)->download('https://media.samsara.com/evt-1/road.mp4?X-Amz-Signature=s3cr3t')->cleanup();

        $ctx = $this->assertSystemLogged('media.download.completed');
        $this->assertSame(['host' => 'media.samsara.com'], $ctx['input']);
        $this->assertSame(['bytes' => 10, 'content_type' => 'video/mp4'], $ctx['result']);
        $this->assertArrayHasKey('duration_ms', $ctx);

        $this->assertStringNotContainsString('s3cr3t', (string) json_encode($this->systemLogEntries()));
        // El path lo conserva sólo la línea automática http.client de un host
        // de la allowlist; la de la descarga lleva sólo el host.
        $this->assertStringNotContainsString('evt-1', (string) json_encode($this->systemLogEntries('media.download.completed')));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_each_rejection_is_logged_with_its_reason(): void
    {
        Http::fake([
            'media.samsara.com/big*' => Http::response('x', 200, ['Content-Length' => '999999999']),
            'media.samsara.com/err*' => Http::response('nope', 403),
            'media.samsara.com/empty*' => Http::response('', 200),
        ]);

        $downloader = app(SecureMediaDownloader::class);

        foreach (['https://evil.example.com/x.mp4', 'https://media.samsara.com/big.mp4', 'https://media.samsara.com/err.mp4', 'https://media.samsara.com/empty.mp4'] as $url) {
            try {
                $downloader->download($url);
                $this->fail("Debió rechazar {$url}");
            } catch (MediaDownloadException) {
            }
        }

        $this->assertSystemLogged('media.download.rejected', fn (array $c) => $c['reason'] === 'ssrf_blocked' && $c['input']['host'] === 'evil.example.com');
        $this->assertSystemLogged('media.download.rejected', fn (array $c) => $c['reason'] === 'too_large' && $c['calc']['max_bytes'] > 0);
        $this->assertSystemLogged('media.download.rejected', fn (array $c) => $c['reason'] === 'http_error' && $c['calc']['http_status'] === 403);
        $this->assertSystemLogged('media.download.rejected', fn (array $c) => $c['reason'] === 'empty_body');
        $this->assertSystemNotLogged('media.download.completed');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_allowlist_is_config_driven(): void
    {
        config()->set('ai.media.allowed_download_hosts', ['media.example.test']);

        $downloader = app(SecureMediaDownloader::class);

        $this->assertTrue($downloader->isAllowed('https://media.example.test/x.jpg'));
        $this->assertFalse($downloader->isAllowed('https://media.samsara.com/x.jpg'));
    }
}
