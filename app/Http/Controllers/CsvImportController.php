<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCsvImportJob;
use App\Services\CsvImportService;
use App\Services\CsvValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CsvImportController extends Controller {
    protected $csvValidator;

    public function __construct(CsvValidator $csvValidator) {
        $this->csvValidator = $csvValidator;
    }

    public function readFromCSV(Request $request) {
        // Determine which file to process
        if (!$request->hasFile('event_input') && !$request->hasFile('task_input')) {
            Log::warning('No file uploaded in request.');
            return response()->json(['error' => 'No file uploaded.'], 422);
        }

        if ($request->hasFile('event_input')) {
            $file = $request->file('event_input');
            $fileType = 'event_input';
        } elseif ($request->hasFile('task_input')) {
            $file = $request->file('task_input');
            $fileType = 'task_input';
        }

        // Validate the uploaded file using the validator
        try {
            $this->csvValidator->validateUploadedFile($file);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $uploadId = uniqid('csv_import_');

        $csvImportService = app(CsvImportService::class);
        $result = $csvImportService->import($file, $uploadId);

        return response()->json([
            'message' => 'CSV file processed successfully',
            'upload_id' => $uploadId,
            'data' => $result,
            'status' => 'completed'
        ]);
    }
}
