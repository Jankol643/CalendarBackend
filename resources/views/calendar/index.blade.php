@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 bg-light">
            <!-- Calendar Selector -->
            <div id="calendar-selector">
                <div class="layout">
                    <h1>Calendar Selector</h1>
                    <div class="calendar-checkboxes">
                        @foreach($calendars as $calendar)
                        <label class="checkbox-btn">
                            <input type="checkbox" class="calendar-checkbox" data-id="{{ $calendar->id }}" checked>
                            <span class="color-circle" style="<?php

declare(strict_types = 1);

'background-color: ' . $calendar->color . ';'; ?>"></span> {{ $calendar->title }}
                        </label>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-md-9">
            <div id="calendar"></div><br />
            <button type="button" class="btn btn-primary" id="openAddEventModalBtn">
                Add Event
            </button>
            <div id="dynamicFormContainer"></div>

            <button type="button" class="btn btn-primary" id="openAddTaskModalBtn">
                Add Task
            </button>
            @if (count($errors) > 0)
            <script type="text/javascript">
                $(document).ready(function() {
                    $('#add-event-modal').modal('show');
                });
            </script>
            @endif

            <h1>Event list</h1>
            <br />
            @include('calendar.entry_list', ['events' => $events])

            <h1>Task list</h1>
            <br />
            @include('task.task_list', ['tasks' => $tasks])
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        $('.calendar-checkbox').change(function() {
            var calendarId = $(this).data('id');
            if ($(this).is(':checked')) {
                // Fetch events and tasks for the selected calendar
                $.ajax({
                    url: '/calendars/' + calendarId + '/items',
                    method: 'GET',
                    success: function(data) {
                        // Update the event and task lists based on the response
                        // You can use data.events and data.tasks to update your lists
                        console.log(data);
                        // Example: Update the event list
                        $('#dynamicFormContainer').html(data.events.map(event => `<div>${event.name}</div>`).join(''));
                    },
                    error: function(xhr) {
                        console.error(xhr.responseText);
                    }
                });
            } else {
                // Handle unchecking the calendar if needed
                // You might want to clear the events/tasks from the display
            }
        });
    });
</script>
@endsection