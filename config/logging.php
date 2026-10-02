<?php

use App\Support\RedactLogChannel;
use Illuminate\Log\Formatters\JsonFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

// Defaults de producción cuando el entorno no fija LOG_STACK / LOG_LEVEL: en
// producción el stack rota por día (`single` crece sin límite) y el nivel es
// `info` (las líneas `debug` del log narrativo se quedan en dev). Lo explícito
// en el entorno siempre manda.
$isProduction = env('APP_ENV') === 'production';
$defaultStack = $isProduction ? 'daily,json' : 'single,json';
$defaultLevel = $isProduction ? 'info' : 'debug';

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', $defaultStack)),
            'ignore_exceptions' => false,
            'tap' => [RedactLogChannel::class],
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', $defaultLevel),
            'replace_placeholders' => true,
            'tap' => [RedactLogChannel::class],
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', $defaultLevel),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
            'tap' => [RedactLogChannel::class],
        ],

        // Log narrativo del sistema (App\Support\SystemLog): una línea JSON por
        // decisión, con el Context en `extra` (trace_id, team_id, ids de etapa).
        // Seguir un evento: `jq 'select(.extra.trace_id == "<id>")' storage/logs/system-*.json`.
        // Catálogo de códigos: docs/SAM/logging.md. Con LOG_JSON_STDERR=true va a
        // stderr (para un agregador) en lugar de a archivo.
        // Misma truthiness que antes: env() da bool, null o string ('' y '0' = no).
        'json' => ! in_array(env('LOG_JSON_STDERR', false), [null, false, '', '0'], true) ? [
            'driver' => 'monolog',
            'level' => env('LOG_JSON_LEVEL', env('LOG_LEVEL', $defaultLevel)),
            'handler' => StreamHandler::class,
            'handler_with' => ['stream' => 'php://stderr'],
            'formatter' => JsonFormatter::class,
            'tap' => [RedactLogChannel::class],
        ] : [
            'driver' => 'daily',
            'path' => storage_path('logs/system.json'),
            'level' => env('LOG_JSON_LEVEL', env('LOG_LEVEL', $defaultLevel)),
            'days' => env('LOG_JSON_DAYS', 7),
            'formatter' => JsonFormatter::class,
            'tap' => [RedactLogChannel::class],
        ],

        // Telemática: alto volumen (resumen por ciclo cada 5 s por tenant), en
        // su propio archivo, en JSON con el mismo esquema de SystemLog.
        'telematics' => [
            'driver' => 'daily',
            'path' => storage_path('logs/telematics.json'),
            'level' => env('LOG_TELEMATICS_LEVEL', 'info'),
            'days' => env('LOG_TELEMATICS_DAYS', 7),
            'formatter' => JsonFormatter::class,
            'tap' => [RedactLogChannel::class],
        ],

        // Laravel lo trae por defecto; se declara aquí para redactar también este canal.
        'monthly' => [
            'driver' => 'monthly',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', $defaultLevel),
            'max_files' => 3,
            'replace_placeholders' => true,
            'tap' => [RedactLogChannel::class],
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
            'tap' => [RedactLogChannel::class],
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', $defaultLevel),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
            'tap' => [RedactLogChannel::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', $defaultLevel),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
            'tap' => [RedactLogChannel::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', $defaultLevel),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
            'tap' => [RedactLogChannel::class],
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', $defaultLevel),
            'replace_placeholders' => true,
            'tap' => [RedactLogChannel::class],
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
            'tap' => [RedactLogChannel::class],
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
            'tap' => [RedactLogChannel::class],
        ],

    ],

];
