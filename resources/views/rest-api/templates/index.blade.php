@extends('layouts.librenms')

@section('title', 'REST API Templates')

@section('content')
<div class="container">
    <div class="row">
        <div class="col-md-12">
            <h2>REST API Templates</h2>
            
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Vendor</th>
                        <th>Endpoints</th>
                        <th>Devices</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($templates as $template)
                        <tr>
                            <td>{{ $template->name }}</td>
                            <td>{{ $template->vendor }}</td>
                            <td>{{ $template->endpoints->count() }}</td>
                            <td>{{ $template->devices()->count() }}</td>
                            <td>
                                <a href="{{ route('rest-api.templates.show', $template) }}" class="btn btn-sm btn-primary">View</a>
                                <a href="{{ route('rest-api.templates.edit', $template) }}" class="btn btn-sm btn-warning">Edit</a>
                                <a href="{{ route('rest-api.templates.devices', $template) }}" class="btn btn-sm btn-info">Devices</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center">No templates found</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
