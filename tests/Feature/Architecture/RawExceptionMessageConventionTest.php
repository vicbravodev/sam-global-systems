<?php

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Ningún `->getMessage()` nuevo en app/: el mensaje crudo de una excepción
 * puede llevar bindings SQL, URLs con token o PII del proveedor y no debe
 * llegar a la DB, a un evento ni a una respuesta. Para persistir se usa
 * `App\Support\SafeErrorMessage::from($e)`; para logs, `error: $e`.
 *
 * Cada uso permitido está contado por archivo con su motivo: un uso nuevo
 * (o uno más en un archivo listado) rompe el test y obliga a revisarlo.
 */
class RawExceptionMessageConventionTest extends TestCase
{
    /**
     * @var array<string, int> ruta relativa → usos permitidos
     */
    private const array ALLOWED = [
        // Los propios saneadores.
        'app/Support/SafeErrorMessage.php' => 3,
        'app/Support/SafeException.php' => 1,
        'app/Support/RedactSensitiveLogData.php' => 2,
        // Sólo clasifica el error (¿reintentable?); el texto no sale del método.
        'app/Domains/AI/Support/RetryableAIError.php' => 1,
        // ActionFailure('invalid_assignee'): el texto del guard de AssignIncident es fijo (enum + ids enteros).
        'app/Domains/Automation/Actions/ExecuteAction.php' => 1,
        // Envuelven y re-lanzan como RuntimeException, que no está en la allowlist de SafeErrorMessage.
        'app/Infrastructure/AI/Agents/StructuredOutputParser.php' => 1,
        'app/Infrastructure/AI/Agents/SdkEventEvaluationAgent.php' => 1,
        'app/Infrastructure/AI/Agents/SdkMediaAssessmentAgent.php' => 1,
        // Salida de consola de comandos de operador (nada se persiste).
        'app/Console/Commands/EvalAIPromptsCommand.php' => 3,
        'app/Console/Commands/EnsureStorageBucketCommand.php' => 1,
    ];

    public function test_no_new_raw_exception_message_in_app(): void
    {
        $unexpected = [];

        foreach (File::allFiles(app_path()) as $file) {
            if (! str_ends_with($file->getPathname(), '.php')) {
                continue;
            }

            $path = str_replace(base_path().'/', '', $file->getPathname());
            $uses = preg_match_all('/->getMessage\(/', (string) file_get_contents($file->getPathname()));

            if ($uses > (self::ALLOWED[$path] ?? 0)) {
                $unexpected[] = "{$path} ({$uses})";
            }
        }

        $this->assertSame([], $unexpected, "getMessage() crudo: usa SafeErrorMessage::from(\$e) para persistir o `error: \$e` en SystemLog:\n".implode("\n", $unexpected));
    }
}
