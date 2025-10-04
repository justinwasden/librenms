@extends('layouts.librenmsv1')

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">REST API Templates</h3>
                <div class="card-tools">
                    {{-- FIX: Use data-target to open modal instead of navigating --}}
                    <button type="button" class="btn btn-sm btn-primary" data-toggle="modal" data-target="#createTemplateModal">
                        Add Template
                    </button>
                </div>
            </div>
            <div class="card-body">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Vendor</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($templates as $template)
                            <tr>
                                <td>{{ $template->name }}</td>
                                <td>{{ $template->vendor }}</td>
                                <td>
                                    {{-- FIX: Use explicit ID passing for edit --}}
                                    <a href="{{ route('devices.rest-api.templates.edit', ['template' => $template->id]) }}" class="btn btn-sm btn-info">Edit</a>
                                    {{-- Delete button is intentionally omitted per request 2 --}}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- NEW: Modal Structure for Create Template (Popout Window) --}}
<div class="modal fade" id="createTemplateModal" tabindex="-1" role="dialog" aria-labelledby="createTemplateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form action="{{ route('devices.rest-api.templates.store') }}" method="POST">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="createTemplateModalLabel">Create REST API Template</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    {{-- Include the form partial here --}}
                    {{-- NOTE: To avoid the getAttrib() error in the modal, we pass a dummy empty template object --}}
                    @include('settings.rest-api.templates._form', ['template' => new \App\Models\RestApiTemplate(), 'device' => null])
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection