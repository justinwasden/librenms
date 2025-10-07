@extends('layouts.librenmsv1')

@section('title', 'Import API Metric Mappings')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <h3>
                <i class="fa fa-upload"></i> Import REST API Metric Field Mappings
            </h3>
            <p class="text-muted">Upload a JSON file containing metric field mappings to import them into the database.</p>

            @if(session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif

            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">Upload Mappings File</h3>
                </div>
                <div class="panel-body">
                    <form action="{{ route('settings.metric-field-mappings.import') }}" method="POST" enctype="multipart/form-data">
                        @csrf

                        <div class="form-group">
                            <label for="mapping_file">JSON Mappings File</label>
                            <input type="file" name="mapping_file" id="mapping_file" class="form-control-file" required>
                            <small class="form-text text-muted">Please select a valid JSON file to import.</small>
                        </div>

                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="overwrite" value="1">
                                **Overwrite** existing manual mappings with the same `metric_name`, `vendor`, and `os`.
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-upload"></i> Upload and Import
                        </button>

                        <a href="{{ route('settings.metric-field-mappings.index') }}" class="btn btn-default">
                            Cancel
                        </a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection