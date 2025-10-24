{{-- /resources/views/settings/rest-api/templates/partials/endpoint-form.blade.php --}}
<div class="border rounded p-3 bg-light">

    {{-- Row 1: Endpoint Name and Path (Consolidated) --}}
    <div class="row">
        <div class="col-md-6">
            <div class="form-group">
                <label>Endpoint Name <span class="text-danger">*</span></label>
                <input type="text"
                       class="form-control endpoint-name"
                       name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][name]"
                       value="{{ $endpoint['name'] ?? '' }}"
                       data-conn-idx="{{ $connectionIndex }}"
                       data-ep-idx="{{ $endpointIndex }}"
                       required>
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                <label>Path <span class="text-danger">*</span></label>
                <input type="text"
                       class="form-control endpoint-path"
                       name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][path]"
                       value="{{ $endpoint['path'] ?? '' }}"
                       placeholder="/api/endpoint or /api/{device_hostname}/endpoint"
                       data-conn-idx="{{ $connectionIndex }}"
                       data-ep-idx="{{ $endpointIndex }}"
                       required>
                <small class="form-text text-muted">Example: /api/v1/data or /api/{device_hostname}/drives</small>
            </div>
        </div>
    </div>

    {{-- Row 2: HTTP Method, Resource Type, and Poll Interval (3-Column Grid) --}}
    <div class="row">
        <div class="col-md-4">
            <div class="form-group">
                <label>HTTP Method</label>
                <select class="form-control endpoint-method"
                        name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][method]"
                        data-conn-idx="{{ $connectionIndex }}"
                        data-ep-idx="{{ $endpointIndex }}">
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
                <select class="form-control endpoint-resource-type"
                        name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][resource_type]"
                        data-conn-idx="{{ $connectionIndex }}"
                        data-ep-idx="{{ $endpointIndex }}"
                        required>
                    <option value="">-- Select Type --</option>
                    <option value="device" {{ ($endpoint['resource_type'] ?? '') === 'device' ? 'selected' : '' }}>Device (Overall Status)</option>
                    <option value="port" {{ ($endpoint['resource_type'] ?? '') === 'port' ? 'selected' : '' }}>Port/Interface</option>
                    <option value="storage" {{ ($endpoint['resource_type'] ?? '') === 'storage' ? 'selected' : '' }}>Storage/Volume</option>
                    <option value="sensor" {{ ($endpoint['resource_type'] ?? '') === 'sensor' ? 'selected' : '' }}>Sensor/Health</option>
                    <option value="processor" {{ ($endpoint['resource_type'] ?? '') === 'processor' ? 'selected' : '' }}>Processor</option>
                    <option value="mempool" {{ ($endpoint['resource_type'] ?? '') === 'mempool' ? 'selected' : '' }}>Memory Pool</option>
                    <option value="alert" {{ ($endpoint['resource_type'] ?? '') === 'alert' ? 'selected' : '' }}>Alert/Event</option>
                    <option value="custom" {{ ($endpoint['resource_type'] ?? '') === 'custom' ? 'selected' : '' }}>Custom/Other</option>
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

    {{-- Row 3: Checkbox and Response Path (Consolidated) --}}
    <div class="row">
        <div class="col-md-6">
            <div class="form-group">
                <div class="custom-control custom-checkbox pt-4">
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
        </div>
        <div class="col-md-6">
            <div class="form-group">
                <label>Response Path (Optional)</label>
                <input type="text"
                       class="form-control font-monospace"
                       name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][response_path]"
                       value="{{ $endpoint['response_path'] ?? '' }}"
                       placeholder="$.data.items">
                <small class="form-text text-muted">
                    JSONPath to the data array in the response (e.g., <code>$.data.items</code>)
                </small>
            </div>
        </div>
    </div>

    {{-- Row 4: Description (Full Width) --}}
    <div class="form-group">
        <label>Description (Optional)</label>
        <textarea class="form-control"
                  rows="2"
                  name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][description]"
                  placeholder="What data does this endpoint provide?">{{ $endpoint['description'] ?? '' }}</textarea>
    </div>

    {{-- ========== METRIC MAPPING SECTION - STATIC CONTENT ONLY ========== --}}
    <div class="card mb-3">
        <div class="card-header bg-primary text-white">
            <h6 class="mb-0">
                <i class="fas fa-chart-line"></i> Metric Mapping
            </h6>
        </div>
        <div class="card-body">
            <div class="alert alert-info mb-3">
                <i class="fas fa-info-circle"></i>
                <strong>API Preview for Mapping:</strong><br>
                Use the <strong>Fetch API Preview</strong> button in the endpoint manager panel to load data into the preview tab.
            </div>

            {{-- Fallback: JSON textarea (for manual entry) --}}
            <div class="form-group">
                <label for="metric_map_json_{{ $connectionIndex }}_{{ $endpointIndex }}">Metric Mapping (JSON)</label>
                <textarea id="metric_map_json_{{ $connectionIndex }}_{{ $endpointIndex }}"
                          name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][metric_map]"
                          class="form-control font-monospace metric-map-json"
                          rows="10"
                          placeholder="Paste JSON mapping here..."
                          style="white-space: pre; resize: vertical;">{{ old('metric_map_json', json_encode($endpoint['metric_map'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) }}</textarea>
                <small class="form-text text-muted">
                    Format: <code>{"api_field": "librenms_table.librenms_field"}</code><br>
                    Example: <code>{"capacity": "storage.storage_size"}</code>
                </small>
            </div>
        </div>
    </div>
</div>

<style>
    .preview-status {
        font-weight: 500;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const connIdx = {{ $connectionIndex }};
    const epIdx = {{ $endpointIndex }};
    const templateIdMatch = window.location.pathname.match(/\/templates\/(\d+)/);
    const templateId = templateIdMatch ? templateIdMatch[1] : null;

// Fetch API Preview Button Handler
const fetchBtn = document.getElementById('fetch-preview-' + connIdx + '-' + epIdx);
if (fetchBtn) {
    fetchBtn.addEventListener('click', function() {
        const pathInput = document.querySelector('.endpoint-path[data-conn-idx="' + connIdx + '"][data-ep-idx="' + epIdx + '"]');
        const statusEl = document.getElementById('preview-status-' + connIdx + '-' + epIdx);

        if (!pathInput || !pathInput.value.trim()) {
            statusEl.textContent = '✗ Please enter an API path first';
            statusEl.className = 'preview-status error';
            return;
        }

        // --- START FIX: Collect UNSAVED Connection and Endpoint Data ---
        const connectionContainer = pathInput.closest('.card-body').querySelector('#api-preview-container-' + connIdx + '-' + epIdx).closest('.card-body').closest('.card-body');

        const currentPath = pathInput.value.trim();
        const currentMethod = connectionContainer.querySelector('.endpoint-method').value;
        const currentBaseUrl = document.querySelector('input[name="template_data[connections][0][base_url]"]').value;

        const hasPlaceholders = /{device_hostname}|{device_ip}|{device_sysname}|{device_attrib:/.test(currentPath);

        // The endpoint object we are currently editing
        const currentEndpointData = {
            name: connectionContainer.querySelector('.endpoint-name').value,
            path: currentPath,
            method: currentMethod,
            // Include other fields needed for testing (Resource Type, etc.)
            resource_type: connectionContainer.querySelector('.endpoint-resource-type').value,
            // You may need to include other data here if the controller uses it
        };

        // The connection object we are currently editing (only passing Base URL is typically enough)
        const currentConnectionData = {
            name: document.querySelector('input[name="template_data[connections][0][name]"]').value,
            base_url: currentBaseUrl,
            disable_ssl_verify: document.getElementById('disable_ssl_verify')?.checked ? 1 : 0
        };

        // Check for device selection if placeholders exist
        const deviceSelect = document.querySelector('.test-device[data-conn-idx="' + connIdx + '"][data-ep-idx="' + epIdx + '"]');
        const deviceId = deviceSelect ? deviceSelect.value : null;

        if (hasPlaceholders && !deviceId) {
            statusEl.textContent = '✗ Path has {device_*} placeholders - SELECT A DEVICE';
            statusEl.className = 'preview-status error';
            return;
        }

        if (!templateId) {
            statusEl.textContent = '✗ Template not found';
            statusEl.className = 'preview-status error';
            return;
        }

        statusEl.textContent = '⟳ Fetching...';
        statusEl.className = 'preview-status loading';

        // Get CSRF token
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ||
                         document.querySelector('input[name="_token"]')?.value;

        if (!csrfToken) {
            statusEl.textContent = '✗ CSRF token not found';
            statusEl.className = 'preview-status error';
            console.error('CSRF token not found in page');
            return;
        }

        // Make API request - Sending UNSAVED connection and endpoint data
        fetch(`/api/rest-api/template-preview`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify({
                template_id: templateId,
                connection_index: connIdx, // Still needed to identify which connection structure is being updated
                endpoint_index: epIdx, // Still needed to identify which endpoint is being updated

                // --- NEW PAYLOAD ---
                device_id: deviceId || null,
                connection_data: currentConnectionData, // Pass the unsaved base_url
                endpoint_data: currentEndpointData      // Pass the unsaved path/method
            })
        })
            .then(response => response.text().then(text => {
                return {
                    ok: response.ok,
                    status: response.status,
                    statusText: response.statusText,
                    text: text
                };
            })
            .then(({ ok, status, statusText, text }) => {
                console.log('Response:', status, statusText, text.substring(0, 300));

                try {
                    const data = JSON.parse(text);

                    if (data.success) {
                        statusEl.textContent = '✓ Preview ready';
                        statusEl.className = 'preview-status success';

                        // Display preview
                        if (data.preview) {
                            const rawJson = JSON.stringify(data.preview, null, 2);
                            document.getElementById('raw-content-' + connIdx + '-' + epIdx).textContent = rawJson;
                            document.getElementById('structure-content-' + connIdx + '-' + epIdx).textContent = rawJson;
                            document.getElementById('sample-content-' + connIdx + '-' + epIdx).textContent = rawJson.substring(0, 500);
                        }

                        document.getElementById('api-preview-container-' + connIdx + '-' + epIdx).style.display = 'block';
                    } else {
                        statusEl.textContent = '✗ ' + (data.error || 'Error fetching preview');
                        statusEl.className = 'preview-status error';
                    }
                } catch (parseError) {
                    statusEl.textContent = `✗ HTTP ${status}: ${statusText}`;
                    statusEl.className = 'preview-status error';
                    console.error('Parse error:', parseError);
                    console.error('Response:', text);
                }
            })
            .catch(error => {
                statusEl.textContent = '✗ Network error: ' + error.message;
                statusEl.className = 'preview-status error';
                console.error('Fetch error:', error);
            });
        });
    }
});
</script>
