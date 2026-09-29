<?php

namespace Tests\Unit\Support;

use App\Support\SafeException;
use Illuminate\Database\QueryException;
use LogicException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SafeExceptionTest extends TestCase
{
    public function test_describes_class_code_location_and_sanitized_message(): void
    {
        $e = new RuntimeException('GET https://bucket.s3/x.jpg?X-Amz-Signature=secret failed for a@b.co', 42, new LogicException('inner'));

        $d = SafeException::describe($e);

        $this->assertSame(RuntimeException::class, $d['class']);
        $this->assertSame(42, $d['code']);
        $this->assertSame('GET https://bucket.s3/x.jpg?[redacted] failed for [email]', $d['message']);
        $this->assertStringContainsString('SafeExceptionTest.php:', $d['at']);
        $this->assertStringStartsNotWith('/', $d['at']);
        $this->assertSame(LogicException::class, $d['previous']);
        $this->assertArrayNotHasKey('trace', $d);
    }

    public function test_truncates_long_messages_to_300_chars(): void
    {
        $d = SafeException::describe(new RuntimeException(str_repeat('a', 1000)));

        $this->assertLessThanOrEqual(300, mb_strlen($d['message']));
    }

    public function test_query_exceptions_never_expose_bindings(): void
    {
        $pdo = new PDOException('SQLSTATE[23505]: Unique violation');
        $pdo->errorInfo = ['23505', 7, 'duplicate'];
        $e = new QueryException('pgsql', 'insert into users (email, phone) values (?, ?)', ['ana@x.com', '+525512345678'], $pdo);

        $d = SafeException::describe($e);

        $this->assertSame('23505', $d['sqlstate']);
        $this->assertSame('insert into users (email, phone) values (?, ?)', $d['message']);
        $this->assertStringNotContainsString('ana@x.com', json_encode($d));
        $this->assertStringNotContainsString('5512345678', json_encode($d));
    }

    public function test_trace_lists_frames_without_arguments(): void
    {
        $d = SafeException::describe(new RuntimeException('x'), withTrace: true);

        $this->assertNotEmpty($d['trace']);
        $this->assertLessThanOrEqual(15, count($d['trace']));
        $this->assertMatchesRegularExpression('/^.+:\d+ .+$|^\[internal\] .+$/', $d['trace'][0]);
    }
}
