<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;

class CsvImportService {
    const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
    const MAX_LINES = 10000; // Max lines to process

    public function import(string $filePath, ?array $headers = null): JsonResponse {
        $fullPath = storage_path('app/' . $filePath);
        Log::info('Starting CSV import for file: ' . $fullPath);

        // Protect against path traversal
        if (!$this->isPathSecure($fullPath)) {
            return response()->json(['error' => 'Invalid file path. Possible path traversal attempt.'], 400);
        }

        // Check file existence and size
        if (!$this->isFileValid($fullPath)) {
            return response()->json(['error' => 'File does not exist, is not readable, or exceeds maximum size of 10MB.'], 400);
        }

        // Retrieve X-Upload-ID from headers if provided
        $uploadId = null;
        if ($headers && isset($headers['x-upload-id'])) {
            Log::debug('Headers received: ', $headers);
            $uploadId = $headers['x-upload-id'][0];
            Log::debug('Upload id from service import: ' . print_r($uploadId, true));
        }

        try {
            $header = $this->getCsvHeader($fullPath);
            $modelClass = $this->getModelClassFromHeader($header);
            $modelInstance = new $modelClass();
            $fillableFields = $modelInstance->getFillable();
        } catch (\Exception $e) {
            Log::error('CSV Header error: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid CSV header: ' . $e->getMessage()], 400);
        }

        try {
            [$eventIds, $taskIds] = $this->processCsvInChunks($fullPath, $modelClass, $fillableFields, $uploadId);
        } catch (\Exception $e) {
            Log::error('Data processing error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to process data: ' . $e->getMessage()], 500);
        }

        try {
            $events = Event::whereIn('id', $eventIds)->get();
            $tasks = Task::whereIn('id', $taskIds)->get();
        } catch (\Exception $e) {
            Log::error('Database retrieval error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to retrieve related data from database.'], 500);
        }

        Log::info('Successfully retrieved events and tasks.');
        Log::info('Events: ', [$events->toArray()]);
        Log::info('Tasks: ', [$tasks->toArray()]);

        try {
            return response()->json(['Events' => $events->toArray(), 'Tasks' => $tasks->toArray()], 200);
        } catch (\Exception $e) {
            Log::error('JSON serialization error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to serialize response data.'], 500);
        }
    }

    // --------- SECURITY AND VALIDATION FUNCTIONS ---------

    protected function isPathSecure(string $path): bool {
        // Prevent path traversal
        $realBase = realpath(storage_path('app'));
        $realPath = realpath($path);
        if ($realPath === false || $realBase === false) {
            return false;
        }
        return strpos($realPath, $realBase) === 0;
    }

    protected function isFileValid(string $path): bool {
        if (!file_exists($path)) {
            Log::warning('File does not exist: ' . $path);
            return false;
        }
        if (!is_readable($path)) {
            Log::warning('File is not readable: ' . $path);
            return false;
        }
        if (filesize($path) > self::MAX_FILE_SIZE) {
            Log::warning('File exceeds maximum size (' . self::MAX_FILE_SIZE . ' bytes): ' . $path);
            return false;
        }
        return true;
    }

    protected function getCsvHeader(string $filePath): array {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new FileNotFoundException($filePath);
        }

        if (($handle = fopen($filePath, 'r')) !== false) {
            // Limit reading lines to prevent DoS
            $headerLine = fgets($handle);
            fclose($handle);

            if (substr($headerLine, 0, 3) === "\xEF\xBB\xBF") {
                $headerLine = substr($headerLine, 3);
            }
            return str_getcsv($headerLine);
        }
        throw new \Exception("Failed to open CSV file for reading header: " . $filePath);
    }

    protected function getModelClassFromHeader(array $header) {
        $headerLower = array_map('strtolower', $header);
        $eventFields = array_map('strtolower', (new \App\Models\Event())->getFillable());
        $taskFields = array_map('strtolower', (new \App\Models\Task())->getFillable());

        Log::debug('Header Lowercase: ', $headerLower);
        Log::debug('Event Model Fillable Fields: ', $eventFields);
        Log::debug('Task Model Fillable Fields: ', $taskFields);

        if (empty(array_diff($headerLower, $eventFields))) {
            Log::info('Header matches Event model.');
            return \App\Models\Event::class;
        }

        if (empty(array_diff($headerLower, $taskFields))) {
            Log::info('Header matches Task model.');
            return \App\Models\Task::class;
        }

        Log::error('Unknown model type for header', ['header' => $headerLower]);
        throw new \Exception("Unknown model type for provided CSV header.");
    }

    protected function processCsvInChunks(string $filePath, string $modelClass, array $fillableFields, ?string $uploadId): array {
        $eventIds = [];
        $taskIds = [];

        $batchSize = 1000;

        $handle = fopen($filePath, 'r');
        if (!$handle) throw new \Exception("Cannot open file: " . $filePath);

        // Skip BOM if present
        $headerLine = fgets($handle);
        if (substr($headerLine, 0, 3) === "\xEF\xBB\xBF") {
            $headerLine = substr($headerLine, 3);
        }
        $headers = str_getcsv($headerLine);

        $batchEvents = [];
        $batchTasks = [];
        $lineCount = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $lineCount++;
            if ($lineCount > self::MAX_LINES) {
                Log::warning('Maximum line limit reached, stopping processing.', ['lineCount' => $lineCount]);
                break; // prevent processing too many lines
            }
            if (count($row) !== count($headers)) {
                Log::warning('Row skipped due to mismatched column count.', ['row' => $row, 'expectedColumns' => count($headers)]);
                continue;
            }
            $rowData = array_combine($headers, $row);
            $filteredData = $this->filterRowData($rowData, $fillableFields);

            // Protect against CSV injection
            $filteredData = $this->sanitizeCsvInjection($filteredData);

            if ($uploadId !== null) {
                Log::debug('Using upload ID: ' . $uploadId);
                $filteredData['uploaded'] = $uploadId;
            } else {
                Log::error('Upload ID not set; defaulting to true.');
                $filteredData['uploaded'] = true;
            }

            if ($modelClass === \App\Models\Event::class) {
                $batchEvents[] = $filteredData;
            } elseif ($modelClass === \App\Models\Task::class) {
                $batchTasks[] = $filteredData;
            }

            if (count($batchEvents) >= $batchSize) {
                $eventIds = array_merge($eventIds, $this->insertBatch(\App\Models\Event::class, $batchEvents));
                $batchEvents = [];
            }
            if (count($batchTasks) >= $batchSize) {
                $taskIds = array_merge($taskIds, $this->insertBatch(\App\Models\Task::class, $batchTasks));
                $batchTasks = [];
            }
        }

        // Insert remaining
        if ($modelClass === \App\Models\Event::class && !empty($batchEvents)) {
            $eventIds = array_merge($eventIds, $this->insertBatch(\App\Models\Event::class, $batchEvents));
        }
        if ($modelClass === \App\Models\Task::class && !empty($batchTasks)) {
            $taskIds = array_merge($taskIds, $this->insertBatch(\App\Models\Task::class, $batchTasks));
        }

        fclose($handle);
        return [$eventIds, $taskIds];
    }

    protected function sanitizeCsvInjection(array $data): array {
        foreach ($data as $key => &$value) {
            // Protect against CSV injection by prefixing dangerous characters
            if (is_string($value) && preg_match('/^[=\+\-@]/', $value)) {
                $value = "'" . $value;
            }
        }
        return $data;
    }

    protected function insertBatch(string $modelClass, array $data): array {
        $ids = [];
        DB::beginTransaction();
        try {
            $modelClass::insert($data);
            // Fetch latest inserted IDs
            $ids = $modelClass::latest()->take(count($data))->pluck('id')->toArray();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Batch insert failed for ' . $modelClass . ': ' . $e->getMessage());
            throw new \Exception('Batch insert failed for ' . $modelClass . ': ' . $e->getMessage());
        }
        return $ids;
    }

    protected function filterRowData(array $row, array $fillableFields): array {
        $filtered = array_filter(
            $row,
            fn($key) => in_array($key, $fillableFields),
            ARRAY_FILTER_USE_KEY
        );

        foreach ($filtered as $key => &$value) {
            if (in_array($key, ['all_day', 'uploaded'])) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }

            if (in_array($key, ['start_datetime', 'end_datetime', 'due_date'])) {
                try {
                    // Parse the date string without regex, relying on PHP's DateTime parsing
                    $dateTime = new \DateTime($value);
                    // Format to 'Y-m-d H:i:s' in UTC or desired timezone
                    $dateTime->setTimezone(new \DateTimeZone('UTC'));
                    $value = $dateTime->format('Y-m-d H:i:s');
                } catch (\Exception $e) {
                    Log::error('Failed to parse date for key "' . $key . '": ' . $value);
                    // Optionally, set to null or handle differently
                    $value = null;
                }
            }
        }

        return $filtered;
    }
}
