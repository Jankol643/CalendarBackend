<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EventController extends Controller {
    public function index(): JsonResponse {
        $events = Event::all();
        return response()->json($events, 200);
    }

    function store(Request $request): JsonResponse {
        $request = $this->parseRequest($request);
        DB::beginTransaction();
        try {
            $event = Event::create($request->all());
            DB::commit();
            return response()->json($event, 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json("saving event failed: " . $e->getMessage(), 420);
        }
    }

    public function delete(int $id): JsonResponse {
        $event = Event::where('id', $id)->first();
        if ($event != null) {
            $event->delete();
            return response()->json('event (' . $id . ', ' . $event->title . ') successfully deleted', 200);
        } else {
            return response()->json('event could not be deleted - it does not exist', 422);
        }
    }

    public function parseRequest(Request $request): Request {
        return $request;
    }

    function getFullCalendarEvents() {
        return Event::toFullCalendarFormat();
    }

    function update(Request $request, $id): JsonResponse {
        DB::beginTransaction();
        try {
            $event = Event::where('id', $id)->first();
            if ($event != null) {
                $event->update($request->all());
                $event->save();
            }
            DB::commit();
            $event1 = Event::where('id', $id)->first();
            return response()->json($event1, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json("updating event failed: " . $e->getMessage(), 420);
        }
    }

    public function findById(int $id) {
        $event = Event::where('id', $id)->first();
        return $event != null ? response()->json($event, 200) : response()->json(null, 200);
    }

    function searchEvents(Request $request) {
        $query = $request->get('query');
        $events = Event::where('event_name', 'LIKE', '%' . $query . '%')->get();
        return response()->json($events);
    }

    function getEvents($includeTimestamps = false) {
        $query = Event::query();
        if ($includeTimestamps) {
            $query->addSelect('created_at', 'updated_at');
        }
        return $query->get();
    }
}
