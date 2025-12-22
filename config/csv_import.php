<?php

return [
    'max_file_size' => env('CSV_MAX_FILE_SIZE', 10 * 1024 * 1024), // 10MB
    'max_lines' => env('CSV_MAX_LINES', 10000),
    'batch_size' => env('CSV_BATCH_SIZE', 1000),

    'validation' => [
        'allowed_mime_types' => ['text/csv', 'text/plain', 'application/csv'],
    ],
];
