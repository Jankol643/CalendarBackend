<?php

namespace App\Http\Controllers;

use DateTimeZone;
use Illuminate\Http\JsonResponse;

class TimezoneController extends Controller {
    public function getTimezones(): JsonResponse {
        $timezones = [];

        // Retrieve list of identifiers
        $identifiers = DateTimeZone::listIdentifiers();

        foreach ($identifiers as $timezoneIdentifier) {
            // Validate timezone identifier
            if (!in_array($timezoneIdentifier, DateTimeZone::listIdentifiers())) {
                continue; // TODO: Log invalid timezone if necessary
            }

            try {
                // Create DateTime object for current time in the timezone
                $dateTime = new \DateTime('now', new DateTimeZone($timezoneIdentifier));
            } catch (\Exception $e) {
                // TODO: Handle exceptions, possibly log and skip
                continue;
            }

            // Get the offset in seconds
            $offsetSeconds = $dateTime->getOffset();

            // Calculate hours and minutes
            $hours = intdiv($offsetSeconds, 3600);
            $minutes = abs(($offsetSeconds % 3600) / 60); // cast to int

            // Format offset with correct sign
            $sign = $offsetSeconds >= 0 ? '+' : '-';

            // Zero-pad hours and minutes
            $formattedOffset = sprintf('GMT%s%02d:%02d', $sign, abs($hours), $minutes);

            // Optional: get abbreviation (like EST, PST)
            $abbreviation = $dateTime->format('T');

            // Add to list
            $timezones[] = [
                'name' => $timezoneIdentifier,
                'utcOffset' => $formattedOffset,
                'abbreviation' => $abbreviation, // TODO: Decide if needed
                // 'isDst' => $dateTime->format('I') == '1', // DST indicator if needed
            ];
        }

        // Sort by offset (numeric), then by name
        usort($timezones, function ($a, $b) {
            // Extract numeric offset for comparison
            preg_match('/GMT([+-])(\d{2}):(\d{2})/', $a['utcOffset'], $aMatch);
            preg_match('/GMT([+-])(\d{2}):(\d{2})/', $b['utcOffset'], $bMatch);

            $aOffset = ($aMatch[1] === '+' ? 1 : -1) * (int)$aMatch[2] * 3600 + (int)$aMatch[3] * 60;
            $bOffset = ($bMatch[1] === '+' ? 1 : -1) * (int)$bMatch[2] * 3600 + (int)$bMatch[3] * 60;

            if ($aOffset === $bOffset) {
                return strcmp($a['name'], $b['name']);
            }
            return $aOffset <=> $bOffset;
        });

        // TODO: Optionally, cache results for performance if needed

        return response()->json($timezones);
    }
}