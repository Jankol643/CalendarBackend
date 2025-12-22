<?php

namespace App\Http\Controllers;

use App\Models\Calendar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;

class CalendarController extends Controller {
    public function __construct() {
        $this->middleware('auth');
        // TODO: Register CalendarPolicy in AuthServiceProvider if not already done
    }

    // Retrieve all calendars for the authenticated user
    public function index() {
        try {
            // TODO: Add pagination to handle large datasets
            $calendars = Calendar::where('user_id', Auth::id())->get();

            // TODO: Implement caching if necessary
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
            // Custom validation messages
            $request->validate([
                'name' => 'required|string|max:255',
            ], [
                'name.required' => 'The calendar name is required.',
                'name.string' => 'The calendar name must be a string.',
                'name.max' => 'The calendar name cannot exceed 255 characters.',
            ]);

            // Sanitize input: Strip tags from the name
            $calendarName = strip_tags($request->input('name'));

            // Check for duplicate calendar names per user
            if (Calendar::where('user_id', Auth::id())->where('name', $calendarName)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'A calendar with this name already exists.',
                ], 409); // Conflict
            }

            // Create the calendar
            $calendar = Calendar::create([
                'user_id' => Auth::id(),
                'name' => $calendarName,
            ]);

            // Log creation action
            AppLogger::info('Calendar created', [
                'user_id' => Auth::id(),
                'calendar_id' => $calendar->id,
                'name' => $calendarName,
            ]);

            return response()->json([
                'success' => true,
                'data' => $calendar,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Handle validation errors separately
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->validator->errors(), // Return validation errors
            ], 422); // Unprocessable Entity
        } catch (\Exception $e) {
            // Handle server errors
            return response()->json([
                'success' => false,
                'message' => 'Failed to create calendar.',
                'error' => $e->getMessage(), // Hide sensitive error details
            ], 500); // Internal Server Error
        }
    }

    // Show a specific calendar (only if it belongs to the authenticated user)
    public function show(Calendar $calendar) {
        try {
            if (Auth::id() !== $calendar->user_id) {
                throw new AuthorizationException('Unauthorized');
            }

            // TODO: Add eager loading if calendar has relations
            return response()->json([
                'success' => true,
                'data' => $calendar,
            ]);
        } catch (AuthorizationException $ae) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access.',
                'error' => $ae->getMessage(),
            ], 403);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch calendar.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, Calendar $calendar) {
        try {
            // Verify ownership using Laravel policies (assuming appropriate policy is defined)
            $this->authorize('update', $calendar);

            // Validate the request with custom messages
            $request->validate([
                'name' => 'sometimes|required|string|max:255'
            ], [
                'name.required' => 'The calendar name is required when updating.',
                'name.string' => 'The calendar name must be a string.',
                'name.max' => 'The calendar name cannot exceed 255 characters.'
            ]);

            // Sanitize input: Strip tags from the name input
            if ($request->has('name')) {
                $calendarName = strip_tags($request->input('name'));
                $calendar->name = $calendarName; // Assign sanitized name
            }

            // Update the calendar
            $calendar->save(); // Save the changes

            // Log the update action
            AppLogger::info('Calendar updated', [
                'user_id' => Auth::id(),
                'calendar_id' => $calendar->id,
                'name' => $calendar->name,
            ]);

            return response()->json([
                'success' => true,
                'data' => $calendar,
            ]);
        } catch (AuthorizationException $ae) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
                'error' => $ae->getMessage(),
            ], 403); // Forbidden
        } catch (\Illuminate\Validation\ValidationException $ve) {
            // Handle validation errors separately
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $ve->validator->errors(),
            ], 422); // Unprocessable Entity
        } catch (\Exception $e) {
            // Handle generic exceptions
            return response()->json([
                'success' => false,
                'message' => 'Failed to update calendar.',
                'error' => $e->getMessage(),
            ], 500); // Internal Server Error
        }
    }

    // Delete a specific calendar (only if it belongs to the authenticated user)
    public function destroy(Calendar $calendar) {
        try {
            $this->authorize('delete', $calendar);

            $calendar->delete();

            AppLogger::debug("Calendar {$calendar->id} deleted sucessfully.");

            return response()->json([
                'success' => true,
                'message' => 'Calendar deleted successfully.',
            ], 204);
        } catch (AuthorizationException $ae) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
                'error' => $ae->getMessage(),
            ], 403);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete calendar.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
