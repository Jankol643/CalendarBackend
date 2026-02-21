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

    public function process(UploadedFile $file, bool $adjustDates, ?string $uploadId = null): array {
        $path = $file->store('csv-imports', 'local');
        $fullPath = storage_path('app/' . $path);

        try {
            $csvSettings = $this->detectCsvSettings($fullPath);
            $result = $this->processCsvFile($fullPath, $csvSettings, $adjustDates, $uploadId);
            return $this->buildResult($result['eventIds'], $result['taskIds'], $result['summary']);
        } finally {
            Storage::delete($path);
        }
    }

    private function processCsvFile(string $filePath, array $csvSettings, bool $adjustDates, ?string $uploadId): array {
        $handle = fopen($filePath, 'r+');
        if (!$handle) {
            throw new \RuntimeException("Cannot open CSV file: {$filePath}");
        }

        try {
            $this->removeBomFromFile($handle);
            $headers = $this->readCsvHeaders($handle, $csvSettings);
            $modelClass = $this->validator->validateCsvHeaders($headers);
            $fillableFields = (new $modelClass())->getFillable();

            return $this->processRows($handle, $headers, $modelClass, $fillableFields, $csvSettings, $adjustDates, $uploadId);
        } finally {
            fclose($handle);
        }
    }

    private function processRows($handle, array $headers, string $modelClass, array $fillableFields, array $csvSettings, bool $adjustDates, ?string $uploadId): array {
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

            $rowData = array_combine($headers, $row);
            $processedRow = $this->processRow($rowData, $fillableFields, $uploadId, $adjustDates);

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

            rewind($handle);
            $streamMeta = stream_get_meta_data($handle);
            if (!str_contains($streamMeta['mode'] ?? '', 'w') && !str_contains($streamMeta['mode'] ?? '', '+')) {
                AppLogger::error('Stream is not writable.');
                return;
            }

            if (ftruncate($handle, 0) && fwrite($handle, $content) !== false) {
                fflush($handle);
                AppLogger::info('BOM removed successfully.');
            } else {
                AppLogger::error('Failed to write content after truncating.');
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

        $firstLineTrimmed = rtrim($firstLine, "\r\n");
        if (!mb_check_encoding($firstLineTrimmed, 'UTF-8')) {
            throw new \RuntimeException('CSV encoding is not UTF-8');
        }

        rewind($handle);

        $headers = fgetcsv($handle, 0, $csvSettings['delimiter'], $csvSettings['enclosure'], $csvSettings['escape']);

        if ($headers === false || $headers === null || empty(array_filter($headers, 'strlen'))) {
            throw new \RuntimeException('Failed to read CSV headers or headers are empty');
        }

        return $headers;
    }

    private function processRow(array $rowData, array $fillableFields, ?string $uploadId, bool $adjustDates): ?array {
        $filteredData = array_intersect_key($rowData, array_flip($fillableFields));
        if (empty($filteredData)) {
            return null;
        }

        $processedData = $this->applyProcessingPipeline($filteredData, $uploadId, $adjustDates);
        AppLogger::debug('Processed data:');
        AppLogger::debug($processedData);
        return $processedData;
    }

    private function applyProcessingPipeline(array $data, string $uploadId, bool $adjustDates): array {
        $data = $this->sanitizeRowData($data);
        $data = $this->convertDataTypes($data, $adjustDates);
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

        return $sanitized;
    }

    private function convertDataTypes(array $data, bool $adjustDates): array {
        $converted = $data;
        Log::debug('Converting data types', ['data' => $data, 'adjustDates' => $adjustDates]);

        foreach ($data as $key => $value) {
            if ($key === 'all_day') {
                $value = $this->convertBoolean($value);
            }

            // Handle date fields
            if (in_array($key, ['start_datetime', 'end_datetime', 'due_date'])) {
                if ($adjustDates) {
                    // Adjust date to current date while preserving time
                    $value = $this->adjustDateToCurrent($value);
                } else {
                    // Parse date normally
                    $value = $this->parseDate($value);
                }
            }

            if (in_array(strtolower($value), ['null'])) {
                $value = null;
            }
            $converted[$key] = $value;
        }

        Log::debug('Row after conversion: ', ['converted' => $converted]);
        return $converted;
    }

    private function adjustDateToCurrent($value): ?string {
        try {
            $dateTime = new \DateTime($value);

            // Get current date
            $currentDate = new \DateTime('now', new \DateTimeZone('UTC'));

            // Replace the date portion with current date while preserving time
            $dateTime->setDate(
                (int)$currentDate->format('Y'),
                (int)$currentDate->format('m'),
                (int)$currentDate->format('d')
            );

            return $dateTime->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            Log::warning("Failed to adjust date", ['value' => $value, 'error' => $e->getMessage()]);

            // If date parsing fails, return current datetime
            try {
                $currentDateTime = new \DateTime('now', new \DateTimeZone('UTC'));
                return $currentDateTime->format('Y-m-d H:i:s');
            } catch (\Exception $fallbackError) {
                Log::error("Failed to create fallback current date", ['error' => $fallbackError->getMessage()]);
                return null;
            }
        }
    }

    private function parseDate($value): ?string {
        try {
            $dateTime = new \DateTime($value);
            $dateTime->setTimezone(new \DateTimeZone('UTC'));
            return $dateTime->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            Log::warning("Failed to parse date", ['value' => $value, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function convertBoolean($value) {
        if (is_string($value)) {
            $valueLower = strtolower(trim($value));
            if ($valueLower === 'true') return 1;
            if ($valueLower === 'false') return 0;

            $boolVal = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            return $boolVal !== null ? (int)$boolVal : 0;
        }
        return (int)filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function limitFieldSizes(array $data): array {
        $maxSizes = [
            'string' => 255,
            'text' => 65535,
            'email' => 255,
        ];

        // Read the casts array from the Task Model for the types
        $taskCasts = (new Task())->getCasts();
        $limited = [];

        foreach ($data as $key => $value) {
            AppLogger::debug($key . ": " . strlen($value));
            // Skip null values
            if ($value === null) {
                $limited[$key] = $value;
                continue;
            }

            // Get the cast type from Task model, default to 'string'
            $castType = $taskCasts[$key] ?? 'string';

            // Skip non-string/text casts (datetime, integer, boolean, etc.)
            if (!in_array($castType, ['string', 'text'])) {
                $limited[$key] = $value;
                continue;
            }

            // Determine max size based on field name or cast type
            if (isset($maxSizes[$key])) {
                $maxSize = $maxSizes[$key];
            } else {
                // Use cast-specific size or default to 'string' size
                $maxSize = $maxSizes[$castType] ?? $maxSizes['string'];
            }
            AppLogger::debug('max size: ' . $maxSize);

            // Convert to string for truncation
            $stringValue = (string)$value;

            if (strlen($stringValue) > $maxSize) {
                // Calculate available size for actual content (reserving 3 chars for "...")
                $truncateSize = max(0, $maxSize - 3);
                $truncatedValue = substr($stringValue, 0, $truncateSize);

                // Add "..." only if we actually truncated something
                if ($truncateSize > 0) {
                    $limited[$key] = $truncatedValue . '...';
                } else {
                    $limited[$key] = '...';
                }

                AppLogger::warning("Field '{$key}' truncated to {$maxSize} characters");
            } else {
                // Keep original type if not truncated
                $limited[$key] = $value;
            }
            AppLogger::debug('After truncation:');
            AppLogger::debug($key . ": " . strlen($value));
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

        $ids = [];
        DB::beginTransaction();
        try {
            foreach ($data as $rowData) {
                Log::debug('Inserting row', [
                    'model_class' => $modelClass,
                    'row_data' => $rowData,
                    'has_uploaded' => isset($rowData['uploaded']),
                    'uploaded_value' => $rowData['uploaded'] ?? null
                ]);

                $model = new $modelClass();
                $model->fill($rowData);
                $model->save();
                $ids[] = $model->getKey();
            }
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
