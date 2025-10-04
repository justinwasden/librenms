@extends('layouts.librenmsv1')

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Edit REST API Template</h3>
            </div>
            <div class="card-body">
                <form action="{{ route('devices.rest-api.templates.update', ['template' => $template->id]) }}" method="POST">
                    @csrf
                    @method('PUT')
                    <pre>{{ print_r($template->template_data, true) }}</pre>
										@php
    $template_data = is_array($template->template_data)
        ? $template->template_data
        : (json_decode($template->template_data, true) ?? []);

    $connections = $template_data['connections'] ?? [];
@endphp

@foreach ($connections as $cIndex => $connection)
    <div class="card mb-4 border-primary">
        <div class="card-header bg-primary text-white">
            <strong>Connection {{ $cIndex + 1 }}: {{ $connection['name'] ?? 'Unnamed Connection' }}</strong>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label>Base URL</label>
                <input type="text" class="form-control" name="template_data[connections][{{ $cIndex }}][base_url]" value="{{ $connection['base_url'] ?? '' }}">
            </div>
            <div class="form-group">
                <label>Rate Limit</label>
                <input type="number" class="form-control" name="template_data[connections][{{ $cIndex }}][rate_limit]" value="{{ $connection['rate_limit'] ?? '' }}">
            </div>

            @foreach (($connection['endpoints'] ?? []) as $eIndex => $endpoint)
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <strong>Endpoint {{ $eIndex + 1 }}: {{ $endpoint['name'] ?? 'Unnamed Endpoint' }}</strong>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label>Path</label>
                            <input type="text" class="form-control" name="template_data[connections][{{ $cIndex }}][endpoints][{{ $eIndex }}][path]" value="{{ $endpoint['path'] ?? '' }}">
                        </div>
                        <div class="form-group">
                            <label>HTTP Method</label>
                            <input type="text" class="form-control" name="template_data[connections][{{ $cIndex }}][endpoints][{{ $eIndex }}][http_method]" value="{{ $endpoint['http_method'] ?? '' }}">
                        </div>
                        <div class="form-group">
                            <label>Response Mapping (JSON)</label>
                            <textarea class="form-control" name="template_data[connections][{{ $cIndex }}][endpoints][{{ $eIndex }}][response_mapping]" rows="3">{{ json_encode($endpoint['response_mapping'] ?? [], JSON_PRETTY_PRINT) }}</textarea>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endforeach
                    <button type="submit" class="btn btn-primary">Update</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection