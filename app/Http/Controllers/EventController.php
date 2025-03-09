<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Calendar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class EventController extends Controller {
    public function index($calendarId) {
        try {
            // Authorize access to the calendar using the policy
            $calendar = Calendar::findOrFail($calendarId);
            $this->authorize('view', $calendar);

            // Fetch events for the authorized calendar
            $events = Event::where('calendar_id', $calendar->id)->get();
            return response()->json($events);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Calendar not found.'], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }

    public function store(Request $request) {
        $request->validate([
            'title' => 'required|string',
            'start_date' => 'required|date_format:Y-m-d\TH:i:s.u\Z',
            'end_date' => 'required|date_format:Y-m-d\TH:i:s.u\Z|after:start_date',
            'calendar_id' => 'required|exists:calendars,id',
        ]);

        DB::beginTransaction();
        try {
            // Authorize access to the calendar using the policy
            $calendar = Calendar::findOrFail($request->input('calendar_id'));
            $this->authorize('view', $calendar);

            // Convert ISO 8601 to MySQL-compatible format
            $startDate = Carbon::parse($request->input('start_date'))->toDateTimeString();
            $endDate = Carbon::parse($request->input('end_date'))->toDateTimeString();

            // Create the event
            $event = Event::create([
                'title' => $request->input('title'),
                'description' => $request->input('description'),
                'location' => $request->input('location'),
                'start_date' => $startDate,
                'end_date' => $endDate,
                'timezone' => $request->input('timezone'),
                'calendar_id' => $calendar->id
            ]);

            DB::commit();
            return response()->json($event, 201);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json(['error' => 'Calendar not found.'], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'An unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }

    public function show($calendarId, $id) {
        try {
            $calendar = Calendar::findOrFail($calendarId);
            $this->authorize('view', $calendar);

            $event = Event::where('calendar_id', $calendar->id)->findOrFail($id);

            // Convert event dates to user's local timezone
            $userTimezone = Auth::user()->timezone; // or get from user settings
            $startDate = Carbon::createFromFormat('Y-m-d H:i:s', $event->start_date, 'UTC')
                ->setTimezone($userTimezone)
                ->format('Y-m-d\TH:i:s.u\Z');
            $endDate = Carbon::createFromFormat('Y-m-d H:i:s', $event->end_date, 'UTC')
                ->setTimezone($userTimezone)
                ->format('Y-m-d\TH:i:s.u\Z');

            return response()->json([
                'title' => $event->title,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'location' => $event->location,
                'description' => $event->description,
                'timezone' => $event->timezone,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Event or Calendar not found.'], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $calendarId, $id) {
        DB::beginTransaction();
        try {
            // Authorize access to the calendar using the policy
            $calendar = Calendar::findOrFail($calendarId);
            $this->authorize('view', $calendar);

            // Fetch and update the event
            $event = Event::where('calendar_id', $calendar->id)->findOrFail($id);
            $event->update($request->all());

            DB::commit();
            return response()->json($event);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json(['error' => 'Event or Calendar not found.'], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'An unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }

    public function destroy($calendarId, $id) {
        DB::beginTransaction();
        try {
            // Authorize access to the calendar using the policy
            $calendar = Calendar::findOrFail($calendarId);
            $this->authorize('view', $calendar);

            // Fetch and delete the event
            $event = Event::where('calendar_id', $calendar->id)->findOrFail($id);
            $event->delete();

            DB::commit();
            return response()->json(null, 204);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json(['error' => 'Event or Calendar not found.'], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'An unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }
}
