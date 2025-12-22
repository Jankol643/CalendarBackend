<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Calendar;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Services\ScheduleService;
use App\Http\Controllers\Helpers\UploadException;
use App\Services\AppLogger;
use App\Services\CsvImportService;
use App\Services\FileHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class EventController extends Controller {
    /**
     * Helper method to validate event request data.
     */
    private function validateEventRequest(Request $request, $isUpdate = false) {
        $rules = [
            'title' => 'required|string',
            'start_datetime' => 'required|date_format:Y-m-d\TH:i:s\Z',
            'end_datetime' => [
                'required',
                'date_format:Y-m-d\TH:i:s\Z',
                // For update, we'll add a custom validation for order
            ],
            'calendar_id' => 'required|exists:calendars,id',
            'timezone' => 'required|string'
        ];

        if ($isUpdate) {
            // For update, validation can be more flexible
            $rules['start_datetime'] = 'sometimes|nullable|date_format:Y-m-d\TH:i:s\Z';
            $rules['end_datetime'] = 'sometimes|nullable|date_format:Y-m-d\TH:i:s\Z|after_or_equal:start_datetime';
        } else {
            // For creation, validate that end_date is after start_date
            $rules['end_datetime'] = 'required|date_format:Y-m-d\TH:i:s\Z|after:start_datetime';
        }

        // Additional optional fields
        $rules['description'] = 'sometimes|nullable|string';
        $rules['location'] = 'sometimes|nullable|string';

        $request->validate($rules);
    }

    /**
     * Utility method to parse ISO8601 date strings into UTC Carbon instances.
     */
    private function parseDateTimeToUTC($datetimeStr) {
        return Carbon::createFromFormat('Y-m-d\TH:i:s\Z', $datetimeStr, 'UTC')->setTimezone('UTC');
    }

    public function index($calendarId) {
        AppLogger::debug('Entering EventController@index', ['calendarId' => $calendarId, 'user_id' => Auth::id()]);

        try {
            $calendar = Calendar::findOrFail($calendarId);
            $this->authorize('view', $calendar);

            // TODO: Implement pagination for large datasets
            $events = Event::where('calendar_id', $calendar->id)->get();

            AppLogger::info('Fetched events for calendar', ['calendar_id' => $calendar->id, 'event_count' => $events->count()]);
            return response()->json($events);
        } catch (ModelNotFoundException $e) {
            AppLogger::warning('Calendar not found in EventController@index', ['calendarId' => $calendarId, 'user_id' => Auth::id()]);
            return response()->json(['error' => 'Calendar not found.'], 404);
        } catch (\Exception $e) {
            AppLogger::error('Unexpected error in EventController@index', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'stack' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'An unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }

    public function store(Request $request) {
        AppLogger::debug('Entering EventController@store', ['user_id' => Auth::id(), 'request_data' => $request->all()]);

        $this->validateEventRequest($request);

        DB::beginTransaction();
        try {
            $calendar = Calendar::findOrFail($request->input('calendar_id'));
            $this->authorize('view', $calendar);

            $timezone = $request->input('timezone');
            // TODO: Validate that timezone is a valid timezone string
            // TODO: Handle invalid timezone gracefully

            $startDate = $this->parseDateTimeToUTC($request->input('start_datetime'));
            $endDate = $this->parseDateTimeToUTC($request->input('end_datetime'));

            if ($startDate->gt($endDate)) {
                throw ValidationException::withMessages(['start_datetime' => 'Start date must be before or equal to end date.']);
            }

            $event = Event::create([
                'title' => $request->input('title'),
                'description' => $request->input('description', null),
                'location' => $request->input('location', null),
                'start_datetime' => $startDate,
                'end_datetime' => $endDate,
                'timezone' => $timezone,
                'calendar_id' => $calendar->id
            ]);

            // TODO: Check for overlapping events before creation

            DB::commit();
            AppLogger::info('Event created successfully', ['event_id' => $event->id, 'user_id' => Auth::id()]);
            return response()->json($event, 201);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            AppLogger::warning('Calendar not found in EventController@store', ['calendar_id' => $request->input('calendar_id'), 'user_id' => Auth::id()]);
            return response()->json(['error' => 'Calendar not found.'], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            AppLogger::error('Error creating event in EventController@store', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'stack' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'An unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }

    public function show($calendarId, $id) {
        AppLogger::debug('Entering EventController@show', ['calendarId' => $calendarId, 'eventId' => $id, 'user_id' => Auth::id()]);

        try {
            $calendar = Calendar::findOrFail($calendarId);
            // TODO: Verify user has permission to view this calendar
            $this->authorize('view', $calendar);

            $event = Event::where('calendar_id', $calendar->id)->findOrFail($id);

            // TODO: Validate event belongs to calendar explicitly
            // TODO: Validate event ID format if necessary

            $userTimezone = Auth::user()->timezone ?? 'UTC';

            // TODO: Use consistent date attribute names, e.g., 'start_datetime' instead of 'start_date'
            $startDate = Carbon::createFromFormat('Y-m-d H:i:s', $event->start_date, 'UTC')
                ->setTimezone($userTimezone)
                ->format('Y-m-d\TH:i:s\Z');
            $endDate = Carbon::createFromFormat('Y-m-d H:i:s', $event->end_date, 'UTC')
                ->setTimezone($userTimezone)
                ->format('Y-m-d\TH:i:s\Z');

            AppLogger::info('Event retrieved', ['event_id' => $event->id, 'user_id' => Auth::id()]);
            return response()->json([
                'id' => $event->id,
                'title' => $event->title,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'location' => $event->location,
                'description' => $event->description,
                'timezone' => $event->timezone,
            ]);
        } catch (ModelNotFoundException $e) {
            AppLogger::warning('Event or Calendar not found in EventController@show', ['calendarId' => $calendarId, 'eventId' => $id, 'user_id' => Auth::id()]);
            return response()->json(['error' => 'Event or Calendar not found.'], 404);
        } catch (\Exception $e) {
            AppLogger::error('Unexpected error in EventController@show', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'stack' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'An unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $calendarId, $id) {
        AppLogger::debug('Entering EventController@update', ['calendarId' => $calendarId, 'eventId' => $id, 'user_id' => Auth::id()]);

        $this->validateEventRequest($request, true);

        DB::beginTransaction();
        try {
            $calendar = Calendar::findOrFail($calendarId);
            // TODO: Confirm user has permission to update this calendar
            $this->authorize('view', $calendar);

            $event = Event::where('calendar_id', $calendar->id)->findOrFail($id);

            $data = $request->all();

            if (isset($data['start_datetime'])) {
                $data['start_date'] = $this->parseDateTimeToUTC($data['start_datetime']);
                unset($data['start_datetime']);
            }

            if (isset($data['end_datetime'])) {
                $data['end_date'] = $this->parseDateTimeToUTC($data['end_datetime']);
                unset($data['end_datetime']);
            }

            // TODO: Validate that start_date <= end_date after update
            if (isset($data['start_date']) && isset($data['end_date'])) {
                if ($data['start_date']->gt($data['end_date'])) {
                    throw ValidationException::withMessages(['start_datetime' => 'Start date must be before or equal to end date.']);
                }
            }

            // TODO: Whitelist fields that can be updated
            $allowedFields = ['title', 'description', 'location', 'start_date', 'end_date', 'timezone'];
            $updateData = array_intersect_key($data, array_flip($allowedFields));

            $event->update($updateData);

            // TODO: Check for overlapping events after update

            DB::commit();
            AppLogger::info('Event updated successfully', ['event_id' => $event->id, 'user_id' => Auth::id()]);
            return response()->json($event);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            AppLogger::warning('Event or Calendar not found in EventController@update', ['calendarId' => $calendarId, 'eventId' => $id, 'user_id' => Auth::id()]);
            return response()->json(['error' => 'Event or Calendar not found.'], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            AppLogger::error('Error updating event in EventController@update', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'stack' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'An unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }

    public function destroy($calendarId, $id) {
        AppLogger::debug('Entering EventController@destroy', ['calendarId' => $calendarId, 'eventId' => $id, 'user_id' => Auth::id()]);

        DB::beginTransaction();
        try {
            $calendar = Calendar::findOrFail($calendarId);
            $this->authorize('delete', $calendar);

            $event = Event::where('calendar_id', $calendar->id)->findOrFail($id);
            $event->delete();

            DB::commit();
            AppLogger::info('Event deleted', ['event_id' => $event->id, 'user_id' => Auth::id()]);
            return response()->json(null, 204);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            AppLogger::warning('Event or Calendar not found in EventController@destroy', ['calendarId' => $calendarId, 'eventId' => $id, 'user_id' => Auth::id()]);
            return response()->json(['error' => 'Event or Calendar not found.'], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            AppLogger::error('Error deleting event in EventController@destroy', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'stack' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'An unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }
}
