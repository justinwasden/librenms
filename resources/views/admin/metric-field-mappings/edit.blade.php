@extends('layouts.librenmsv1')

@section('title', 'Edit Metric Mapping')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-8 col-md-offset-2">
            <h3>Edit Metric Field Mapping</h3>
            <p class="text-muted">Configure how this REST API metric maps to LibreNMS fields</p>

            <div class="panel panel-default">
                <div class="panel-body">
                    <form action="{{ route('admin.metric-field-mappings.update', $mapping) }}" method="POST">
                        @csrf
                        @method('PUT')

                        {{-- Read-only metric info --}}
                        <div class="form-group">
                            <label>Metric Name</label>
                            <input type="text" class="form-control" value="{{ $mapping->metric_name }}" disabled>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Resource Type</label>
                                    <input type="text" class="form-control" value="{{ $mapping->resource_type ?? 'N/A' }}" disabled>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Vendor / OS</label>
                                    <input type="text" class="form-control" value="{{ $mapping->vendor ?? 'generic' }} / {{ $mapping->os ?? 'generic' }}" disabled>
                                </div>
                            </div>
                        </div>

                        <hr>

                        {{-- Editable mapping fields --}}
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group @error('librenms_table') has-error @enderror">
                                    <label>LibreNMS Table <span class="text-danger">*</span></label>
                                    <select name="librenms_table" id="librenms_table" class="form-control" required>
                                        <option value="">Select Table...</option>
                                        @foreach($tables as $value => $label)
                                            <option value="{{ $value }}" {{ old('librenms_table', $mapping->librenms_table) == $value ? 'selected' : '' }}>
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
                                           value="{{ old('librenms_field', $mapping->librenms_field) }}" required>
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
                                        <option value="numeric" {{ old('data_type', $mapping->data_type) == 'numeric' ? 'selected' : '' }}>Numeric</option>
                                        <option value="string" {{ old('data_type', $mapping->data_type) == 'string' ? 'selected' : '' }}>String</option>
                                        <option value="boolean" {{ old('data_type', $mapping->data_type) == 'boolean' ? 'selected' : '' }}>Boolean</option>
                                        <option value="json" {{ old('data_type', $mapping->data_type) == 'json' ? 'selected' : '' }}>JSON</option>
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
                                           value="{{ old('unit', $mapping->unit) }}" 
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
                                           value="{{ old('multiplier', $mapping->multiplier ?? 1) }}" 
                                           placeholder="1.0">
                                    @error('multiplier')
                                        <span class="help-block">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <div class="form-group @error('description') has-error @enderror">
                            <label>Description (optional)</label>
                            <textarea name="description" class="form-control" rows="3">{{ old('description', $mapping->description) }}</textarea>
                            @error('description')
                                <span class="help-block">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="enabled" value="1" {{ old('enabled', $mapping->enabled) ? 'checked' : '' }}>
                                Enable this mapping
                            </label>
                        </div>

                        <hr>

                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-save"></i> Update Mapping
                            </button>
                            <a href="{{ route('admin.metric-field-mappings.index') }}" class="btn btn-default">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>

            {{-- Metadata Info --}}
            <div class="panel panel-info">
                <div class="panel-heading">Mapping Metadata</div>
                <div class="panel-body">
                    <dl class="dl-horizontal">
                        <dt>Created:</dt>
                        <dd>{{ $mapping->created_at->format('Y-m-d H:i:s') }}</dd>

                        <dt>Last Updated:</dt>
                        <dd>{{ $mapping->updated_at->format('Y-m-d H:i:s') }}</dd>

                        <dt>Last Seen:</dt>
                        <dd>{{ $mapping->last_seen_at ? $mapping->last_seen_at->diffForHumans() : 'Never' }}</dd>

                        <dt>Last Device:</dt>
                        <dd>
                            @if($mapping->lastMatchedDevice)
                                <a href="{{ route('device', $mapping->lastMatchedDevice->device_id) }}">
                                    {{ $mapping->lastMatchedDevice->hostname }}
                                </a>
                            @else
                                N/A
                            @endif
                        </dd>

                        <dt>Auto-learned:</dt>
                        <dd>{{ $mapping->auto_learned ? 'Yes' : 'No' }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
