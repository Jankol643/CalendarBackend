<?php

namespace App\Http\Controllers;

use DateTimeZone;
use Illuminate\Http\JsonResponse;

class TimezoneController extends Controller {
    public function getTimezones(): JsonResponse {
        $timezones = array_map(function ($timezone) {
            $dateTime = new \DateTime('now', new DateTimeZone($timezone));
            $offset = $dateTime->getOffset();
            $hours = intdiv($offset, 3600);
            $minutes = abs($offset % 3600 / 60);
            $formattedOffset = sprintf('GMT%+03d:%02d', $hours, $minutes);

            return [
                'name' => $timezone,
                'offset' => $formattedOffset,
            ];
        }, DateTimeZone::listIdentifiers());

        // Sort the timezones by offset first, then by name
        usort($timezones, function ($a, $b) {
            // Compare by offset
            $offsetComparison = strcmp($a['offset'], $b['offset']);
            if ($offsetComparison === 0) {
                // If offsets are the same, compare by name
                return strcmp($a['name'], $b['name']);
            }
            return $offsetComparison;
        });

        return response()->json($timezones);
    }
}
