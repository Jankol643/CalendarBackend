<?php

namespace App\Http\Controllers;

use App\Models\Calendar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CalendarController extends Controller {
    public function index() {
        $calendars = Calendar::where('user_id', Auth::id())->get();
        return response()->json($calendars);
    }

    public function store(Request $request) {
        $request->validate(['user_id' => 'required|exists:users,id']);
        $calendar = Calendar::create(['user_id' => $request->user_id]);
        return response()->json($calendar, 201);
    }

    public function show($id) {
        $calendar = Calendar::findOrFail($id);
        return response()->json($calendar);
    }

    public function update(Request $request, $id) {
        $calendar = Calendar::findOrFail($id);
        $request->validate(['user_id' => 'sometimes|required|exists:users,id']);
        $calendar->update($request->only('user_id'));
        return response()->json($calendar);
    }

    public function destroy($id) {
        $calendar = Calendar::findOrFail($id);
        $calendar->delete();
        return response()->json(null, 204);
    }

    public function getUserCalendars() {
        $calendars = Calendar::where('user_id', Auth::id())->get();
        return response()->json($calendars);
    }
}
