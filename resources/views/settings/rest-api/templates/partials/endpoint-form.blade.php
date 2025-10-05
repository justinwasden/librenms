<div class="border rounded p-3 bg-light">
    <div class="form-group">
        <label>Endpoint Name <span class="text-danger">*</span></label>
        <input type="text" 
               class="form-control" 
               name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][name]" 
               value="{{ $endpoint['name'] ?? '' }}"
               required>
    </div>

    <div class="form-group">
        <label>Path <span class="text-danger">*</span></label>
        <input type="text" 
               class="form-control" 
               name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][path]" 
               value="{{ $endpoint['path'] ?? '' }}"
               placeholder="/api/endpoint"
               required>
        <small class="form-text text-muted">Example: /api/v1/data</small>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="form-group">
                <label>HTTP Method</label>
                <select class="form-control" 
                        name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][method]">
                    <option value="GET" {{ ($endpoint['method'] ?? 'GET') === 'GET' ? 'selected' : '' }}>GET</option>
                    <option value="POST" {{ ($endpoint['method'] ?? '') === 'POST' ? 'selected' : '' }}>POST</option>
                    <option value="PUT" {{ ($endpoint['method'] ?? '') === 'PUT' ? 'selected' : '' }}>PUT</option>
                    <option value="DELETE" {{ ($endpoint['method'] ?? '') === 'DELETE' ? 'selected' : '' }}>DELETE</option>
                    <option value="PATCH" {{ ($endpoint['method'] ?? '') === 'PATCH' ? 'selected' : '' }}>PATCH</option>
                </select>
            </div>
        </div>

        <div class="col-md-6">
            <div class="form-group">
                <label>Poll Interval (seconds)</label>
                <input type="number" 
                       class="form-control" 
                       name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][poll_interval]" 
                       value="{{ $endpoint['poll_interval'] ?? 300 }}"
                       min="60"
                       step="60">
                <small class="form-text text-muted">Default: 300 (5 minutes)</small>
            </div>
        </div>
    </div>

    <div class="form-group">
        <div class="custom-control custom-checkbox">
            {{-- Hidden input to ensure a value is always sent --}}
            <input type="hidden" 
                   name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][enabled]" 
                   value="0">
            <input type="checkbox" 
                   class="custom-control-input" 
                   id="endpoint_enabled_{{ $connectionIndex }}_{{ $endpointIndex }}"
                   name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][enabled]" 
                   value="1"
                   {{ ($endpoint['enabled'] ?? true) ? 'checked' : '' }}>
            <label class="custom-control-label" for="endpoint_enabled_{{ $connectionIndex }}_{{ $endpointIndex }}">
                Enable this endpoint
            </label>
        </div>
        <small class="form-text text-muted">Disabled endpoints will not be polled</small>
    </div>

    <div class="form-group">
        <label>Description (Optional)</label>
        <textarea class="form-control" 
                  rows="2" 
                  name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][description]"
                  placeholder="What data does this endpoint provide?">{{ $endpoint['description'] ?? '' }}</textarea>
    </div>

    <div class="form-group mb-0">
        <label>Response Mapping (JSON) <small class="text-muted">(Optional)</small></label>
        <textarea class="form-control font-monospace" 
                  rows="6" 
                  name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][response_mapping]"
                  placeholder='{"metric_name": "$.data.value"}'>{{ is_array($endpoint['response_mapping'] ?? null) ? json_encode($endpoint['response_mapping'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : ($endpoint['response_mapping'] ?? '') }}</textarea>
        <small class="form-text text-muted">Map API response fields to LibreNMS metrics</small>
    </div>

    <div class="text-right mt-3">
        <button type="button" class="btn btn-sm btn-secondary" @click="openEndpoint = null">
            <i class="fas fa-times"></i> Close
        </button>
    </div>
</div>
