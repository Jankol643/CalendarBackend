<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class FileSecurityScanner {
    public function scanFileForMalware(string $filePath): bool {
        // Check file extension against whitelist
        if (!$this->hasAllowedExtension($filePath)) {
            return false;
        }

        // Check for suspicious patterns
        if ($this->containsSuspiciousPatterns($filePath)) {
            return false;
        }

        // Check file size consistency
        if (!$this->hasValidFileSize($filePath)) {
            return false;
        }

        return true;
    }

    private function hasAllowedExtension(string $filePath): bool {
        $allowedExtensions = ['csv', 'txt'];
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($extension, $allowedExtensions);
    }

    private function containsSuspiciousPatterns(string $filePath): bool {
        $content = file_get_contents($filePath);
        $suspiciousPatterns = [
            '/<\?php/i',
            '/<script/i',
            '/javascript:/i',
            '/vbscript:/i'
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                AppLogger::warning("Potential malicious content detected in file: {$filePath}");
                return true;
            }
        }

        return false;
    }

    private function hasValidFileSize(string $filePath): bool {
        $fileSize = filesize($filePath);
        return $fileSize > 0 && $fileSize <= 10 * 1024 * 1024; // 10MB max
    }
}
