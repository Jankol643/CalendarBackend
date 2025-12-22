<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;

class DuplicateDetectionService {
    public function isDuplicateUpload(UploadedFile $file): bool {
        $fileHash = hash_file('sha256', $file->getPathname());
        $cacheKey = 'csv_upload_hash:' . $fileHash;
        $filenameKey = 'csv_upload_filename:' . md5($file->getClientOriginalName());

        return Cache::has($cacheKey) || Cache::has($filenameKey);
    }

    public function storeFileHash(UploadedFile $file): void {
        $fileHash = hash_file('sha256', $file->getPathname());
        $cacheKey = 'csv_upload_hash:' . $fileHash;
        $filenameKey = 'csv_upload_filename:' . md5($file->getClientOriginalName());

        Cache::put($cacheKey, true, 3600); // 1 hour
        Cache::put($filenameKey, true, 3600); // 1 hour
    }
}
