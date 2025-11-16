<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\UploadException;
use App\Models\Event;
use App\Models\Task;
use App\Services\CsvImportService;
use App\Services\FileHandler;
use App\Services\ScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

class CsvImportController extends Controller {
    /**
     * Read CSV data from uploaded file.
     */
    public function readFromCSV(Request $request): JsonResponse {
        $logs = [];
        Log::info('Starting readFromCSV request.');

        if (!$request->hasFile('event_input') && !$request->hasFile('task_input')) {
            Log::warning('No file uploaded in request.');
            return $this->errorResponse('No file uploaded.', $logs, 422);
        }

        if ($request->hasFile('event_input')) {
            Log::info('Processing event_input file.');
            return $this->processFileUpload($request, $request->file('event_input'), $logs);
        } elseif ($request->hasFile('task_input')) {
            Log::info('Processing task_input file.');
            return $this->processFileUpload($request, $request->file('task_input'), $logs);
        }

        Log::warning('No valid file found after checks.');
        return $this->errorResponse('No valid file found.', $logs, 422);
    }

    /**
     * Core method to process file upload, validation, reading, and session storage.
     */
    private function processFileUpload(Request $request, $file, array &$logs): JsonResponse {
        try {
            Log::debug('Creating FileHandler instance.');
            $fileHandler = new FileHandler($file);
            Log::debug('Validating file.');
            $fileHandler->validate();

            Log::debug('Getting file info.');
            $info = $fileHandler->getFileInfo();

            Log::debug('Saving file.');
            $path = $fileHandler->saveFile();
            $logs = $fileHandler->getLogs();

            $csvImportService = new CsvImportService();
            $headers = $request->headers->all(); // or specific header
            Log::debug('Headers from controller: ');
            Log::debug(json_encode($headers)); // Use json_encode instead of print_r
            $csvImportService->import($path, $headers);

            Log::info('File uploaded and processed successfully.');
            return response()->json([
                'info' => $info,
                'logs' => $logs,
            ], 200);
        } catch (ValidationException $e) {
            $this->logException($e, 'ValidationException');
            $logs[] = $this->formatExceptionLog($e, 'ValidationException');
            return $this->errorResponse('Invalid file upload.', $logs, 422, $e->errors());
        } catch (UploadException $e) {
            $this->logException($e, 'UploadException');
            $logs[] = $this->formatExceptionLog($e, 'UploadException');
            return $this->errorResponse($e->getMessage(), $logs, 400);
        } catch (\Exception $e) {
            $this->logException($e, 'Exception');
            $logs[] = $this->formatExceptionLog($e, 'Exception');
            return $this->errorResponse('An error occurred during upload.', $logs, 500, ['message' => $e->getMessage()]);
        }
    }

    /**
     * Log exception details with actual line number.
     */
    private function logException(\Exception $e, string $type): void {
        $file = __FILE__;
        $line = $e->getLine();
        $message = "{$type} at {$file} line {$line}: " . $e->getMessage();
        Log::error($message);
    }

    /**
     * Format exception details for logs.
     */
    private function formatExceptionLog(\Exception $e, string $type): string {
        $file = __FILE__;
        $line = $e->getLine();
        return "{$type} at {$file} line {$line}: " . $e->getMessage();
    }

    /**
     * Common method to generate error responses.
     */
    private function errorResponse(string $message, array $logs, int $statusCode, array $errors = []): JsonResponse {
        Log::debug('Generating error response: ' . $message);
        return response()->json([
            'error' => $message,
            'messages' => $errors,
            'logs' => $logs,
        ], $statusCode);
    }
}
