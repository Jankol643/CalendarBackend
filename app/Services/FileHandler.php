<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\Exception\CannotWriteFileException;
use Symfony\Component\HttpFoundation\File\Exception\UploadException;

class FileHandler {
    protected $file;
    protected $validationRules;
    protected $logs = [];

    public function __construct(UploadedFile $file, array $validationRules = []) {
        Log::debug('Initializing FileHandler with file: ' . $file->getClientOriginalName());
        $this->file = $file;
        $this->validationRules = $validationRules ?: [
            'required',
            'file',
            'mimes:csv,txt',
            'max:2048',
        ];
        Log::info('Validation rules set: ' . json_encode($this->validationRules));
    }

    public function validate(): void {
        Log::debug('Starting validation for file: ' . $this->file->getClientOriginalName());
        $validator = Validator::make(
            ['file' => $this->file],
            ['file' => $this->validationRules]
        );

        if ($validator->fails()) {
            Log::error('Validation failed: ' . json_encode($validator->errors()->all()));
            throw new ValidationException($validator);
        }
        $this->logs['Validation'] = 'Passed';
        Log::info('Validation passed for file: ' . $this->file->getClientOriginalName());
    }

    public function getFileInfo(): array {
        Log::debug('Getting file info for: ' . $this->file->getClientOriginalName());
        $info = [
            'Original Name' => $this->file->getClientOriginalName(),
            'Mime Type' => $this->file->getMimeType(),
            'Size (bytes)' => $this->file->getSize(),
        ];
        $this->logs['File Info'] = $info;
        Log::info('File info retrieved: ' . json_encode($info));
        return $info;
    }

    public function saveFile(): ?string {
        $filename = $this->file->getClientOriginalName();
        Log::debug('Attempting to save file: ' . $filename);
        try {
            // Save the uploaded file to the specified directory
            $path = Storage::putFileAs('/uploads', $this->file, $filename);
            if (!$path) {
                $this->logs['saveFile'] = false;
                Log::error('Failed to write file: ' . $filename);
                throw new CannotWriteFileException('Unable to write file.');
            }
            $this->logs['saveFile'] = true;
            Log::info('File ' . $filename . ' written successfully to path ' . $path);
            return $path;
        } catch (\Exception $e) {
            Log::error('Exception during saveFile: ' . $e->getMessage());
            throw new CannotWriteFileException($e->getMessage());
        }
    }

    public function getLogs(): array {
        Log::debug('Retrieving logs: ' . json_encode($this->logs));
        return $this->logs;
    }
}
