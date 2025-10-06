@extends('layouts.librenmsv1')

@section('title', 'Create Metric Mapping')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-8 col-md-offset-2">
            <h3><i class="fa fa-plus"></i> Create Metric Field Mapping</h3>
            <p class="text-muted">Create a new mapping for REST API metrics to LibreNMS fields</p>

            <div class="panel panel-default">
                <div class="panel-body">
                    <form action="{{ route('settings.metric-field-mappings.store') }}" method="POST">
                        @csrf

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group @error('metric_name') has-error @enderror">
                                    <label>Metric Name <span class="text-danger">*</span></label>
                                    <input type="text" name="metric_name" class="form-control" 
                                           value="{{ old('metric_name') }}" required
                                           placeholder="e.g., temperature, volume_capacity">
                                    @error('metric_name')
                                        <span class="help-block">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-group @error('resource_type') has-error @enderror">
                                    <label>Resource Type (optional)</label>
                                    <input type="text" name="resource_type" class="form-control" 
                                           value="{{ old('resource_type') }}"
                                           placeholder="e.g., volume, controller, interface">
                                    @error('resource_type')
                                        <span class="help-block">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group @error('vendor') has-error @enderror">
                                    <label>Vendor (optional)</label>
                                    <input type="text" name="vendor" class="form-control" 
                                           value="{{ old('vendor') }}"
                                           placeholder="e.g., PureStorage, Fortinet">
                                    <small class="help-block">Leave empty for generic mapping</small>
                                    @error('vendor')
                                        <span class="help-block">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-group @error('os') has-error @enderror">
                                    <label>Operating System (optional)</label>
                                    <input type="text" name="os" class="form-control" 
                                           value="{{ old('os') }}"
                                           placeholder="e.g., Purity, FortiOS">
                                    <small class="help-block">Leave empty for generic mapping</small>
                                    @error('os')
                                        <span class="help-block">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <hr>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group @error('librenms_table') has-error @enderror">
                                    <label>LibreNMS Table <span class="text-danger">*</span></label>
                                    <select name="librenms_table" id="librenms_table" class="form-control" required>
                                        <option value="">Select Table...</option>
                                        @foreach($tables as $value => $label)
                                            <option value="{{ $value }}" {{ old('librenms_table') == $value ? 'selected' : '' }}>
                                                {{ $label }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('librenms_table')
                                        <span class="help-block">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-group @error('librenms_field') has-error @enderror">
                                    <label>LibreNMS Field <span class="text-danger">*</span></label>
                                    <input type="text" name="librenms_field" class="form-control" 
                                           value="{{ old('librenms_field') }}" required
                                           placeholder="e.g., sensor_current, storage_total">
                                    @error('librenms_field')
                                        <span class="help-block">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group @error('data_type') has-error @enderror">
                                    <label>Data Type <span class="text-danger">*</span></label>
                                    <select name="data_type" class="form-control" required>
                                        <option value="numeric" {{ old('data_type') == 'numeric' ? 'selected' : '' }}>Numeric</option>
                                        <option value="string" {{ old('data_type') == 'string' ? 'selected' : '' }}>String</option>
                                        <option value="boolean" {{ old('data_type') == 'boolean' ? 'selected' : '' }}>Boolean</option>
                                        <option value="json" {{ old('data_type') == 'json' ? 'selected' : '' }}>JSON</option>
                                    </select>
                                    @error('data_type')
                                        <span class="help-block">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="form-group @error('unit') has-error @enderror">
                                    <label>Unit (optional)</label>
                                    <input type="text" name="unit" class="form-control" 
                                           value="{{ old('unit') }}" 
                                           placeholder="e.g., bytes, celsius, percent">
                                    @error('unit')
                                        <span class="help-block">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="form-group @error('multiplier') has-error @enderror">
                                    <label>Multiplier (optional)</label>
                                    <input type="number" step="0.0001" name="multiplier" class="form-control" 
                                           value="{{ old('multiplier', 1) }}" 
                                           placeholder="1.0">
                                    <small class="help-block">For unit conversion (e.g., 1024 for KB to bytes)</small>
                                    @error('multiplier')
                                        <span class="help-block">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <div class="form-group @error('description') has-error @enderror">
                            <label>Description (optional)</label>
                            <textarea name="description" class="form-control" rows="3">{{ old('description') }}</textarea>
                            @error('description')
                                <span class="help-block">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="enabled" value="1" {{ old('enabled', true) ? 'checked' : '' }}>
                                Enable this mapping
                            </label>
                        </div>

                        <hr>

                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-save"></i> Create Mapping
                            </button>
                            <a href="{{ route('settings.metric-field-mappings.index') }}" class="btn btn-default">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>

            {{-- Help Panel --}}
            <div class="panel panel-info">
                <div class="panel-heading">Examples</div>
                <div class="panel-body">
                    <h5>PureStorage Volume Capacity:</h5>
                    <ul>
                        <li><strong>Metric Name:</strong> volume_provisioned</li>
                        <li><strong>Resource Type:</strong> volume</li>
                        <li><strong>Vendor:</strong> PureStorage</li>
                        <li><strong>OS:</strong> Purity</li>
                        <li><strong>Table:</strong> sensors</li>
                        <li><strong>Field:</strong> sensor_current</li>
                        <li><strong>Data Type:</strong> numeric</li>
                    </ul>

                    <h5>Generic Temperature Sensor:</h5>
                    <ul>
                        <li><strong>Metric Name:</strong> temperature</li>
                        <li><strong>Resource Type:</strong> sensor</li>
                        <li><strong>Vendor:</strong> (leave empty for all)</li>
                        <li><strong>Table:</strong> sensors</li>
                        <li><strong>Field:</strong> sensor_current</li>
                        <li><strong>Data Type:</strong> numeric</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
