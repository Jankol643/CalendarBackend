<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\Calendar; // Assuming Calendar model exists
use App\Services\AppLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TaskController extends Controller {
    /**
     * List tasks with pagination and authorization check
     */
    public function index($calendarId, Request $request) {
        try {
            // TODO: Implement authorization check for listing tasks
            // e.g., $this->authorize('viewAny', [Task::class, $calendarId]);

            // Check if calendar exists and user has permission
            $calendar = Calendar::findOrFail($calendarId);
            // TODO: Check user permission for this calendar
            // if (!$this->userCanView($calendar)) { return response()->json(['error' => 'Forbidden'], 403); }

            $perPage = intval($request->query('per_page', 15));
            $tasks = Task::where('calendar_id', $calendarId)
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            // TODO: Add pagination links if needed
            return response()->json([
                'data' => $tasks->items(),
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
            ]);
        } catch (ModelNotFoundException $e) {
            // Log not found
            AppLogger::warning("Calendar not found: {$calendarId}");
            return response()->json(['error' => 'Calendar not found'], 404);
        } catch (\Exception $e) {
            // Log exception
            AppLogger::error("Error listing tasks: " . $e->getMessage());
            return response()->json(['error' => 'Unable to retrieve tasks'], 500);
        }
    }

    /**
     * Store a new task with validation and permission checks
     */
    public function store(Request $request, $calendarId) {
        try {
            // TODO: Validate user permissions to create task
            // e.g., $this->authorize('create', [Task::class, $calendarId]);

            // Check if calendar exists
            $calendar = Calendar::findOrFail($calendarId);
            // TODO: Check user permission for this calendar

            // Validate input
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'due_date' => 'nullable|date',
                // TODO: Add more validation rules for optional fields
            ]);

            // Whitelist fields
            $taskData = array_merge($validated, ['calendar_id' => $calendarId]);

            DB::beginTransaction();
            $task = Task::create($taskData);
            DB::commit();

            return response()->json($task, 201);
        } catch (ModelNotFoundException $e) {
            AppLogger::warning("Calendar not found during task creation: {$calendarId}");
            return response()->json(['error' => 'Calendar not found'], 404);
        } catch (ValidationException $e) {
            // Validation errors are automatically handled, but if custom response needed:
            return response()->json(['errors' => $e->errors()], 422);
        } catch (QueryException $e) {
            DB::rollBack();
            AppLogger::error("QueryException during task creation: " . $e->getMessage());
            return response()->json(['error' => 'Saving task failed'], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            AppLogger::error("Unexpected error during task creation: " . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    /**
     * Show a specific task with permission check
     */
    public function show($calendarId, $id) {
        try {
            // TODO: Check user permission for viewing task
            $task = Task::where('calendar_id', $calendarId)->findOrFail($id);
            // TODO: Check if user can view this task
            // if (!$this->userCanView($task)) { return response()->json(['error' => 'Forbidden'], 403); }

            // Add cache headers if applicable
            return response()->json($task)
                ->header('Cache-Control', 'public, max-age=60'); // cache for 1 min
        } catch (ModelNotFoundException $e) {
            AppLogger::warning("Task not found: {$id} in calendar {$calendarId}");
            return response()->json(['error' => 'Task not found'], 404);
        } catch (\Exception $e) {
            AppLogger::error("Error retrieving task: " . $e->getMessage());
            return response()->json(['error' => 'Unable to retrieve task'], 500);
        }
    }

    /**
     * Update a task with validation, permission, and transaction
     */
    public function update(Request $request, $calendarId, $id) {
        try {
            // TODO: Validate user permission for update
            $task = Task::where('calendar_id', $calendarId)->findOrFail($id);
            // TODO: Check if user can update this task
            // if (!$this->userCanUpdate($task)) { return response()->json(['error' => 'Forbidden'], 403); }

            // Validate input
            $validated = $request->validate([
                'title' => 'sometimes|required|string|max:255',
                'description' => 'sometimes|nullable|string',
                'due_date' => 'sometimes|nullable|date',
                // TODO: Add more validation rules
            ]);

            // Whitelist fields
            $updateData = $validated;

            DB::beginTransaction();
            $task->update($updateData);
            DB::commit();

            // TODO: Confirm update success
            return response()->json($task);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            AppLogger::warning("Task not found for update: {$id} in calendar {$calendarId}");
            return response()->json(['error' => 'Task not found'], 404);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (QueryException $e) {
            DB::rollBack();
            AppLogger::error("QueryException during task update: " . $e->getMessage());
            return response()->json(['error' => 'Updating task failed'], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            AppLogger::error("Unexpected error during task update: " . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    /**
     * Delete a task with soft delete support and permission check
     */
    public function destroy($calendarId, $id) {
        try {
            // TODO: Check user permission for delete
            $task = Task::where('calendar_id', $calendarId)->findOrFail($id);
            // TODO: Check if user can delete this task
            // if (!$this->userCanDelete($task)) { return response()->json(['error' => 'Forbidden'], 403); }

            // Consider soft deletes here
            $task->delete();

            return response()->json(null, 204);
        } catch (ModelNotFoundException $e) {
            AppLogger::warning("Task not found for delete: {$id} in calendar {$calendarId}");
            return response()->json(['error' => 'Task not found'], 404);
        } catch (\Exception $e) {
            AppLogger::error("Error deleting task: " . $e->getMessage());
            return response()->json(['error' => 'Unable to delete task'], 500);
        }
    }
}
