<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

class EventController extends Controller {
    public function index($calendarId) {
        try {
            $events = Event::where('calendar_id', $calendarId)->get();
            return response()->json($events);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Unable to retrieve events: ' . $e->getMessage()], 500);
        }
    }

    public function store(Request $request, $calendarId) {
        $request->validate([
            'title' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'calendar_id' => 'required|exists:calendars,id',
        ]);

        DB::beginTransaction();
        try {
            $event = Event::create($request->all());
            DB::commit();
            return response()->json($event, 201);
        } catch (QueryException $e) {
            DB::rollBack();
            return response()->json(['error' => 'Saving event failed: ' . $e->getMessage()], 420);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'An unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }

    public function show($calendarId, $id) {
        try {
            $event = Event::where('calendar_id', $calendarId)->findOrFail($id);
            return response()->json($event);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Event not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Unable to retrieve event: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $calendarId, $id) {
        DB::beginTransaction();
        try {
            $event = Event::where('calendar_id', $calendarId)->findOrFail($id);
            $event->update($request->all());
            DB::commit();
            return response()->json($event);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json(['error' => 'Event not found'], 404);
        } catch (QueryException $e) {
            DB::rollBack();
            return response()->json(['error' => 'Updating event failed: ' . $e->getMessage()], 420);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'An unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }

    public function destroy($calendarId, $id) {
        DB::beginTransaction();
        try {
            $event = Event::where('calendar_id', $calendarId)->findOrFail($id);
            $event->delete();
            DB::commit();
            return response()->json(null, 204);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json(['error' => 'Event not found'], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Unable to delete event: ' . $e->getMessage()], 500);
        }
    }
}
