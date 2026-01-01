<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Task;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class CsvProcessor {
    private CsvValidator $validator;
    private int $maxLines;
    private int $batchSize;

    const DEFAULT_MAX_LINES = 10000;
    const DEFAULT_BATCH_SIZE = 1000;

    public function __construct(CsvValidator $validator) {
        $this->validator = $validator;
        $this->maxLines = config('csv_import.max_lines', self::DEFAULT_MAX_LINES);
        $this->batchSize = config('csv_import.batch_size', self::DEFAULT_BATCH_SIZE);
    }

    public function process(UploadedFile $file, ?string $uploadId = null): array {
        $path = $file->store('csv-imports', 'local');
        $fullPath = storage_path('app/' . $path);

        try {
            $csvSettings = $this->detectCsvSettings($fullPath);
            $result = $this->processCsvFile($fullPath, $csvSettings, $uploadId);
            return $this->buildResult($result['eventIds'], $result['taskIds'], $result['summary']);
        } finally {
            Storage::delete($path);
        }
    }

    private function processCsvFile(string $filePath, array $csvSettings, ?string $uploadId): array {
        $handle = fopen($filePath, 'r+');
        if (!$handle) {
            throw new \RuntimeException("Cannot open CSV file: {$filePath}");
        }

        try {
            $this->removeBomFromFile($handle);
            $headers = $this->readCsvHeaders($handle, $csvSettings);
            $modelClass = $this->validator->validateCsvHeaders($headers);
            $fillableFields = (new $modelClass())->getFillable();

            return $this->processRows($handle, $headers, $modelClass, $fillableFields, $csvSettings, $uploadId);
        } finally {
            fclose($handle);
        }
    }

    private function processRows($handle, array $headers, string $modelClass, array $fillableFields, array $csvSettings, ?string $uploadId): array {
        $summary = $this->initializeSummary();
        $batchData = [];
        $eventIds = [];
        $taskIds = [];
        $lineCount = 0;

        while (($row = fgetcsv($handle, 0, $csvSettings['delimiter'], $csvSettings['enclosure'], $csvSettings['escape'])) !== false) {
            $lineCount++;
            if ($lineCount > $this->maxLines) {
                $this->logMaxLines($uploadId);
                break;
            }

            $summary['total_rows']++;
            if ($this->isEmptyRow($row)) {
                $summary['skipped_rows']++;
                continue;
            }

            if (!$this->validateColumnCount($row, $headers)) {
                $summary['failed_rows']++;
                continue;
            }

            $rowData = array_combine($headers, $row);
            $processedRow = $this->processRow($rowData, $fillableFields, $modelClass, $uploadId);

            if ($processedRow) {
                $batchData[] = $processedRow;
                $summary['processed_rows']++;
                if (count($batchData) >= $this->batchSize) {
                    $insertedIds = $this->insertBatch($modelClass, $batchData);
                    $this->trackInsertedIds($modelClass, $insertedIds, $eventIds, $taskIds);
                    $batchData = [];
                }
            } else {
                $summary['failed_rows']++;
            }
        }

        if (!empty($batchData)) {
            $insertedIds = $this->insertBatch($modelClass, $batchData);
            $this->trackInsertedIds($modelClass, $insertedIds, $eventIds, $taskIds);
        }

        return [
            'eventIds' => $eventIds,
            'taskIds' => $taskIds,
            'summary' => $summary,
        ];
    }

    private function initializeSummary(): array {
        return [
            'total_rows' => 0,
            'processed_rows' => 0,
            'failed_rows' => 0,
            'skipped_rows' => 0,
        ];
    }

    private function logMaxLines(?string $uploadId): void {
        Log::warning('Maximum line limit reached', [
            'max_lines' => $this->maxLines,
            'upload_id' => $uploadId,
        ]);
    }

    private function validateColumnCount(array $row, array $headers): bool {
        if (count($row) !== count($headers)) {
            Log::warning('Row skipped due to column mismatch', [
                'expected' => count($headers),
                'actual' => count($row),
            ]);
            return false;
        }
        return true;
    }

    private function removeBomFromFile($handle): void {
        AppLogger::info('Starting BOM removal process.');
        rewind($handle);
        $content = stream_get_contents($handle);
        if ($content === false) {
            AppLogger::warning('Failed to read file contents during BOM removal.');
            return;
        }

        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
            AppLogger::info('BOM detected, removing BOM from file.');
            // Rewind to beginning
            rewind($handle);
            // Check if stream is writable
            if (!stream_get_meta_data($handle)['mode'] || strpos(stream_get_meta_data($handle)['mode'], 'w') === false && strpos(stream_get_meta_data($handle)['mode'], '+') === false) {
                AppLogger::error('Stream is not writable.');
                return;
            }
            // Truncate the file
            if (ftruncate($handle, 0)) {
                if (fwrite($handle, $content) !== false) {
                    fflush($handle);
                    AppLogger::info('BOM removed successfully.');
                } else {
                    AppLogger::error('Failed to write content after truncating.');
                }
            } else {
                AppLogger::error('Failed to truncate file during BOM removal.');
            }
        } else {
            AppLogger::info('No BOM detected in the first line.');
            rewind($handle);
        }
    }

    private function readCsvHeaders($handle, array $csvSettings): array {
        rewind($handle);
        $firstLine = fgets($handle);
        if ($firstLine === false) {
            throw new \RuntimeException('CSV file is empty');
        }

        if (substr($firstLine, 0, 3) === "\xEF\xBB\xBF") {
            $firstLine = substr($firstLine, 3);
        }

        if (!mb_check_encoding($firstLine, 'UTF-8')) {
            throw new \RuntimeException('CSV encoding is not UTF-8');
        }

        rewind($handle);
        $headers = fgetcsv($handle, 0, $csvSettings['delimiter'], $csvSettings['enclosure'], $csvSettings['escape']);

        if (empty($headers) || empty(array_filter($headers))) {
            throw new \RuntimeException('CSV header is empty or invalid');
        }

        return $headers;
    }

    private function processRow(array $rowData, array $fillableFields, string $modelClass, ?string $uploadId): ?array {
        Log::debug('Row data: ', $rowData);
        $filteredData = array_intersect_key($rowData, array_flip($fillableFields));
        if (empty($filteredData)) {
            return null;
        }
        AppLogger::debug('UploadId: ' . $uploadId);

        $processedData = $this->applyProcessingPipeline($filteredData, $modelClass, $uploadId);
        return $processedData;
    }

    private function applyProcessingPipeline(array $data, string $uploadId): array {
        $data = $this->sanitizeRowData($data);
        $data = $this->convertDataTypes($data);
        $data = $this->limitFieldSizes($data);
        $data['uploaded'] = $uploadId;

        return $data;
    }

    private function sanitizeRowData(array $data): array {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
                $value = filter_var($value, FILTER_SANITIZE_SPECIAL_CHARS);
                $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
            }
            $sanitized[$key] = $value;
        }

        Log::debug('Sanitized row: ', $sanitized);
        return $sanitized;
    }

    private function convertDataTypes(array $data): array {
        $converted = $data;
        Log::debug('Converting data types', ['data' => $data]);

        foreach ($data as $key => $value) {
            if ($key === 'all_day') {
                $value = $this->convertBoolean($value);
            }
            if (in_array($key, ['start_datetime', 'end_datetime', 'due_date'])) {
                $value = $this->parseDate($value);
            }
            $converted[$key] = $value;
        }

        Log::debug('Row after conversion: ', ['converted' => $converted]);
        return $converted;
    }

    private function convertBoolean($value) {
        if (is_string($value)) {
            $valueLower = strtolower(trim($value));
            if ($valueLower === 'true') {
                return 1;
            } elseif ($valueLower === 'false') {
                return 0;
            } else {
                $boolVal = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                return $boolVal !== null ? (int)$boolVal : 0;
            }
        }
        return (int)filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function parseDate($value): ?string {
        try {
            $dateTime = new \DateTime($value);
            $dateTime->setTimezone(new \DateTimeZone('UTC'));
            return $dateTime->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            Log::warning("Failed to parse date", ['value' => $value]);
            return null;
        }
    }

    private function limitFieldSizes(array $data): array {
        $maxSizes = [
            'string' => 255,
            'text' => 65535,
            'title' => 255,
            'description' => 1000,
            'name' => 255,
            'email' => 255,
        ];

        $limited = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $maxSize = $maxSizes[$key] ?? $maxSizes['string'];
                if (strlen($value) > $maxSize) {
                    $value = substr($value, 0, $maxSize);
                    Log::warning("Field '{$key}' truncated to {$maxSize} characters");
                }
            }
            $limited[$key] = $value;
        }
        return $limited;
    }

    private function detectCsvSettings(string $filePath): array {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return $this->getDefaultCsvSettings();
        }

        $firstLine = fgets($handle);
        fclose($handle);
        if ($firstLine === false) {
            return $this->getDefaultCsvSettings();
        }

        $commonDelimiters = [',', ';', "\t", '|'];
        $maxCount = 0;
        $detectedDelimiter = ',';

        foreach ($commonDelimiters as $delimiter) {
            $count = substr_count($firstLine, $delimiter);
            if ($count > $maxCount) {
                $maxCount = $count;
                $detectedDelimiter = $delimiter;
            }
        }

        return [
            'delimiter' => $detectedDelimiter,
            'enclosure' => '"',
            'escape' => '\\',
        ];
    }

    private function getDefaultCsvSettings(): array {
        return [
            'delimiter' => ',',
            'enclosure' => '"',
            'escape' => '\\',
        ];
    }

    private function insertBatch(string $modelClass, array $data): array {
        if (empty($data)) {
            return [];
        }

        DB::beginTransaction();
        try {
            $modelClass::insert($data);
            $ids = DB::table((new $modelClass())->getTable())
                ->orderBy('id', 'desc')
                ->limit(count($data))
                ->pluck('id')
                ->toArray();
            DB::commit();
            return $ids;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Batch insert failed for {$modelClass}", [
                'error' => $e->getMessage(),
                'batch_size' => count($data),
            ]);
            throw new \RuntimeException('Failed to insert batch records');
        }
    }

    private function trackInsertedIds(string $modelClass, array $insertedIds, array &$eventIds, array &$taskIds): void {
        if ($modelClass === Event::class) {
            $eventIds = array_merge($eventIds, $insertedIds);
        } elseif ($modelClass === Task::class) {
            $taskIds = array_merge($taskIds, $insertedIds);
        }
    }

    private function buildResult(array $eventIds, array $taskIds, array $summary): array {
        try {
            $events = !empty($eventIds) ? Event::whereIn('id', $eventIds)->get()->toArray() : [];
            $tasks = !empty($taskIds) ? Task::whereIn('id', $taskIds)->get()->toArray() : [];
            return [
                'events' => $events,
                'tasks' => $tasks,
                'summary' => $summary,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to retrieve records', [
                'error' => $e->getMessage(),
                'event_ids' => $eventIds,
                'task_ids' => $taskIds,
            ]);
            throw new \RuntimeException('Failed to retrieve inserted records from database');
        }
    }

    private function isEmptyRow(array $row): bool {
        return count($row) === 1 && empty($row[0]);
    }
}
