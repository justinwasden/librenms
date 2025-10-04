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
								    @php
    								$endpoints = json_decode($template->template_data, true)['endpoints'] ?? [];
										@endphp

										@foreach ($endpoints as $index => $endpoint)
										    <div class="card mb-3">
										        <div class="card-header bg-light">
										            <strong>Endpoint {{ $index + 1 }}: {{ $endpoint['name'] ?? 'Unnamed' }}</strong>
										        </div>
										        <div class="card-body">
										            <div class="form-group">
										                <label>Path</label>
										                <input type="text" class="form-control" name="template_data[endpoints][{{ $index }}][path]" value="{{ $endpoint['path'] ?? '' }}">
										            </div>
										            <div class="form-group">
										                <label>Method</label>
										                <input type="text" class="form-control" name="template_data[endpoints][{{ $index }}][method]" value="{{ $endpoint['method'] ?? '' }}">
										            </div>
										            <div class="form-group">
										                <label>Parameters (JSON)</label>
										                <textarea class="form-control" name="template_data[endpoints][{{ $index }}][params]" rows="3">{{ json_encode($endpoint['params'] ?? [], JSON_PRETTY_PRINT) }}</textarea>
										            </div>
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