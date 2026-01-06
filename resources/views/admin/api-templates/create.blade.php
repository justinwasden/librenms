@extends('layouts.librenmsv1')

@section('title', 'Create API Template')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-8 col-md-offset-2">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <i class="fa fa-plus"></i> Create API Template
                    </h3>
                </div>
                <div class="panel-body">
                    @if($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form action="{{ route('admin.api-templates.store') }}" method="POST">
                        @csrf

                        <div class="form-group">
                            <label for="key">Template Key <span class="text-danger">*</span></label>
                            <input type="text" name="key" id="key" class="form-control"
                                   value="{{ old('key') }}" required
                                   pattern="[a-z0-9_]+" title="Lowercase letters, numbers, and underscores only"
                                   placeholder="e.g., vendor_product">
                            <span class="help-block">Unique identifier (lowercase, no spaces)</span>
                        </div>

                        <div class="form-group">
                            <label for="name">Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="name" class="form-control"
                                   value="{{ old('name') }}" required
                                   placeholder="e.g., Vendor Product API">
                        </div>

                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea name="description" id="description" class="form-control" rows="2"
                                      placeholder="Brief description of this template">{{ old('description') }}</textarea>
                        </div>

                        <div class="form-group">
                            <label for="auth_type">Authentication Type <span class="text-danger">*</span></label>
                            <select name="auth_type" id="auth_type" class="form-control" required>
                                <option value="">-- Select --</option>
                                @foreach($authSchemas as $schema)
                                    <option value="{{ $schema->key }}" {{ old('auth_type') == $schema->key ? 'selected' : '' }}>
                                        {{ $schema->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="base_url_pattern">Base URL Pattern</label>
                            <input type="text" name="base_url_pattern" id="base_url_pattern" class="form-control"
                                   value="{{ old('base_url_pattern') }}"
                                   placeholder="https://{hostname}/api/v1">
                            <span class="help-block">Use {hostname} as placeholder for device hostname/IP</span>
                        </div>

                        <div class="form-group">
                            <label for="os_types">OS Types</label>
                            <input type="text" name="os_types" id="os_types" class="form-control"
                                   value="{{ old('os_types') }}"
                                   placeholder="e.g., purestorage, proxmox">
                            <span class="help-block">Comma-separated list of LibreNMS OS identifiers</span>
                        </div>

                        <div class="form-group">
                            <label for="capabilities">Capabilities</label>
                            <input type="text" name="capabilities" id="capabilities" class="form-control"
                                   value="{{ old('capabilities') }}"
                                   placeholder="e.g., sensors, ports, inventory">
                            <span class="help-block">Comma-separated list of capabilities this template provides</span>
                        </div>

                        <div class="checkbox">
                            <label>
                                <input type="hidden" name="enabled" value="0">
                                <input type="checkbox" name="enabled" value="1" {{ old('enabled', true) ? 'checked' : '' }}>
                                Enabled
                            </label>
                        </div>

                        <hr>

                        <div class="form-group">
                            <a href="{{ route('admin.api-templates.index') }}" class="btn btn-default">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-save"></i> Create Template
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
