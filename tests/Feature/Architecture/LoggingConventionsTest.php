<?php

namespace Tests\Feature\Architecture;

use App\Support\SystemLog;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * La exigencia del log narrativo no se pierde con código nuevo: todo pasa por
 * SystemLog, ningún getMessage() crudo llega a un log y cada código literal
 * cumple dominio.etapa.resultado. Ver docs/SAM/logging.md.
 */
class LoggingConventionsTest extends TestCase
{
    /**
     * Archivos que pueden usar la fachada Log directamente.
     *
     * @var list<string>
     */
    private const array ALLOWED_DIRECT_LOG = [
        'app/Support/SystemLog.php',
    ];

    /**
     * @return array<string, string> ruta relativa → contenido
     */
    private function sources(): array
    {
        $files = [];

        foreach (['app', 'routes', 'bootstrap/app.php'] as $root) {
            $path = base_path($root);
            $list = is_dir($path) ? File::allFiles($path) : [new \SplFileInfo($path)];

            foreach ($list as $file) {
                if (str_ends_with($file->getPathname(), '.php')) {
                    $files[str_replace(base_path().'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $files;
    }

    public function test_nothing_logs_outside_system_log(): void
    {
        $offenders = [];

        foreach ($this->sources() as $path => $code) {
            if (in_array($path, self::ALLOWED_DIRECT_LOG, true)) {
                continue;
            }

            if (preg_match('/\bLog::|(?<![\w>:$])logger\(|(?<![\w>:$])info\(/', $code) === 1) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, "Usa App\\Support\\SystemLog en lugar de Log::/logger()/info():\n".implode("\n", $offenders));
    }

    public function test_no_raw_exception_message_reaches_a_log(): void
    {
        $offenders = [];

        foreach ($this->sources() as $path => $code) {
            foreach (preg_split('/;\s*\n/', $code) ?: [] as $statement) {
                if (str_contains($statement, 'SystemLog::') && str_contains($statement, 'getMessage()')) {
                    $offenders[] = $path;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)), "Pasa la excepción como `error:` (SafeException), nunca getMessage():\n".implode("\n", $offenders));
    }

    public function test_every_literal_code_follows_the_schema(): void
    {
        $invalid = [];

        foreach ($this->sources() as $path => $code) {
            preg_match_all('/SystemLog::(?:ok|skipped|degraded|failed|measure)\(\s*\'([^\']+)\'/', $code, $matches);

            foreach ($matches[1] as $literal) {
                if (preg_match(SystemLog::CODE_PATTERN, $literal) !== 1) {
                    $invalid[] = "{$path}: {$literal}";
                }
            }
        }

        $this->assertSame([], $invalid, "Códigos fuera de dominio.etapa.resultado:\n".implode("\n", $invalid));
    }

    public function test_every_literal_code_is_in_the_catalog(): void
    {
        $catalog = (string) file_get_contents(base_path('docs/SAM/logging.md'));
        $missing = [];

        foreach ($this->sources() as $path => $code) {
            preg_match_all('/SystemLog::(?:ok|skipped|degraded|failed|measure)\(\s*\'([^\']+)\'/', $code, $matches);

            foreach ($matches[1] as $literal) {
                if (! str_contains($catalog, '`'.$literal.'`')) {
                    $missing[] = "{$path}: {$literal}";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), "Añade estos códigos a docs/SAM/logging.md:\n".implode("\n", array_unique($missing)));
    }
}
