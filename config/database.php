<?php

declare(strict_types = 1);

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Here are each of the database connections setup for your application.
    | Of course, examples of configuring each database platform that is
    | supported by Laravel is shown below to make development simple.
    |
    |
    | All database work in Laravel is done through the PHP PDO facilities
    | so make sure you have the driver for your particular database of
    | choice installed on your machine before you begin development.
    |
    */

    'connections' => [

        'mysql' => [
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'database' => env('DB_DATABASE', 'forge'),
            'driver' => 'mysql',
            'engine' => null,
            'host' => env('DB_HOST', '127.0.0.1'),
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
            'password' => env('DB_PASSWORD', ''),
            'port' => env('DB_PORT', '3306'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'unix_socket' => env('DB_SOCKET', ''),
            'url' => env('DATABASE_URL'),
            'username' => env('DB_USERNAME', 'forge'),
        ],

        'pgsql' => [
            'charset' => 'utf8',
            'database' => env('DB_DATABASE', 'forge'),
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'password' => env('DB_PASSWORD', ''),
            'port' => env('DB_PORT', '5432'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
            'url' => env('DATABASE_URL'),
            'username' => env('DB_USERNAME', 'forge'),
        ],

        'sqlite' => [
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'driver' => 'sqlite',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'prefix' => '',
            'url' => env('DATABASE_URL'),
        ],

        'sqlsrv' => [
            'charset' => 'utf8',
            'database' => env('DB_DATABASE', 'forge'),
            'driver' => 'sqlsrv',
            'host' => env('DB_HOST', 'localhost'),
            'password' => env('DB_PASSWORD', ''),
            'port' => env('DB_PORT', '1433'),
            'prefix' => '',
            'prefix_indexes' => true,
            'url' => env('DATABASE_URL'),
            'username' => env('DB_USERNAME', 'forge'),
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for all database work. Of course
    | you may use many connections at once using the Database library.
    |
    */

    'default' => env('DB_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run in the database.
    |
    */

    'migrations' => 'migrations',

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as APC or Memcached. Laravel makes it easy to dig right in.
    |
    */

    'redis' => [

        'cache' => [
            'database' => env('REDIS_CACHE_DB', '1'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'url' => env('REDIS_URL'),
            'username' => env('REDIS_USERNAME'),
        ],

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'default' => [
            'database' => env('REDIS_DB', '0'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'url' => env('REDIS_URL'),
            'username' => env('REDIS_USERNAME'),
        ],

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_') . '_database_'),
        ],

    ],

];
