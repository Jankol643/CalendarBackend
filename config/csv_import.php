<?php

declare(strict_types = 1);

return [
    'batch_size' => env('CSV_BATCH_SIZE', 1000),
    // 10MB
    'max_file_size' => env('CSV_MAX_FILE_SIZE', 10 * 1024 * 1024),
    'max_lines' => env('CSV_MAX_LINES', 10000),

    'validation' => [
        'allowed_mime_types' => ['text/csv', 'text/plain', 'application/csv'],
    ],
];
