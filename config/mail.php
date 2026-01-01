<?php

declare(strict_types = 1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send any email
    | messages sent by your application. Alternative mailers may be setup
    | and used as needed; however, this mailer will be used by default.
    |
    */

    'default' => env('MAIL_MAILER', 'smtp'),

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all e-mails sent by your application to be sent from
    | the same address. Here, you may specify a name and address that is
    | used globally for all e-mails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers to be used while
    | sending an e-mail. You will specify which one you are using for your
    | mailers below. You are free to add additional mailers as required.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses",
    |            "postmark", "log", "array", "failover"
    |
    */

    'mailers' => [

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'mailers' => [
                'smtp',
                'log',
            ],
            'transport' => 'failover',
        ],

        'log' => [
            'channel' => env('MAIL_LOG_CHANNEL'),
            'transport' => 'log',
        ],

        'mailgun' => [
            'transport' => 'mailgun',
        ],

        'postmark' => [
            'transport' => 'postmark',
        ],

        'sendmail' => [
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
            'transport' => 'sendmail',
        ],

        'ses' => [
            'transport' => 'ses',
        ],
        'smtp' => [
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'host' => env('MAIL_HOST', 'smtp.mailgun.org'),
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
            'password' => env('MAIL_PASSWORD'),
            'port' => env('MAIL_PORT', 587),
            'timeout' => null,
            'transport' => 'smtp',
            'username' => env('MAIL_USERNAME'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Markdown Mail Settings
    |--------------------------------------------------------------------------
    |
    | If you are using Markdown based email rendering, you may configure your
    | theme and component paths here, allowing you to customize the design
    | of the emails. Or, you may simply stick with the Laravel defaults!
    |
    */

    'markdown' => [

        'paths' => [
            resource_path('views/vendor/mail'),
        ],
        'theme' => 'default',
    ],

];
