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

    {{-- ========== METRIC MAPPING SECTION - INTEGRATED UI ========== --}}
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
                1. Select a device below 2. Click "Fetch API Preview" 3. Map fields to metrics
            </div>

            {{-- Device Selection for Testing --}}
            <div class="form-group mb-3">
                <label for="test_device_{{ $connectionIndex }}_{{ $endpointIndex }}">
                    <i class="fas fa-server"></i> <strong>Select Device for Testing</strong> <span class="text-danger">*</span>
                </label>
                <select class="form-control test-device" 
                        id="test_device_{{ $connectionIndex }}_{{ $endpointIndex }}"
                        data-conn-idx="{{ $connectionIndex }}"
                        data-ep-idx="{{ $endpointIndex }}">
                    <option value="">-- SELECT A DEVICE TO TEST --</option>
                    @foreach(\App\Models\Device::orderBy('hostname')->get() as $device)
                        <option value="{{ $device->device_id }}">
                            {{ $device->hostname }}
                            @if($device->ip)
                                ({{ $device->ip }})
                            @endif
                        </option>
                    @endforeach
                </select>
                <small class="form-text text-muted">
                    <strong>Required to:</strong> Replace {device_hostname}, {device_ip} placeholders + obtain session tokens for authentication
                </small>
            </div>

            {{-- API Preview Fetch Button --}}
            <div class="form-group mb-3">
                <button type="button" 
                        class="btn btn-info btn-sm fetch-api-preview"
                        data-conn-idx="{{ $connectionIndex }}"
                        data-ep-idx="{{ $endpointIndex }}"
                        id="fetch-preview-{{ $connectionIndex }}-{{ $endpointIndex }}">
                    <i class="fas fa-download"></i> Fetch API Preview
                </button>
                <span class="preview-status ml-2" id="preview-status-{{ $connectionIndex }}-{{ $endpointIndex }}"></span>
            </div>

            {{-- API Response Preview (Initially Hidden) --}}
            <div id="api-preview-container-{{ $connectionIndex }}-{{ $endpointIndex }}" 
                 style="display: none; margin-bottom: 20px;">
                <div class="card bg-light">
                    <div class="card-header">
                        <h6 class="mb-0">API Response Preview</h6>
                    </div>
                    <div class="card-body">
                        <ul class="nav nav-tabs" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link active" data-toggle="tab" href="#preview-structure-{{ $connectionIndex }}-{{ $endpointIndex }}" role="tab">
                                    <i class="fas fa-tree"></i> Structure
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-toggle="tab" href="#preview-sample-{{ $connectionIndex }}-{{ $endpointIndex }}" role="tab">
                                    <i class="fas fa-database"></i> Sample Data
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-toggle="tab" href="#preview-raw-{{ $connectionIndex }}-{{ $endpointIndex }}" role="tab">
                                    <i class="fas fa-code"></i> Raw JSON
                                </a>
                            </li>
                        </ul>

                        <div class="tab-content mt-2">
                            <div id="preview-structure-{{ $connectionIndex }}-{{ $endpointIndex }}" class="tab-pane fade show active" role="tabpanel">
                                <pre id="structure-content-{{ $connectionIndex }}-{{ $endpointIndex }}" style="max-height: 300px; overflow-y: auto;"></pre>
                            </div>
                            <div id="preview-sample-{{ $connectionIndex }}-{{ $endpointIndex }}" class="tab-pane fade" role="tabpanel">
                                <pre id="sample-content-{{ $connectionIndex }}-{{ $endpointIndex }}" style="max-height: 300px; overflow-y: auto;"></pre>
                            </div>
                            <div id="preview-raw-{{ $connectionIndex }}-{{ $endpointIndex }}" class="tab-pane fade" role="tabpanel">
                                <pre id="raw-content-{{ $connectionIndex }}-{{ $endpointIndex }}" style="max-height: 300px; overflow-y: auto;"></pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Fallback: JSON textarea (for manual entry) --}}
            <div class="form-group">
                <label for="metric_map_json_{{ $connectionIndex }}_{{ $endpointIndex }}">Metric Mapping (JSON)</label>
                <textarea id="metric_map_json_{{ $connectionIndex }}_{{ $endpointIndex }}"
                          name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][metric_map]"
                          class="form-control font-monospace metric-map-json"
                          rows="10"
                          placeholder="Paste JSON mapping here after fetching preview..."
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
    .preview-status.loading {
        color: #0066cc;
    }
    .preview-status.success {
        color: #28a745;
    }
    .preview-status.error {
        color: #dc3545;
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

            // Check if path contains placeholders
            const pathValue = pathInput.value.trim();
            const hasPlaceholders = /{device_hostname}|{device_ip}|{device_sysname}|{device_attrib:/.test(pathValue);
            
            if (hasPlaceholders) {
                // Get selected device
                const deviceSelect = document.querySelector('.test-device[data-conn-idx="' + connIdx + '"][data-ep-idx="' + epIdx + '"]');
                const deviceId = deviceSelect ? deviceSelect.value : null;
                
                if (!deviceId) {
                    statusEl.textContent = '✗ Path has {device_*} placeholders - SELECT A DEVICE';
                    statusEl.className = 'preview-status error';
                    return;
                }
            }

            if (!templateId) {
                statusEl.textContent = '✗ Template not found';
                statusEl.className = 'preview-status error';
                return;
            }

            statusEl.textContent = '⟳ Fetching...';
            statusEl.className = 'preview-status loading';

            // Get selected device
            const deviceSelect = document.querySelector('.test-device[data-conn-idx="' + connIdx + '"][data-ep-idx="' + epIdx + '"]');
            const deviceId = deviceSelect ? deviceSelect.value : null;
            
            // Get CSRF token
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || 
                             document.querySelector('input[name="_token"]')?.value;
            
            if (!csrfToken) {
                statusEl.textContent = '✗ CSRF token not found';
                statusEl.className = 'preview-status error';
                console.error('CSRF token not found in page');
                return;
            }

            console.log('Fetching preview with device_id:', deviceId, 'template_id:', templateId);

            // Make API request
            fetch(`/api/rest-api/template-preview`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    template_id: templateId,
                    connection_index: connIdx,
                    endpoint_index: epIdx,
                    device_id: deviceId || null
                })
            })
            .then(response => response.text().then(text => {
                return {
                    ok: response.ok,
                    status: response.status,
                    statusText: response.statusText,
                    text: text
                };
            }))
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
