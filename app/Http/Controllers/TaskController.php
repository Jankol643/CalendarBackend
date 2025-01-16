<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

class TaskController extends Controller {
    public function index($calendarId) {
        try {
            $tasks = Task::where('calendar_id', $calendarId)->get();
            return response()->json($tasks);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Unable to retrieve tasks: ' . $e->getMessage()], 500);
        }
    }

    public function store(Request $request, $calendarId) {
        $request->validate([
            'title' => 'required|string',
            'calendar_id' => 'required|exists:calendars,id',
        ]);

        DB::beginTransaction();
        try {
            $task = Task::create($request->all());
            DB::commit();
            return response()->json($task, 201);
        } catch (QueryException $e) {
            DB::rollBack();
            return response()->json(['error' => 'Saving task failed: ' . $e->getMessage()], 420);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'An unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }

    public function show($calendarId, $id) {
        try {
            $task = Task::where('calendar_id', $calendarId)->findOrFail($id);
            return response()->json($task);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Task not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Unable to retrieve task: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $calendarId, $id) {
        DB::beginTransaction();
        try {
            $task = Task::where('calendar_id', $calendarId)->findOrFail($id);
            $task->update($request->all());
            DB::commit();
            return response()->json($task);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json(['error' => 'Task not found'], 404);
        } catch (QueryException $e) {
            DB::rollBack();
            return response()->json(['error' => 'Updating task failed: ' . $e->getMessage()], 420);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'An unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }

    public function destroy($calendarId, $id) {
        DB::beginTransaction();
        try {
            $task = Task::where('calendar_id', $calendarId)->findOrFail($id);
            $task->delete();
            DB::commit();
            return response()->json(null, 204);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json(['error' => 'Task not found'], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Unable to delete task: ' . $e->getMessage()], 500);
        }
    }
}
