@extends('layouts.librenmsv1')

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">REST API Templates</h3>
                <div class="card-tools">
                    {{-- FIX: Use the new route name for creation --}}
                    <a href="{{ route('devices.rest-api.templates.create') }}" class="btn btn-sm btn-primary">Add Template</a>
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
                                    {{-- FIX: Use the new edit route name --}}
                                    <a href="{{ route('devices.rest-api.templates.edit', $template) }}" class="btn btn-sm btn-info">Edit</a>
                                    <form action="{{ route('devices.rest-api.templates.destroy', $template) }}" method="POST" style="display: inline-block;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection