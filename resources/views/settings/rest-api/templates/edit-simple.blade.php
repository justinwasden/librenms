@extends('layouts.librenmsv1')

@section('title', 'Edit REST API Template')

@push('styles')
<style>
    .json-editor {
        font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', 'Consolas', monospace;
        font-size: 13px;
        line-height: 1.5;
    }
    
    .template-example {
        background-color: #f8f9fa;
        border-left: 4px solid #007bff;
        padding: 15px;
        margin: 15px 0;
    }
    
    .placeholder-help {
        background-color: #fff3cd;
        border-left: 4px solid #ffc107;
        padding: 10px;
        margin: 10px 0;
    }
</style>
@endpush

@section('content')
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-md-11 col-lg-10">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-layer-group"></i> Edit Template: {{ $template->name }}
                    </h3>
                    <div class="card-tools">
                        <a href="{{ route('settings.rest-api.templates.index') }}" class="btn btn-default btn-sm">
                            <i class="fas fa-arrow-left"></i> Back to Templates
                        </a>
                    </div>
                </div>

                <form action="{{ route('settings.rest-api.templates.update', $template->id) }}" method="POST">
                    @csrf
                    @method('PUT')
                    
                    <div class="card-body">
                        @if($errors->any())
                            <div class="alert alert-danger">
                                <h5><i class="fas fa-exclamation-triangle"></i> Validation Errors:</h5>
                                <ul class="mb-0">
                                    @foreach($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <h5 class="mb-3 text-info">
                            <i class="fas fa-info-circle"></i> Basic Information
                        </h5>
                        
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="name">Template Name <span class="text-danger">*</span></label>
                                    <input type="text" 
                                           name="name" 
                                           id="name" 
                                           class="form-control @error('name') is-invalid @enderror"
                                           value="{{ old('name', $template->name) }}" 
                                           required>
                                    @error('name')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="vendor">Vendor</label>
                                    <input type="text" 
                                           name="vendor" 
                                           id="vendor" 
                                           class="form-control"
                                           value="{{ old('vendor', $template->vendor) }}"
                                           placeholder="e.g., Cisco, Juniper, Dell">
                                </div>
                            </div>
                        </div>

                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="resource_type">Primary Resource Type</label>
                                    <select name="resource_type" id="resource_type" class="form-control">
                                        <option value="">-- None (Generic) --</option>
                                        <option value="device" {{ old('resource_type', $template->resource_type) === 'device' ? 'selected' : '' }}>Device</option>
                                        <option value="port" {{ old('resource_type', $template->resource_type) === 'port' ? 'selected' : '' }}>Port/Interface</option>
                                        <option value="storage" {{ old('resource_type', $template->resource_type) === 'storage' ? 'selected' : '' }}>Storage/Volume</option>
                                        <option value="sensor" {{ old('resource_type', $template->resource_type) === 'sensor' ? 'selected' : '' }}>Sensor/Health</option>
                                        <option value="processor" {{ old('resource_type', $template->resource_type) === 'processor' ? 'selected' : '' }}>Processor</option>
                                        <option value="mempool" {{ old('resource_type', $template->resource_type) === 'mempool' ? 'selected' : '' }}>Memory Pool</option>
                                        <option value="custom" {{ old('resource_type', $template->resource_type) === 'custom' ? 'selected' : '' }}>Custom/Other</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="description">Description</label>
                                    <textarea name="description" 
                                              id="description" 
                                              class="form-control" 
                                              rows="3">{{ old('description', $template->description) }}</textarea>
                                </div>
                            </div>
                        </div>

                        <hr class="my-4">

                        <h5 class="mb-3">
                            <i class="fas fa-code"></i> Template Configuration
                        </h5>

                        <div class="placeholder-help">
                            <strong><i class="fas fa-lightbulb"></i> Available Placeholders:</strong>
                            <ul class="mb-0 mt-2">
                                <li><code>{{ '{{ $device->hostname }}' }}</code> - Device hostname</li>
                                <li><code>{{ '{{ $device->ip }}' }}</code> - Device IP address</li>
                                <li><code>{{ '{{ $device->sysName }}' }}</code> - Device sysName</li>
                                <li><code>{{ '{{ $device->getAttrib("key") }}' }}</code> - Custom device attribute</li>
                            </ul>
                        </div>

                        <div class="form-group">
                            <label for="template_data">
                                JSON Configuration <span class="text-danger">*</span>
                                <button type="button" class="btn btn-sm btn-info ml-2" onclick="formatJson()">
                                    <i class="fas fa-magic"></i> Format JSON
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary ml-1" onclick="showExample()">
                                    <i class="fas fa-book"></i> Show Example
                                </button>
                            </label>
                            <textarea name="template_data" 
                                      id="template_data" 
                                      class="form-control json-editor @error('template_data') is-invalid @enderror" 
                                      rows="20" 
                                      required>{{ old('template_data', json_encode($template->template_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) }}</textarea>
                            @error('template_data')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <small class="form-text text-muted">
                                Define connections and endpoints in JSON format. Validation occurs on save.
                            </small>
                        </div>

                        <div id="example-template" class="template-example" style="display: none;">
                            <h6><i class="fas fa-code"></i> Example Template Structure:</h6>
                            <pre class="mb-0"><code>{
  "connections": [
    {
      "name": "API Connection",
      "base_url": "https://{{ '{{ $device->ip }}' }}",
      "disable_ssl_verify": true,
      "rate_limit": 60,
      "endpoints": [
        {
          "name": "System Status",
          "path": "/api/v1/system/status",
          "method": "GET",
          "resource_type": "device",
          "enabled": true,
          "metric_map": {
            "cpu_usage": "system.cpu.percent",
            "memory_usage": "system.memory.percent",
            "uptime": "system.uptime"
          }
        },
        {
          "name": "Interface Stats",
          "path": "/api/v1/interfaces",
          "method": "GET",
          "resource_type": "port",
          "enabled": true,
          "metric_map": {
            "port_status": "interfaces.[].status",
            "port_speed": "interfaces.[].speed"
          }
        }
      ]
    }
  ]
}</code></pre>
                        </div>
                    </div>

                    <div class="card-footer">
                        <div class="row">
                            <div class="col-md-6">
                                <a href="{{ route('settings.rest-api.templates.index') }}" class="btn btn-default">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                            <div class="col-md-6 text-right">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Template
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
// JSON validation on blur
document.getElementById('template_data').addEventListener('blur', function() {
    validateJson(this);
});

function validateJson(textarea) {
    try {
        const json = JSON.parse(textarea.value);
        textarea.classList.remove('is-invalid');
        textarea.classList.add('is-valid');
        return true;
    } catch (e) {
        textarea.classList.remove('is-valid');
        textarea.classList.add('is-invalid');
        
        // Show error in console for debugging
        console.error('JSON Validation Error:', e.message);
        return false;
    }
}

function formatJson() {
    const textarea = document.getElementById('template_data');
    try {
        const json = JSON.parse(textarea.value);
        textarea.value = JSON.stringify(json, null, 2);
        textarea.classList.remove('is-invalid');
        textarea.classList.add('is-valid');
    } catch (e) {
        alert('Invalid JSON. Cannot format. Error: ' + e.message);
    }
}

function showExample() {
    const example = document.getElementById('example-template');
    if (example.style.display === 'none') {
        example.style.display = 'block';
    } else {
        example.style.display = 'none';
    }
}

// Validate before submit
document.querySelector('form').addEventListener('submit', function(e) {
    const textarea = document.getElementById('template_data');
    if (!validateJson(textarea)) {
        e.preventDefault();
        alert('Please fix the JSON syntax errors before submitting.');
        textarea.focus();
    }
});
</script>
@endpush
