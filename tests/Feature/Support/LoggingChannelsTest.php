<?php

namespace Tests\Feature\Support;

use App\Support\PipelineTrace;
use App\Support\RedactLogChannel;
use App\Support\SystemLog;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class LoggingChannelsTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('framework/testing/logging-'.bin2hex(random_bytes(4)).'.json');
    }

    protected function tearDown(): void
    {
        File::delete($this->path);

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function lastLine(): array
    {
        $lines = array_values(array_filter(explode("\n", (string) File::get($this->path))));

        return json_decode(end($lines), true);
    }

    public function test_every_configured_channel_is_tapped_with_redaction(): void
    {
        foreach (config('logging.channels') as $name => $channel) {
            if (in_array($channel['driver'] ?? null, ['stack'], true)) {
                continue;
            }

            $this->assertContains(RedactLogChannel::class, $channel['tap'] ?? [], "El canal [{$name}] no redacta.");
        }
    }

    public function test_the_default_stack_includes_json(): void
    {
        // El default del archivo, no el del .env local de quien corre el test.
        $this->assertStringContainsString("env('LOG_STACK', 'single,json')", (string) File::get(config_path('logging.php')));
        $this->assertSame(storage_path('logs/system.json'), config('logging.channels.json.path'));
    }

    public function test_json_line_carries_the_trace_and_is_redacted_after_the_context_is_added(): void
    {
        config(['logging.channels.json.path' => $this->path, 'logging.channels.json.driver' => 'single']);

        $trace = PipelineTrace::begin(42, 'samsara');
        Context::add('phone', '+525512345678');

        Log::channel('json')->warning('llamada a +525512345678', [
            'recipient' => ['email' => 'a@b.co', 'user_id' => 3],
            'exception' => new RuntimeException('GET https://x.s3/a.jpg?sig=secret'),
        ]);

        $line = $this->lastLine();
        $json = (string) json_encode($line);

        $this->assertSame($trace, $line['extra']['trace_id']);
        $this->assertSame(42, $line['extra']['team_id']);
        $this->assertSame('[redacted]', $line['extra']['phone']);
        $this->assertSame('llamada a [phone]', $line['message']);
        $this->assertSame(['email' => '[redacted]', 'user_id' => 3], $line['context']['recipient']);
        $this->assertSame(RuntimeException::class, $line['context']['exception']['class']);
        $this->assertStringNotContainsString('5512345678', $json);
        $this->assertStringNotContainsString('sig=secret', $json);
        $this->assertStringNotContainsString('a@b.co', $json);
    }

    public function test_telematics_channel_writes_redacted_json(): void
    {
        config(['logging.channels.telematics.path' => $this->path, 'logging.channels.telematics.driver' => 'single']);

        SystemLog::ok('telematics.cycle.completed', input: ['integration_id' => 1, 'note' => 'ana@x.com'], channel: 'telematics');

        $line = $this->lastLine();

        $this->assertSame('telematics.cycle.completed', $line['message']);
        $this->assertSame('[email]', $line['context']['input']['note']);
    }
}
