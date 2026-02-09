<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CsvImportService {
    public function __construct(
        private CsvValidator $csvValidator,
        private CsvProcessor $csvProcessor
    ) {
    }

    public function import(UploadedFile $file, bool $adjustDates, ?string $uploadId = null): JsonResponse {
        try {
            // Validate upload ID first
            if ($uploadId && !$this->csvValidator->isValidUploadId($uploadId)) {
                return response()->json(['error' => 'Invalid upload ID format'], 400);
            }

            // Validate file
            $this->csvValidator->validateUploadedFile($file);

            // Process file
            $result = $this->csvProcessor->process($file, $adjustDates, $uploadId);

            return response()->json([
                'events' => $result['events'],
                'tasks' => $result['tasks'],
                'summary' => $result['summary']
            ], 200);
        } catch (\InvalidArgumentException $e) {
            AppLogger::warning('CSV import validation failed', [
                'error' => $e->getMessage(),
                'upload_id' => $uploadId
            ]);
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            AppLogger::error('CSV import failed', [
                'error' => $e->getMessage(),
                'upload_id' => $uploadId,
                'file_name' => $file->getClientOriginalName()
            ]);
            return response()->json([
                'error' => 'Failed to process CSV file',
                'message' => config('app.debug') ? $e->getMessage() : 'Please try again later'
            ], 500);
        }
    }
}
