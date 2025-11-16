<div class="modal fade" id="addEventModal" tabindex="-1" aria-labelledby="addEventModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addEventModalLabel">Add Event</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="addEventForm" class="form-horizontal" method="POST" action="{{url('events/create')}}">
                    @csrf <!-- Laravel CSRF token for security -->
                    <div class="row">
                        <div class="col-md-6">
                            <div id="title-group" class="form-group">
                                <label class="control-label" for="title">Title</label>
                                <input type="text" class="form-control @error('title') is-invalid @enderror" name="title" id="title" value="{{ old('title') }}">
                            </div>
                            <div id="startdate-group" class="form-group">
                                <label class="control-label" for="start_date">Start Date</label>
                                <input type="date" class="form-control @error('start_date') is-invalid @enderror" id="start_date" name="start_date" value="{{ old('start_date') }}">
                            </div>
                            <div id="starttime-group" class="form-group">
                                <label class="control-label" for="start_datetime">Start Time</label>
                                <input type="time" class="form-control @error('start_datetime') is-invalid @enderror" id="start_datetime" name="start_datetime" value="{{ old('start_datetime') }}">
                            </div>
                            <div id="enddate-group" class="form-group">
                                <label class="control-label" for="end_date">End Date</label>
                                <input type="date" class="form-control @error('end_date') is-invalid @enderror" id="end_date" name="end_date" value="{{ old('end_date') }}">
                            </div>
                            <div id="endtime-group" class="form-group">
                                <label class="control-label" for="end_datetime">End Time</label>
                                <input type="time" class="form-control" id="end_datetime" name="end_datetime">
                            </div>
                            <div id="description-group" class="form-group">
                                <label class="control-label" for="description">Description</label>
                                <textarea class="form-control" id="description" name="description"></textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="all_day" name="all_day">
                                <label class="form-check-label" for="all_day">All day</label>
                            </div>
                            <div id="category-group" class="form-group">
                                <label class="control-label" for="category">Category</label>
                                <select class="form-control" name="category" id="category">
                                    <option value="">Select Category</option>
                                    <option value="Meeting">Meeting</option>
                                    <option value="Event">Event</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="closeAddEventForm">Close</button>
                <button type="submit" class="btn btn-primary" id="addEventBtn" form="addEventForm">Save changes</button>
            </div>
        </div>
    </div>
</div>