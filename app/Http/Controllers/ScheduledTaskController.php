<?php

namespace App\Http\Controllers;

use App\Models\ScheduledTask;
use Illuminate\Http\Request;

class ScheduledTaskController extends Controller {
    /**
     * Get all scheduled tasks for a specific calendar.
     */
    public function getAll($calendarId) {
        $scheduledTasks = ScheduledTask::with(['task'])
            ->where('calendar_id', $calendarId)
            ->orderBy('start_datetime')
            ->get();

        return response()->json($scheduledTasks);
    }

    /**
     * Get scheduled tasks by upload ID for a specific calendar.
     */
    public function getByUploadId(Request $request, $calendarId) {
        $uploadId = $request->header('X-Upload-ID');

        if (!$uploadId) {
            return response()->json(['error' => 'Missing X-Upload-ID header'], 400);
        }

        $scheduledTasks = ScheduledTask::with(['task'])
            ->where('calendar_id', $calendarId)
            ->byUpload($uploadId)
            ->orderBy('start_datetime')
            ->get();

        return response()->json($scheduledTasks);
    }

    /**
     * Get scheduled tasks by date range for a specific calendar.
     */
    public function getByDateRange(Request $request, $calendarId) {
        $uploadId = $request->header('X-Upload-ID');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        if (!$uploadId || !$startDate || !$endDate) {
            return response()->json(['error' => 'Missing required parameters'], 400);
        }

        $scheduledTasks = ScheduledTask::with(['task'])
            ->where('calendar_id', $calendarId)
            ->byUpload($uploadId)
            ->betweenDates($startDate, $endDate)
            ->orderBy('start_datetime')
            ->get();

        return response()->json($scheduledTasks);
    }
}
