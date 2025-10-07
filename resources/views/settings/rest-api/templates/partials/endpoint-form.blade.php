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
        <div class="col-md-4">
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

        <div class="col-md-4">
            <div class="form-group">
                <label>Resource Type <span class="text-danger">*</span></label>
                <select class="form-control"
                        name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][resource_type]"
                        required>
                    <option value="">-- Select Type --</option>
                    {{-- Updated Resource Types to match LibreNMS standards/seeding --}}
                    <option value="device" {{ ($endpoint['resource_type'] ?? '') === 'device' ? 'selected' : '' }}>Device (Overall Status)</option>
                    <option value="port" {{ ($endpoint['resource_type'] ?? '') === 'port' ? 'selected' : '' }}>Port/Interface</option>
                    <option value="storage" {{ ($endpoint['resource_type'] ?? '') === 'storage' ? 'selected' : '' }}>Storage/Volume</option>
                    <option value="sensor" {{ ($endpoint['resource_type'] ?? '') === 'sensor' ? 'selected' : '' }}>Sensor/Health</option>
                    <option value="processor" {{ ($endpoint['resource_type'] ?? '') === 'processor' ? 'selected' : '' }}>Processor</option>
                    <option value="mempool" {{ ($endpoint['resource_type'] ?? '') === 'mempool' ? 'selected' : '' }}>Memory Pool</option>
                    <option value="alert" {{ ($endpoint['resource_type'] ?? '') === 'alert' ? 'selected' : '' }}>Alert/Event</option>
                    <option value="custom" {{ ($endpoint['resource_type'] ?? '') === 'custom' ? 'selected' : '' }}>Custom/Other</option>
                    {{-- Fallback/Legacy values --}}
                    <option value="unknown" {{ ($endpoint['resource_type'] ?? '') === 'unknown' ? 'selected' : '' }}>Unknown</option>
                </select>
                <small class="form-text text-muted">What type of resource does this endpoint monitor?</small>
            </div>
        </div>

        <div class="col-md-4">
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

    {{-- Metric Mapping Section --}}
    <div class="card mb-3">
        <div class="card-header bg-primary text-white">
            <h6 class="mb-0">
                <i class="fas fa-chart-line"></i> Metric Mapping
            </h6>
        </div>
        <div class="card-body">
            <div class="alert alert-info mb-3">
                <i class="fas fa-info-circle"></i>
                <strong>Map API response fields to LibreNMS metrics.</strong><br>
                Use JSONPath notation to extract values from the response. Example: <code>$.data.cpu_usage</code>
            </div>

            <div class="form-group">
						    <label for="metric_map_json">Metric Mapping (JSON)</label>
						    <textarea id="metric_map_json"
						              name="metric_map_json"
						              class="form-control font-monospace"
						              rows="10"
						              placeholder="Enter or paste JSON mapping here..."
						              required
						              style="white-space: pre; resize: vertical;">{{ old('metric_map_json', json_encode($endpoint['metric_map'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) }}</textarea>

						    <small class="form-text text-muted">
						        Paste valid JSON mapping. Example: <code>{"storage_size": "items.0.space.total_physical"}</code>
						    </small>
						    <button type="button" class="btn btn-sm btn-secondary mt-2" id="beautifyJson">Beautify JSON</button>

						    <div id="jsonError" class="text-danger mt-2" style="display: none;"></div>
						</div>


            <div class="form-group mb-0">
                <label>Response Path (Optional)</label>
                <input type="text"
                       class="form-control font-monospace"
                       name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][response_path]"
                       value="{{ $endpoint['response_path'] ?? '' }}"
                       placeholder="$.data.items">
                <small class="form-text text-muted">
                    JSONPath to the data array in the response. Leave empty if metrics are at root level.<br>
                    <strong>Example:</strong> <code>$.data.items</code> to access items array in nested response
                </small>
            </div>
        </div>
    </div>

    {{-- Legacy Response Mapping (kept for compatibility) --}}
    <div class="form-group" style="display: none;">
        <label>Response Mapping (Deprecated - Use Metric Map)</label>
        <textarea class="form-control font-monospace"
                  rows="2"
                  name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][response_mapping]">{{ is_array($endpoint['response_mapping'] ?? null) ? json_encode($endpoint['response_mapping'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : ($endpoint['response_mapping'] ?? '') }}</textarea>
    </div>

    <div class="text-right mt-3">
        <button type="button" class="btn btn-sm btn-secondary" @click="openEndpoint = null">
            <i class="fas fa-times"></i> Close
        </button>
    </div>
</div>
{{-- Pushing scripts separately due to template structure, ensuring the JS is scoped to the form --}}
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Unique ID for this specific textarea, generated from the partial's context
    const endpointIndex = '{{ $connectionIndex }}_{{ $endpointIndex }}';
    const textarea = document.querySelector(`#endpointEditModal{{ $endpoint->id }} #metric_map_json`);
    const beautifyButton = document.querySelector(`#endpointEditModal{{ $endpoint->id }} #beautifyJson`);

    // Fallback if the component is used outside of the modal context
    if (!textarea) return;

    /**
     * Validate and pretty-print JSON
     */
    function validateAndFormatJSON() {
        const value = textarea.value.trim();
        const errorDiv = textarea.closest('.form-group').querySelector('#jsonError');

        if (!value) {
            errorDiv.style.display = 'none';
            return;
        }

        try {
            const parsed = JSON.parse(value);
            textarea.value = JSON.stringify(parsed, null, 4);
            errorDiv.style.display = 'none';
        } catch (e) {
            errorDiv.textContent = '⚠️ Invalid JSON: ' + e.message;
            errorDiv.style.display = 'block';
        }
    }

    /**
     * Validate while typing (without reformatting)
     */
    textarea.addEventListener('input', () => {
        const errorDiv = textarea.closest('.form-group').querySelector('#jsonError');
        try {
            JSON.parse(textarea.value);
            errorDiv.style.display = 'none';
        } catch (e) {
            errorDiv.textContent = '⚠️ Invalid JSON: ' + e.message;
            errorDiv.style.display = 'block';
        }
    });

    /**
     * Beautify JSON automatically when focus is lost
     */
    textarea.addEventListener('blur', validateAndFormatJSON);

    /**
     * Manual Beautify button
     */
    if (beautifyButton) {
        beautifyButton.addEventListener('click', function(e) {
            e.preventDefault();
            validateAndFormatJSON();
        });
    }
});
</script>