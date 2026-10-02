<?php

namespace App\Support;

use App\Contracts\AI\Exceptions\MediaFileMissingException;
use App\Contracts\AI\Exceptions\MediaFileRejectedException;
use App\Domains\Automation\Support\ActionFailure;
use App\Domains\Integrations\Exceptions\ProviderRequestFailed;
use App\Domains\Integrations\Exceptions\ProviderRequestFailedException;
use App\Domains\Tenancy\Exceptions\TenantContextException;
use App\Infrastructure\Storage\MediaDownloadException;
use App\Support\Http\UnsafeOutboundUrlException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Throwable;
use Twilio\Exceptions\RestException;

/**
 * Texto de una excepción apto para PERSISTIR (columnas `error_message`,
 * `last_error_message`, `failure_reason`, JSON de respuesta) o viajar en un
 * evento: lo que ve el tenant en la UI y lo que queda en la DB, que no pasan
 * por el redactor de logs.
 *
 * El mensaje crudo de una excepción ajena puede traer bindings SQL, URLs
 * firmadas o con token, el path secreto de un webhook o la respuesta de un
 * proveedor con teléfonos y emails. Por eso:
 *
 * - Excepciones de la allowlist (propias, con texto que construimos): su
 *   mensaje, pasado por el redactor.
 * - Cualquier otra: sólo la clase corta y metadatos técnicos (status HTTP,
 *   SQLSTATE, código de Twilio, código cURL), nunca su mensaje.
 *
 * Siempre truncado. Para logs se usa `error: $e` ({@see SafeException}).
 */
final class SafeErrorMessage
{
    public const int DEFAULT_LIMIT = 500;

    /**
     * Tipos cuyo mensaje se construye en el código con texto fijo, ids y
     * códigos, nunca con el mensaje de otra excepción ni con datos del
     * proveedor sin acotar. Añadir una clase aquí exige revisar todos sus
     * `new`/`throw`: ninguno puede interpolar el mensaje de otra excepción
     * (lo vigila `RawExceptionMessageConventionTest`).
     *
     * @var list<class-string<Throwable>>
     */
    public const array SAFE_MESSAGE_TYPES = [
        ActionFailure::class,
        ProviderRequestFailed::class,
        ProviderRequestFailedException::class,
        UnsafeOutboundUrlException::class,
        MediaDownloadException::class,
        MediaFileRejectedException::class,
        MediaFileMissingException::class,
        TenantContextException::class,
    ];

    public static function from(Throwable $e, int $limit = self::DEFAULT_LIMIT): string
    {
        $text = self::hasSafeMessage($e) && trim($e->getMessage()) !== ''
            ? RedactSensitiveLogData::sanitize($e->getMessage())
            : self::describe($e);

        return Str::limit($text, max(1, $limit - 1), '…');
    }

    public static function hasSafeMessage(Throwable $e): bool
    {
        foreach (self::SAFE_MESSAGE_TYPES as $type) {
            if ($e instanceof $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clase corta más los metadatos técnicos que ayudan a diagnosticar sin
     * exponer el mensaje: `ConnectionException (cURL 28)`,
     * `RequestException (HTTP 503)`, `RestException (código 21211, HTTP 400)`.
     */
    private static function describe(Throwable $e): string
    {
        $details = array_values(array_filter(match (true) {
            $e instanceof RequestException => ['HTTP '.$e->response->status()],
            $e instanceof GuzzleRequestException && $e->getResponse() !== null => ['HTTP '.$e->getResponse()->getStatusCode()],
            $e instanceof QueryException => ['SQLSTATE '.($e->errorInfo[0] ?? $e->getCode())],
            $e instanceof RestException => [
                $e->getCode() !== 0 ? 'código '.$e->getCode() : null,
                'HTTP '.$e->getStatusCode(),
            ],
            $e instanceof ConnectionException, $e instanceof ConnectException => [self::curlCode($e)],
            default => [is_int($e->getCode()) && $e->getCode() !== 0 ? 'código '.$e->getCode() : null],
        }, static fn (?string $detail): bool => $detail !== null));

        $short = class_basename($e);

        return $details === [] ? $short : $short.' ('.implode(', ', $details).')';
    }

    private static function curlCode(Throwable $e): ?string
    {
        return preg_match('/cURL error (\d+)/', $e->getMessage(), $match) === 1 ? 'cURL '.$match[1] : null;
    }
}
