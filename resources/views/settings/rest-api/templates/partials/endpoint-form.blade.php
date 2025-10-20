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
                       placeholder="/api/endpoint"
                       data-conn-idx="{{ $connectionIndex }}"
                       data-ep-idx="{{ $endpointIndex }}"
                       required>
                <small class="form-text text-muted">Example: /api/v1/data</small>
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
                Configure the endpoint above, then click "Fetch API Preview" to see available fields.
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
                        <ul class="nav nav-tabs" role="tablist" id="preview-tabs-{{ $connectionIndex }}-{{ $endpointIndex }}">
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

            {{-- Recommended Mappings (Initially Hidden) --}}
            <div id="recommendations-container-{{ $connectionIndex }}-{{ $endpointIndex }}" 
                 style="display: none; margin-bottom: 20px;">
                <div class="card bg-light border-success">
                    <div class="card-header bg-success text-white">
                        <h6 class="mb-0">
                            <i class="fas fa-lightbulb"></i> Recommended Mappings
                        </h6>
                    </div>
                    <div class="card-body">
                        <div id="recommendations-list-{{ $connectionIndex }}-{{ $endpointIndex }}"></div>
                    </div>
                </div>
            </div>

            {{-- Interactive Field Mapper --}}
            <div id="field-mapper-container-{{ $connectionIndex }}-{{ $endpointIndex }}" 
                 style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">Configure Mappings</h6>
                    </div>
                    <div class="card-body">
                        <div id="field-mapper-{{ $connectionIndex }}-{{ $endpointIndex }}">
                            {{-- Dynamically populated with field rows --}}
                        </div>
                        <button type="button" 
                                class="btn btn-sm btn-secondary mt-2 add-field-mapping"
                                data-conn-idx="{{ $connectionIndex }}"
                                data-ep-idx="{{ $endpointIndex }}">
                            <i class="fas fa-plus"></i> Add Mapping
                        </button>
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
                          placeholder="Enter or paste JSON mapping here..."
                          style="white-space: pre; resize: vertical;">{{ old('metric_map_json', json_encode($endpoint['metric_map'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) }}</textarea>
                <small class="form-text text-muted">
                    Paste valid JSON mapping. Example: <code>{"storage_size": "items.0.space.total_physical"}</code>
                </small>
                <button type="button" 
                        class="btn btn-sm btn-secondary mt-2 beautify-json"
                        data-conn-idx="{{ $connectionIndex }}"
                        data-ep-idx="{{ $endpointIndex }}"
                        id="beautifyJson_{{ $connectionIndex }}_{{ $endpointIndex }}">
                    <i class="fas fa-indent"></i> Beautify JSON
                </button>
                <div id="jsonError_{{ $connectionIndex }}_{{ $endpointIndex }}" class="text-danger mt-2" style="display: none;"></div>
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
    .field-mapping-row {
        display: flex;
        gap: 10px;
        margin-bottom: 10px;
        align-items: flex-start;
    }
    .field-mapping-row > div {
        flex: 1;
    }
    .field-mapping-row .btn-sm {
        margin-top: 23px;
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
            const methodInput = document.querySelector('.endpoint-method[data-conn-idx="' + connIdx + '"][data-ep-idx="' + epIdx + '"]');
            const statusEl = document.getElementById('preview-status-' + connIdx + '-' + epIdx);
            
            if (!pathInput || !pathInput.value.trim()) {
                statusEl.textContent = '✗ Please enter an API path first';
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

            // Make AJAX request to get API preview
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || 
                             document.querySelector('input[name="_token"]')?.value;
            
            if (!csrfToken) {
                statusEl.textContent = '✗ CSRF token not found';
                statusEl.className = 'preview-status error';
                console.error('CSRF token not found in page');
                return;
            }

            fetch(`/api/rest-api/template-preview`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    template_id: templateId,
                    connection_index: connIdx,
                    endpoint_index: epIdx
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    statusEl.textContent = '✓ Preview ready';
                    statusEl.className = 'preview-status success';

                    // Populate preview containers
                    displayPreview(data.preview, connIdx, epIdx);
                    displayRecommendations(data.recommendations, connIdx, epIdx, data.preview);
                    populateFieldMapper(data.preview, connIdx, epIdx);

                    // Show containers
                    document.getElementById('api-preview-container-' + connIdx + '-' + epIdx).style.display = 'block';
                    document.getElementById('recommendations-container-' + connIdx + '-' + epIdx).style.display = 'block';
                    document.getElementById('field-mapper-container-' + connIdx + '-' + epIdx).style.display = 'block';
                } else {
                    statusEl.textContent = '✗ ' + (data.error || 'Error fetching preview');
                    statusEl.className = 'preview-status error';
                }
            })
            .catch(error => {
                statusEl.textContent = '✗ Network error';
                statusEl.className = 'preview-status error';
                console.error('Fetch error:', error);
            });
        });
    }

    // Beautify JSON Button Handler
    const beautifyBtn = document.getElementById('beautifyJson_' + connIdx + '_' + epIdx);
    if (beautifyBtn) {
        beautifyBtn.addEventListener('click', function() {
            const textarea = document.getElementById('metric_map_json_' + connIdx + '_' + epIdx);
            const errorDiv = document.getElementById('jsonError_' + connIdx + '_' + epIdx);
            
            try {
                const json = JSON.parse(textarea.value);
                textarea.value = JSON.stringify(json, null, 2);
                errorDiv.style.display = 'none';
            } catch (e) {
                errorDiv.textContent = 'Invalid JSON: ' + e.message;
                errorDiv.style.display = 'block';
            }
        });
    }

    // Helper function to display API preview
    function displayPreview(data, connIdx, epIdx) {
        if (!data) return;

        const rawJson = JSON.stringify(data, null, 2);
        document.getElementById('raw-content-' + connIdx + '-' + epIdx).textContent = rawJson;

        // Build structure view
        const structure = buildStructure(data);
        document.getElementById('structure-content-' + connIdx + '-' + epIdx).textContent = structure;

        // Build sample data view
        const sample = buildSampleData(data);
        document.getElementById('sample-content-' + connIdx + '-' + epIdx).textContent = sample;
    }

    function buildStructure(obj, indent = '') {
        let result = '';
        if (Array.isArray(obj)) {
            result += '[\n';
            if (obj.length > 0) {
                result += indent + '  {\n';
                const keys = Object.keys(obj[0]);
                keys.forEach((key, idx) => {
                    const val = obj[0][key];
                    const type = typeof val === 'object' ? (Array.isArray(val) ? 'array' : 'object') : typeof val;
                    result += indent + '    ' + key + ': ' + type;
                    result += (idx < keys.length - 1) ? ',\n' : '\n';
                });
                result += indent + '  }\n';
            }
            result += indent + ']';
        } else if (typeof obj === 'object' && obj !== null) {
            result += '{\n';
            const keys = Object.keys(obj);
            keys.forEach((key, idx) => {
                const val = obj[key];
                const type = typeof val === 'object' ? (Array.isArray(val) ? 'array' : 'object') : typeof val;
                result += indent + '  ' + key + ': ' + type;
                result += (idx < keys.length - 1) ? ',\n' : '\n';
            });
            result += indent + '}';
        }
        return result;
    }

    function buildSampleData(obj) {
        if (Array.isArray(obj) && obj.length > 0) {
            return JSON.stringify(obj[0], null, 2);
        }
        return JSON.stringify(obj, null, 2).substring(0, 500) + '...';
    }

    function displayRecommendations(recommendations, connIdx, epIdx, apiData) {
        const container = document.getElementById('recommendations-list-' + connIdx + '-' + epIdx);
        if (!recommendations || recommendations.length === 0) {
            container.innerHTML = '<p class="text-muted">No recommendations available</p>';
            return;
        }

        let html = '';
        recommendations.forEach(rec => {
            const confidence = rec.confidence || 0;
            const color = confidence > 0.8 ? 'success' : confidence > 0.5 ? 'warning' : 'info';
            html += `
                <div class="row mb-2 align-items-center">
                    <div class="col-md-4">
                        <small><strong>${rec.api_field}</strong></small>
                    </div>
                    <div class="col-md-4">
                        <small class="badge badge-${color}">${(confidence * 100).toFixed(0)}% match</small>
                    </div>
                    <div class="col-md-3">
                        <small>${rec.librenms_field}</small>
                    </div>
                    <div class="col-md-1">
                        <button type="button" class="btn btn-xs btn-primary apply-recommendation" 
                                data-api-field="${rec.api_field}" 
                                data-librenms-field="${rec.librenms_field}"
                                data-table="${rec.librenms_table}">
                            Apply
                        </button>
                    </div>
                </div>
            `;
        });
        container.innerHTML = html;

        // Add event listeners for apply buttons
        container.querySelectorAll('.apply-recommendation').forEach(btn => {
            btn.addEventListener('click', function() {
                applyRecommendation(this.dataset.apiField, this.dataset.librenmsField, this.dataset.table, connIdx, epIdx);
            });
        });
    }

    function populateFieldMapper(apiData, connIdx, epIdx) {
        const container = document.getElementById('field-mapper-' + connIdx + '-' + epIdx);
        const fields = extractFields(apiData);
        
        let html = '';
        fields.forEach(field => {
            html += `
                <div class="field-mapping-row">
                    <div>
                        <label class="small">API Field</label>
                        <input type="text" class="form-control form-control-sm" value="${field}" readonly>
                    </div>
                    <div>
                        <label class="small">LibreNMS Table</label>
                        <select class="form-control form-control-sm libreNMS-table" data-api-field="${field}">
                            <option value="">-- Select --</option>
                            <option value="storage">Storage</option>
                            <option value="ports">Ports</option>
                            <option value="sensors">Sensors</option>
                            <option value="devices">Device</option>
                        </select>
                    </div>
                    <div>
                        <label class="small">LibreNMS Field</label>
                        <input type="text" class="form-control form-control-sm libreNMS-field" data-api-field="${field}" placeholder="e.g., storage_size">
                    </div>
                    <div>
                        <button type="button" class="btn btn-sm btn-danger remove-mapping" data-api-field="${field}">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;
        syncFieldMapperToJson(connIdx, epIdx);

        // Update JSON textarea when fields change
        container.querySelectorAll('select, input.libreNMS-field').forEach(el => {
            el.addEventListener('change', () => syncFieldMapperToJson(connIdx, epIdx));
        });

        // Handle remove mapping
        container.querySelectorAll('.remove-mapping').forEach(btn => {
            btn.addEventListener('click', function() {
                this.closest('.field-mapping-row').remove();
                syncFieldMapperToJson(connIdx, epIdx);
            });
        });
    }

    function extractFields(obj, prefix = '') {
        let fields = [];
        if (Array.isArray(obj) && obj.length > 0) {
            fields = extractFields(obj[0], prefix);
        } else if (typeof obj === 'object' && obj !== null) {
            Object.keys(obj).forEach(key => {
                const fullPath = prefix ? prefix + '.' + key : key;
                fields.push(fullPath);
            });
        }
        return fields.sort();
    }

    function syncFieldMapperToJson(connIdx, epIdx) {
        const mapping = {};
        const container = document.getElementById('field-mapper-' + connIdx + '-' + epIdx);
        
        container.querySelectorAll('.field-mapping-row').forEach(row => {
            const apiField = row.querySelector('input').value;
            const table = row.querySelector('.libreNMS-table').value;
            const field = row.querySelector('.libreNMS-field').value;
            
            if (apiField && table && field) {
                mapping[apiField] = `${table}.${field}`;
            }
        });

        document.getElementById('metric_map_json_' + connIdx + '_' + epIdx).value = JSON.stringify(mapping, null, 2);
    }

    function applyRecommendation(apiField, librenmsField, table, connIdx, epIdx) {
        const mapping = {};
        mapping[apiField] = `${table}.${librenmsField}`;
        
        const jsonEl = document.getElementById('metric_map_json_' + connIdx + '_' + epIdx);
        let current = JSON.parse(jsonEl.value || '{}');
        current = Object.assign(current, mapping);
        jsonEl.value = JSON.stringify(current, null, 2);
    }
});
</script>
