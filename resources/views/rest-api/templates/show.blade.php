@extends('layouts.librenms')

@section('title', $template->name)

@section('content')
<div class="container">
    <div class="row">
        <div class="col-md-12">
            <h2>{{ $template->name }}</h2>
            
            <dl class="dl-horizontal">
                <dt>Vendor</dt>
                <dd>{{ $template->vendor }}</dd>
                
                <dt>Description</dt>
                <dd>{{ $template->description }}</dd>
                
                <dt>Endpoints</dt>
                <dd>{{ $endpoints->count() }}</dd>
                
                <dt>Devices Using</dt>
                <dd>{{ $template->devices()->count() }}</dd>
            </dl>

            <h3>Endpoints</h3>
            
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Path</th>
                        <th>Method</th>
                        <th>Resource Type</th>
                        <th>Poll Interval</th>
                        <th>Mappings</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($endpoints as $endpoint)
                        <tr>
                            <td>{{ $endpoint->name }}</td>
                            <td><code>{{ $endpoint->path }}</code></td>
                            <td>{{ $endpoint->http_method }}</td>
                            <td>{{ $endpoint->resource_type }}</td>
                            <td>{{ $endpoint->poll_interval }}s</td>
                            <td>{{ count($endpoint->getMappingConfig()) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center">No endpoints configured</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <div class="btn-group">
                <a href="{{ route('rest-api.templates.edit', $template) }}" class="btn btn-warning">Edit</a>
                <a href="{{ route('rest-api.templates.index') }}" class="btn btn-secondary">Back</a>
            </div>
        </div>
    </div>
</div>
@endsection
