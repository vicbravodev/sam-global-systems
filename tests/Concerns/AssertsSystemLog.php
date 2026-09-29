<?php

namespace Tests\Concerns;

use App\Support\RedactSensitiveLogData;
use App\Support\SystemLog;
use Closure;

/**
 * Captura las líneas de SystemLog del test (antes de la redacción) para
 * afirmar la narrativa: qué código, con qué reason y qué campos de calc.
 */
trait AssertsSystemLog
{
    /**
     * @var list<array{level: string, code: string, context: array<string, mixed>, channel: ?string}>
     */
    private array $capturedSystemLog = [];

    protected function setUpAssertsSystemLog(): void
    {
        $this->capturedSystemLog = [];
        SystemLog::flushListeners();
        SystemLog::listen(function (array $entry): void {
            $this->capturedSystemLog[] = $entry;
        });
    }

    protected function tearDownAssertsSystemLog(): void
    {
        SystemLog::flushListeners();
    }

    /**
     * @return list<array{level: string, code: string, context: array<string, mixed>, channel: ?string}>
     */
    protected function systemLogEntries(?string $code = null): array
    {
        return array_values(array_filter(
            $this->capturedSystemLog,
            static fn (array $entry): bool => $code === null || $entry['code'] === $code,
        ));
    }

    /**
     * @param  (Closure(array<string, mixed>): bool)|null  $where
     * @return array<string, mixed> el contexto de la primera línea que cumple
     */
    protected function assertSystemLogged(string $code, ?Closure $where = null): array
    {
        foreach ($this->systemLogEntries($code) as $entry) {
            if ($where === null || $where($entry['context'])) {
                $this->addToAssertionCount(1);

                return $entry['context'];
            }
        }

        $seen = implode(', ', array_unique(array_column($this->capturedSystemLog, 'code')));
        $this->fail("No se registró [{$code}]".($where !== null ? ' con la condición dada' : '').". Registrados: [{$seen}]");
    }

    protected function assertSystemNotLogged(string $code): void
    {
        $this->assertSame([], $this->systemLogEntries($code), "Se registró [{$code}] y no debía.");
    }

    protected function assertNoSensitiveDataLogged(): void
    {
        foreach ($this->capturedSystemLog as $entry) {
            $findings = RedactSensitiveLogData::findings($entry['context']);

            $this->assertSame([], $findings, "[{$entry['code']}] recibió datos sensibles en: ".implode(', ', $findings));
        }
    }
}
