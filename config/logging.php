<?php

declare(strict_types = 1);

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;

return [

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Out of
    | the box, Laravel uses the Monolog PHP logging library. This gives
    | you a variety of powerful log handlers / formatters to utilize.
    |
    | Available Drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog",
    |                    "custom", "stack"
    |
    */

    'channels' => [

        'daily' => [
            'days' => 14,
            'driver' => 'daily',
            'level' => env('LOG_LEVEL', 'debug'),
            'path' => storage_path('logs/laravel.log'),
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'connectionString' => 'tls://' . env('PAPERTRAIL_URL') . ':' . env('PAPERTRAIL_PORT'),
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
            ],
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        'single' => [
            'driver' => 'single',
            'level' => env('LOG_LEVEL', 'debug'),
            'path' => storage_path('logs/laravel.log'),
        ],

        'slack' => [
            'driver' => 'slack',
            'emoji' => ':boom:',
            'level' => env('LOG_LEVEL', 'critical'),
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => 'Laravel Log',
        ],
        'stack' => [
            'channels' => ['single'],
            'driver' => 'stack',
            'ignore_exceptions' => false,
        ],

        'stderr' => [
            'driver' => 'monolog',
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'handler' => StreamHandler::class,
            'level' => env('LOG_LEVEL', 'debug'),
            'with' => [
                'stream' => 'php://stderr',
            ],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that gets used when writing
    | messages to the logs. The name specified in this option should match
    | one of the channels defined in the "channels" configuration array.
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
        'trace' => false,
    ],

];
