<?php

declare(strict_types = 1);

namespace App\Services;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Request;

final class RateLimitService {

    public function checkRateLimit(Request $request): bool {
        $rateLimitKey = 'csv_upload:' . ($request->user()?->id ?: $request->ip());
        $maxAttempts = 5;
        $decaySeconds = 300;

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            return false;
        }

        RateLimiter::hit($rateLimitKey, $decaySeconds);

        return true;
    }

    public function getRetryAfter(Request $request): int {
        $rateLimitKey = 'csv_upload:' . ($request->user()?->id ?: $request->ip());

        return RateLimiter::availableIn($rateLimitKey);
    }

}
