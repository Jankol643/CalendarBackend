<?php

namespace App\Http\Controllers;

use App\Models\Calendar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CalendarController extends Controller {
    // Retrieve all calendars for the authenticated user
    public function index() {
        try {
            $calendars = Calendar::where('user_id', Auth::id())->get();

            return response()->json([
                'success' => true,
                'data' => $calendars,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch calendars.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Create a new calendar for the authenticated user
    public function store(Request $request) {
        try {
            $request->validate([
                'name' => 'required|string|max:255', // Example: Add a name field for the calendar
            ]);

            $calendar = Calendar::create([
                'user_id' => Auth::id(),
                'name' => $request->input('name'),
            ]);

            return response()->json([
                'success' => true,
                'data' => $calendar,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create calendar.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Show a specific calendar (only if it belongs to the authenticated user)
    public function show(Calendar $calendar) {
        try {
            $this->authorize('view', $calendar); // Ensure the user owns the calendar

            return response()->json([
                'success' => true,
                'data' => $calendar,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch calendar.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Update a specific calendar (only if it belongs to the authenticated user)
    public function update(Request $request, Calendar $calendar) {
        try {
            $this->authorize('update', $calendar); // Ensure the user owns the calendar

            $request->validate([
                'name' => 'sometimes|required|string|max:255',
            ]);

            $calendar->update($request->only('name'));

            return response()->json([
                'success' => true,
                'data' => $calendar,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update calendar.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Delete a specific calendar (only if it belongs to the authenticated user)
    public function destroy(Calendar $calendar) {
        try {
            $this->authorize('delete', $calendar); // Ensure the user owns the calendar

            $calendar->delete();

            return response()->json([
                'success' => true,
                'message' => 'Calendar deleted successfully.',
            ], 204);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete calendar.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
