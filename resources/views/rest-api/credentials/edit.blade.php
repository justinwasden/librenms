@extends('layouts.librenms')

@section('title', 'Edit REST API - ' . $device->hostname)

@section('content')
<div class="container">
    <div class="row">
        <div class="col-md-12">
            <h2>Edit REST API Configuration for {{ $device->hostname }}</h2>

            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" id="credentials-tab" data-toggle="tab" href="#credentials">Credentials</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="mapping-tab" data-toggle="tab" href="#mapping">Field Mapping</a>
                </li>
            </ul>

            <div class="tab-content">
                <!-- Credentials Tab -->
                <div id="credentials" class="tab-pane fade show active">
                    <form method="POST" action="{{ route('rest-api.credentials.update', $device) }}">
                        @csrf
                        @method('PUT')

                        <div class="form-group mt-3">
                            <label for="template_id">Template</label>
                            <select name="template_id" id="template_id" class="form-control" required>
                                <option value="">-- Select Template --</option>
                                @foreach($templates as $template)
                                    <option value="{{ $template->id }}" @if($deviceTemplate && $deviceTemplate->template_id === $template->id) selected @endif>
                                        {{ $template->name }} ({{ $template->vendor }})
                                    </option>
                                @endforeach
                            </select>
                            @error('template_id')<span class="text-danger">{{ $message }}</span>@enderror
                        </div>

                        <div class="form-group">
                            <label for="auth_type">Authentication Type</label>
                            <select name="auth_type" id="auth_type" class="form-control" required>
                                <option value="">-- Select Auth Type --</option>
                                @foreach($authTypes as $key => $label)
                                    <option value="{{ $key }}" @if($credential && $credential->auth_type === $key) selected @endif>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                            @error('auth_type')<span class="text-danger">{{ $message }}</span>@enderror
                        </div>

                        <div class="form-group" id="username-group" style="display:none;">
                            <label for="username">Username</label>
                            <input type="text" name="username" id="username" class="form-control" value="{{ $credential->username ?? '' }}">
                            @error('username')<span class="text-danger">{{ $message }}</span>@enderror
                        </div>

                        <div class="form-group" id="password-group" style="display:none;">
                            <label for="password">Password</label>
                            <input type="password" name="password" id="password" class="form-control" placeholder="Leave blank to keep current">
                            @error('password')<span class="text-danger">{{ $message }}</span>@enderror
                        </div>

                        <div class="form-group" id="token-group" style="display:none;">
                            <label for="auth_token">API Token</label>
                            <input type="password" name="auth_token" id="auth_token" class="form-control" placeholder="Leave blank to keep current">
                            @error('auth_token')<span class="text-danger">{{ $message }}</span>@enderror
                        </div>

                        <div class="btn-group">
                            <button type="submit" class="btn btn-primary">Update Credentials</button>
                            <button type="button" class="btn btn-info" onclick="testConnection()">Test Connection</button>
                        </div>
                    </form>

                    <div id="test-result" class="alert alert-info" style="display:none; margin-top:20px;"></div>
                </div>

                <!-- Field Mapping Tab -->
                <div id="mapping" class="tab-pane fade">
                    <div class="mt-3">
                        <h4>Field Mapping Configuration</h4>
                        
                        <div class="alert alert-info">
                            <strong>Field Mapping:</strong> Choose how API response fields map to LibreNMS database tables.
                        </div>

                        <form method="POST" action="{{ route('rest-api.credentials.set-mapping', $device) }}">
                            @csrf

                            <div class="form-group">
                                <label for="mapping_type">Mapping Type</label>
                                <select name="mapping_type" id="mapping_type" class="form-control" required onchange="updateMappingOptions()">
                                    <option value="">-- Select Mapping Type --</option>
                                    <option value="vendor">Vendor Preset (Auto-detected)</option>
                                    <option value="custom">Custom Mapping</option>
                                </select>
                            </div>

                            <div class="form-group" id="vendor-mapping-group" style="display:none;">
                                <label for="vendor_mapping">Select Vendor Mapping</label>
                                <select name="mapping_name" id="vendor_mapping" class="form-control">
                                    <option value="">-- Select Mapping --</option>
                                    @foreach($vendorMappings as $name => $description)
                                        <option value="{{ $name }}">{{ $description }}</option>
                                    @endforeach
                                </select>
                                <small class="form-text text-muted">
                                    Vendor mappings are pre-configured for specific device types and automatically extract and transform API data.
                                </small>
                            </div>

                            <div class="form-group" id="custom-mapping-group" style="display:none;">
                                <label for="custom_mapping">Select Custom Mapping</label>
                                <select name="mapping_name" id="custom_mapping" class="form-control">
                                    <option value="">-- Select Custom Mapping --</option>
                                    @foreach($customMappings as $name => $path)
                                        <option value="{{ $name }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                                <small class="form-text text-muted">
                                    Custom mappings are user-created JSON files stored in storage/app/rest-api-mappings/
                                </small>
                                <div class="mt-2">
                                    <a href="{{ route('rest-api.mappings.create') }}" class="btn btn-sm btn-success">Create New Mapping</a>
                                </div>
                            </div>

                            <div class="btn-group mt-3">
                                <button type="submit" class="btn btn-primary">Save Field Mapping</button>
                                <a href="{{ route('devices.show', $device) }}" class="btn btn-secondary">Cancel</a>
                            </div>
                        </form>

                        <hr>

                        <h5>Current Mapping Info</h5>
                        @if($currentMapping)
                            <dl class="dl-horizontal">
                                <dt>Type</dt>
                                <dd>{{ ucfirst($currentMapping->mapping_type) }}</dd>
                                
                                <dt>Name</dt>
                                <dd>{{ $currentMapping->mapping_name }}</dd>
                            </dl>
                        @else
                            <p class="text-muted">No custom mapping configured. Using auto-detection.</p>
                        @endif
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <form method="POST" action="{{ route('rest-api.credentials.destroy', $device) }}" style="display:inline;">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger" onclick="return confirm('Remove REST API configuration?')">Remove REST API</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('auth_type').addEventListener('change', function() {
    const type = this.value;
    document.getElementById('username-group').style.display = type === 'basic_auth' ? 'block' : 'none';
    document.getElementById('password-group').style.display = type === 'basic_auth' ? 'block' : 'none';
    document.getElementById('token-group').style.display = ['bearer_token', 'api_token', 'oauth2'].includes(type) ? 'block' : 'none';
});

function updateMappingOptions() {
    const type = document.getElementById('mapping_type').value;
    document.getElementById('vendor-mapping-group').style.display = type === 'vendor' ? 'block' : 'none';
    document.getElementById('custom-mapping-group').style.display = type === 'custom' ? 'block' : 'none';
}

function testConnection() {
    const resultDiv = document.getElementById('test-result');
    resultDiv.innerHTML = 'Testing connection...';
    resultDiv.style.display = 'block';
    resultDiv.className = 'alert alert-info';

    fetch('{{ route("rest-api.credentials.test", $device) }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Content-Type': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            resultDiv.className = 'alert alert-success';
            resultDiv.innerHTML = 'Connection successful! Status: ' + data.status_code;
        } else {
            resultDiv.className = 'alert alert-danger';
            resultDiv.innerHTML = 'Connection failed: ' + (data.error || 'Unknown error');
        }
    })
    .catch(error => {
        resultDiv.className = 'alert alert-danger';
        resultDiv.innerHTML = 'Test failed: ' + error.message;
    });
}

// Trigger auth type display on page load
document.getElementById('auth_type').dispatchEvent(new Event('change'));
</script>
@endsection
