<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CsvValidator {
    private int $maxFileSize;
    private int $maxLines;

    public function __construct() {
        $this->maxFileSize = config('csv_import.max_file_size', 10 * 1024 * 1024);
        $this->maxLines = config('csv_import.max_lines', 10000);
    }

    public function validateUploadedFile(UploadedFile $file): void {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('Invalid file upload: ' . $file->getErrorMessage());
        }

        $this->validateFileName($file->getClientOriginalName());

        if ($file->getSize() > $this->maxFileSize) {
            $maxSizeMb = $this->maxFileSize / (1024 * 1024);
            throw new \InvalidArgumentException("File exceeds maximum size of {$maxSizeMb}MB.");
        }
    }

    public function validateFileName(string $filename): void {
        if (!$this->isValidCsvExtension($filename)) {
            throw new \InvalidArgumentException('Invalid file extension. Only .csv files are allowed.');
        }
    }

    private function isValidCsvExtension(string $filename): bool {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'csv';
    }

    public function isValidUploadId(?string $uploadId): bool {
        if (!$uploadId) {
            return false;
        }

        // UUID format
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uploadId)) {
            return true;
        }

        // Alphanumeric with reasonable length
        return preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $uploadId);
    }

    public function validateCsvHeaders(array $headers): string {
        Log::info('validateCsvHeaders called with headers:', $headers);

        if (empty($headers)) {
            throw new \InvalidArgumentException('CSV header is empty');
        }

        // FIX: Remove BOM from first header if present
        if (!empty($headers[0])) {
            $headers[0] = $this->removeBom($headers[0]);
            Log::info('First header after BOM removal:', [$headers[0]]);
        }

        $headerLower = array_map('strtolower', $headers);
        Log::info('Headers converted to lowercase:', $headerLower);

        $eventRequired = $this->getRequiredFields(\App\Models\Event::class);
        $taskRequired = $this->getRequiredFields(\App\Models\Task::class);

        Log::info('Event required fields:', $eventRequired);
        Log::info('Task required fields:', $taskRequired);

        $eventRequiredLower = array_map('strtolower', $eventRequired);
        $taskRequiredLower = array_map('strtolower', $taskRequired);

        Log::info('Event required fields (lowercase):', $eventRequiredLower);
        Log::info('Task required fields (lowercase):', $taskRequiredLower);

        $missingEventFields = array_diff($eventRequiredLower, $headerLower);
        Log::info('Missing event fields (after diff):', array_values($missingEventFields));

        if (empty($missingEventFields)) {
            Log::info('Event validation passed - no missing fields');
            return \App\Models\Event::class;
        }

        $missingTaskFields = array_diff($taskRequiredLower, $headerLower);
        Log::info('Missing task fields (after diff):', array_values($missingTaskFields));

        if (empty($missingTaskFields)) {
            Log::info('Task validation passed - no missing fields');
            return \App\Models\Task::class;
        }

        // FIX: Use the lowercase arrays for the actual missing fields in error message
        throw new \InvalidArgumentException(
            'CSV header is missing required fields. ' .
                'Missing for Event: ' . implode(', ', array_diff($eventRequiredLower, $headerLower)) . '. ' .
                'Missing for Task: ' . implode(', ', array_diff($taskRequiredLower, $headerLower))
        );
    }

    /**
     * Remove UTF-8 BOM from string
     */
    private function removeBom(string $string): string {
        // Alternative method if above doesn't work
        if (substr($string, 0, 3) == "\xEF\xBB\xBF") {
            $string = substr($string, 3);
        }
        return $string;
    }

    public function validateRowData(array $data, string $modelClass): bool {
        try {
            $modelInstance = new $modelClass();

            $rules = method_exists($modelInstance, 'getValidationRules')
                ? $modelInstance->getValidationRules()
                : $this->getDefaultValidationRules($data);

            $validator = Validator::make($data, $rules);
            return !$validator->fails();
        } catch (\Exception $e) {
            // Log or handle as needed
            return false;
        }
    }

    private function getRequiredFields(string $modelClass): array {
        $modelInstance = new $modelClass();

        if (method_exists($modelInstance, 'getValidationRules')) {
            $rules = $modelInstance->getValidationRules();
            $requiredFields = [];

            foreach ($rules as $field => $rule) {
                // Skip system-generated fields
                if (in_array($field, ['uploaded', 'created_at', 'updated_at'])) {
                    continue;
                }

                // Check if field is required
                $isRequired = false;
                if (is_string($rule)) {
                    $isRequired = str_contains($rule, 'required');
                } elseif (is_array($rule)) {
                    $isRequired = in_array('required', $rule);
                }

                if ($isRequired) {
                    $requiredFields[] = $field; // Keep original case
                }
            }

            return $requiredFields;
        }

        // Fallback to fillable fields (excluding system fields)
        $fillable = array_diff($modelInstance->getFillable(), ['uploaded', 'created_at', 'updated_at']);
        return $fillable; // Keep original case
    }

    private function getDefaultValidationRules(array $data): array {
        $rules = [];
        foreach ($data as $key => $value) {
            $rules[$key] = 'nullable';
        }
        return $rules;
    }
}
