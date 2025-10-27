{{-- resources/views/device/partials/device_api.blade.php --}}
@php
    // Debug output
    error_log("device_api.blade.php rendering");
    error_log("Templates count: " . count($templates ?? []));
    error_log("AuthTypes count: " . count($authTypes ?? []));

    // Ensure arrays/booleans to avoid count()/foreach/JSON errors
    $templates = is_array($templates ?? null) ? $templates : [];
    $authTypes = is_array($authTypes ?? null) ? $authTypes : [];
    $autoSelectTemplate = isset($autoSelectTemplate) ? (bool) $autoSelectTemplate : false;
    $apiEnabled = $apiConfig ? true : false;
    $currentAuthType = $apiConfig?->schema?->key ?? '';
@endphp

<div class="alert alert-info">
    <strong>Debug:</strong> Templates: {{ count($templates) }}, Auth Types: {{ count($authTypes) }}
</div>

@if(!empty($device->getAttrib('rest_last_error_message')))
    <div class="alert alert-warning">
        <strong>Last Error:</strong> {{ $device->getAttrib('rest_last_error_message') }}
        @if(!empty($device->getAttrib('rest_last_error')))
            <br><small>{{ \Carbon\Carbon::createFromTimestamp((int) $device->getAttrib('rest_last_error'))->diffForHumans() }}</small>
        @endif
    </div>
@endif

<div class="form-group">
    <div class="col-sm-offset-2 col-sm-6">
        <div class="checkbox">
            <label>
                <input type="checkbox" id="rest_enabled" name="rest_enabled" value="1"
                       {{ old('rest_enabled', $apiEnabled) ? 'checked' : '' }}>
                <strong>Enable REST API discovery/polling</strong>
            </label>
        </div>
    </div>
</div>

{{-- Template Selector --}}
<div class="form-group">
    <label for="rest_template" class="col-sm-2 control-label">Template</label>
    <div class="col-sm-6">
        @php $selectedTemplate = old('rest_template', $selectedTemplate ?? ''); @endphp
        <select class="form-control" id="rest_template" name="rest_template">
            <option value="">Custom (no template)</option>
            @foreach($templates as $vendor => $template)
                <option value="{{ $vendor }}" {{ $selectedTemplate === $vendor ? 'selected' : '' }}>
                    {{ $template['name'] ?? ucfirst((string) $vendor) }}
                </option>
            @endforeach
        </select>
        <small class="text-muted">
            @if(empty($templates))
                No templates available for {{ $device->os }}. Use custom configuration.
            @elseif(count($templates) === 1)
                Template auto-selected for {{ $device->os }} devices.
            @else
                Select a pre-configured template or configure manually.
            @endif
        </small>
    </div>
</div>

{{-- Hidden vendor field (auto-populated from template) --}}
<input type="hidden" id="rest_vendor" name="rest_vendor" value="{{ old('rest_vendor', $apiConfig?->template?->key ?? '') }}">

{{-- Authentication Type Selector --}}
<div class="form-group">
    <label for="rest_auth_type" class="col-sm-2 control-label">Authentication Type <span class="text-danger">*</span></label>
    <div class="col-sm-6">
        @php $authType = old('rest_auth_type', $currentAuthType); @endphp
        <select class="form-control" id="rest_auth_type" name="rest_auth_type">
            <option value="">Select authentication type...</option>
            @foreach($authTypes as $type => $config)
                <option value="{{ $type }}" {{ $authType === $type ? 'selected' : '' }}>
                    {{ $config['name'] ?? ucfirst((string) $type) }}
                </option>
            @endforeach
        </select>
        <small class="text-muted auth-description"></small>
    </div>
</div>

{{-- Base URL --}}
<div class="form-group">
    <label for="rest_base_url" class="col-sm-2 control-label">Base URL <span class="text-danger">*</span></label>
    <div class="col-sm-6">
        <input type="url" id="rest_base_url" class="form-control" name="rest_base_url"
               value="{{ old('rest_base_url', $apiConfig?->base_url ?? '') }}"
               placeholder="https://device.example/api">
        <small class="text-muted base-url-hint"></small>
    </div>
</div>

{{-- Dynamic Auth Fields (rendered based on auth schemas from database) --}}
@foreach($authTypes as $authKey => $authSchema)
    @if(isset($authSchema['fields']) && is_array($authSchema['fields']))
        @foreach($authSchema['fields'] as $field)
            <div class="form-group auth-field auth-{{ $authKey }}" style="display: none;">
                <label for="{{ $field['name'] }}" class="col-sm-2 control-label">
                    {{ $field['label'] }}
                    @if($field['required'] ?? false)
                        <span class="text-danger">*</span>
                    @endif
                </label>
                <div class="col-sm-6">
                    @if($field['type'] === 'password')
                        <input type="password"
                               id="{{ $field['name'] }}"
                               class="form-control"
                               name="{{ $field['name'] }}"
                               placeholder="{{ $field['placeholder'] ?? 'Enter to set or replace' }}"
                               value="">
                        @if($apiConfig && $apiConfig->schema_id === $authSchema['id'] && $apiConfig->getValue($field['name']))
                            <small class="text-muted">A value is stored. Enter a new value to replace.</small>
                        @endif
                    @elseif($field['type'] === 'select' && isset($field['options']))
                        <select id="{{ $field['name'] }}"
                                class="form-control"
                                name="{{ $field['name'] }}">
                            @foreach($field['options'] as $optValue => $optLabel)
                                @php
                                    $currentValue = old($field['name'],
                                        $apiConfig && $apiConfig->schema_id === $authSchema['id']
                                            ? $apiConfig->getValue($field['name'], $field['default'] ?? '')
                                            : ($field['default'] ?? '')
                                    );
                                @endphp
                                <option value="{{ $optValue }}" {{ $currentValue == $optValue ? 'selected' : '' }}>
                                    {{ $optLabel }}
                                </option>
                            @endforeach
                        </select>
                    @else
                        @php
                            $currentValue = old($field['name'],
                                $apiConfig && $apiConfig->schema_id === $authSchema['id']
                                    ? $apiConfig->getValue($field['name'], $field['default'] ?? '')
                                    : ($field['default'] ?? '')
                            );
                        @endphp
                        <input type="{{ $field['type'] ?? 'text' }}"
                               id="{{ $field['name'] }}"
                               class="form-control"
                               name="{{ $field['name'] }}"
                               value="{{ $currentValue }}"
                               placeholder="{{ $field['placeholder'] ?? '' }}">
                    @endif
                </div>
            </div>
        @endforeach
    @endif
@endforeach

{{-- Other Settings --}}
<div class="form-group">
    <label for="rest_headers" class="col-sm-2 control-label">Extra Headers</label>
    <div class="col-sm-6">
        @php
            $headers = old('rest_headers', '');
            if (!$headers && $apiConfig && $apiConfig->extra_headers) {
                $headers = implode("\n", array_map(fn($k, $v) => "$k: $v", array_keys($apiConfig->extra_headers), $apiConfig->extra_headers));
            }
        @endphp
        <textarea id="rest_headers" class="form-control" name="rest_headers" rows="2"
                  placeholder="Header-Name: value (one per line)">{{ $headers }}</textarea>
        <small class="text-muted">One header per line in format: Header-Name: value</small>
    </div>
</div>

<div class="form-group">
    <div class="col-sm-offset-2 col-sm-6">
        <div class="checkbox">
            <label>
                @php $verify = (bool) old('rest_verify_tls', $apiConfig?->verify_ssl ?? true); @endphp
                <input type="checkbox" id="rest_verify_tls" name="rest_verify_tls" value="1"
                       {{ $verify ? 'checked' : '' }}>
                Verify TLS/SSL certificates
            </label>
            <small class="text-muted d-block">Uncheck to disable SSL certificate verification (not recommended for production).</small>
        </div>
    </div>
</div>

<hr>

{{-- Endpoints Management Section --}}
<div class="form-group">
    <div class="col-sm-offset-2 col-sm-10">
        <h4>API Endpoints <small class="text-muted">Configure which endpoints to poll</small></h4>
    </div>
</div>

<div class="form-group">
    <div class="col-sm-offset-2 col-sm-10">
        <div class="panel panel-default">
            <div class="panel-heading">
                <button type="button" id="add-endpoint-btn" class="btn btn-xs btn-success pull-right">
                    <i class="fa fa-plus"></i> Add Endpoint
                </button>
                Configured Endpoints
            </div>
            <div class="panel-body" style="max-height: 400px; overflow-y: auto;">
                <table class="table table-condensed table-hover" id="endpoints-table">
                    <thead>
                        <tr>
                            <th style="width: 5%;"><input type="checkbox" id="toggle-all-endpoints"></th>
                            <th style="width: 20%;">Name</th>
                            <th style="width: 25%;">Path</th>
                            <th style="width: 10%;">Method</th>
                            <th style="width: 15%;">Category</th>
                            <th style="width: 15%;">Poll Interval (s)</th>
                            <th style="width: 10%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="endpoints-tbody">
                        {{-- Endpoints will be populated via JavaScript --}}
                    </tbody>
                </table>
                <p class="text-muted text-center" id="no-endpoints-msg" style="display: none;">
                    No endpoints configured. Select a template or add endpoints manually.
                </p>
            </div>
        </div>
    </div>
</div>

<input type="hidden" name="rest_endpoints" id="rest_endpoints" value="">

<hr>

{{-- Test Connection and Health Status --}}
<div class="form-group">
    <div class="col-sm-offset-2 col-sm-6">
        <button type="button" id="test-api-connection" class="btn btn-info">
            <i class="fa fa-plug"></i> Test Connection
        </button>

        @php $errorCount = (int) $device->getAttrib('rest_error_count'); @endphp
        @if($errorCount > 0)
            <button type="button" id="reset-circuit-breaker" class="btn btn-warning">
                <i class="fa fa-refresh"></i> Reset Error Counter
            </button>
        @endif
    </div>
</div>

@php $lastSuccess = (int) $device->getAttrib('rest_last_success'); @endphp
@if($lastSuccess > 0)
<div class="form-group">
    <div class="col-sm-offset-2 col-sm-6">
        <small class="text-muted">
            <i class="fa fa-check-circle text-success"></i>
            Last success: {{ \Carbon\Carbon::createFromTimestamp($lastSuccess)->diffForHumans() }}
            @php $avgLatency = (int) $device->getAttrib('rest_avg_latency_ms'); @endphp
            @if($avgLatency > 0)
                (avg {{ $avgLatency }}ms)
            @endif
        </small>
    </div>
</div>
@endif

@push('scripts')
<script>
// Device information
const deviceHostname = '{{ $device->hostname }}';
const deviceSysName = '{{ $device->sysName ?? $device->hostname }}';
const autoSelectTemplate = {{ $autoSelectTemplate ? 'true' : 'false' }};

// Template metadata and auth config (always arrays by guards above)
const templates = @json($templates);
const authTypes = @json($authTypes);
const configuredEndpoints = [];

// Pre-load all template data for instant switching
const allTemplateData = {
@foreach($templates as $vendor => $template)
    @php
        $fullTemplate = \LibreNMS\Util\ApiTemplateManager::loadTemplate($vendor);
    @endphp
    '{{ $vendor }}': @json($fullTemplate ?? []),
@endforeach
};

// Endpoints storage
let endpoints = Array.isArray(configuredEndpoints) ? configuredEndpoints : [];

// Initialize on page load
$(document).ready(function() {
    // Show appropriate auth fields for saved auth type
    updateAuthFieldVisibility();

    // Render saved endpoints
    renderEndpointsTable();
    updateEndpointsHiddenField();

    // Update auth description if auth type is already set
    const authType = $('#rest_auth_type').val();
    if (authType && authTypes[authType]) {
        $('.auth-description').text(authTypes[authType].description || '');
    }

    // Auto-select and apply template if requested and nothing is configured yet
    if (autoSelectTemplate && endpoints.length === 0) {
        const templateName = $('#rest_template').val();
        if (templateName) {
            loadTemplateData(templateName);
        }
    }
});

// Template selection handler
$('#rest_template').on('change', function() {
    const templateName = $(this).val();
    if (templateName) {
        // User selected a template - load and apply it
        loadTemplateData(templateName);
    } else {
        // User selected "Custom" - clear vendor but keep existing endpoints
        $('#rest_vendor').val('');
        toastr.info('Switched to custom configuration');
    }
});

// Load template data (from pre-loaded data)
function loadTemplateData(templateName) {
    if (allTemplateData[templateName]) {
        applyTemplate(allTemplateData[templateName]);
    } else {
        toastr.error('Template not found: ' + templateName);
        console.error('Template not found:', templateName);
    }
}

// Apply template to form
function applyTemplate(template) {
    if (!template || typeof template !== 'object') return;

    // Set vendor name
    if (template.vendor) {
        $('#rest_vendor').val(template.vendor);
    }

    // Build and set base URL from pattern using device hostname
    if (template.base_url_pattern) {
        const baseUrl = template.base_url_pattern.replace('{hostname}', deviceHostname);
        $('#rest_base_url').val(baseUrl);
        $('.base-url-hint').text('Auto-populated from device hostname');
    } else if (template.base_url_example) {
        $('#rest_base_url').attr('placeholder', template.base_url_example);
        $('.base-url-hint').text('Example: ' + template.base_url_example);
    }

    // Set auth type
    if (template.auth_type) {
        $('#rest_auth_type').val(template.auth_type).trigger('change');
    }

    // Set default settings
    if (template.default_settings) {
        $('#rest_verify_tls').prop('checked', !!template.default_settings.verify_tls);
        $('#rest_timeout_ms').val(parseInt(template.default_settings.timeout_ms ?? 5000, 10));
        $('#rest_rate_limit_qps').val(parseInt(template.default_settings.rate_limit_qps ?? 10, 10));
    }

    // Load endpoints from template (always replace when template is selected)
    if (Array.isArray(template.endpoints) && template.endpoints.length > 0) {
        endpoints = template.endpoints.map(function(ep) { return Object.assign({}, ep); }); // Deep copy
        renderEndpointsTable();
        updateEndpointsHiddenField();
        toastr.success('Template applied with ' + endpoints.length + ' endpoint(s)');
    }
}

// Auth type change handler
$('#rest_auth_type').on('change', function() {
    updateAuthFieldVisibility();

    // Update description
    const authType = $(this).val();
    if (authType && authTypes[authType]) {
        $('.auth-description').text(authTypes[authType].description || '');
    } else {
        $('.auth-description').text('');
    }
});

// Show/hide auth fields based on selected type
function updateAuthFieldVisibility() {
    const authType = $('#rest_auth_type').val();

    // Hide all auth fields
    $('.auth-field').hide();

    // Show fields for selected auth type
    if (authType) {
        $('.auth-' + authType).show();
    }
}

// Render endpoints table
function renderEndpointsTable() {
    const tbody = $('#endpoints-tbody');
    tbody.empty();

    if (!Array.isArray(endpoints) || endpoints.length === 0) {
        $('#no-endpoints-msg').show();
        return;
    }

    $('#no-endpoints-msg').hide();

    endpoints.forEach((endpoint, index) => {
        const safeName = escapeHtml(endpoint?.name ?? '');
        const safePath = escapeHtml(endpoint?.path ?? '');
        const method = (endpoint?.method || 'GET').toUpperCase();
        const category = endpoint?.category || 'general';
        const pollInterval = parseInt(endpoint?.poll_interval ?? 60, 10);
        const enabled = endpoint?.enabled ?? true;

        const row = `
            <tr data-index="${index}">
                <td>
                    <input type="checkbox" class="endpoint-enabled" data-index="${index}" ${enabled ? 'checked' : ''}>
                </td>
                <td>${safeName}</td>
                <td><code>${safePath}</code></td>
                <td><span class="label label-info">${method}</span></td>
                <td>${category}</td>
                <td>${pollInterval}</td>
                <td>
                    <button type="button" class="btn btn-xs btn-primary edit-endpoint" data-index="${index}">
                        <i class="fa fa-edit"></i>
                    </button>
                    <button type="button" class="btn btn-xs btn-danger delete-endpoint" data-index="${index}">
                        <i class="fa fa-trash"></i>
                    </button>
                </td>
            </tr>
        `;
        tbody.append(row);
    });
}

// Update hidden field with endpoints JSON
function updateEndpointsHiddenField() {
    try {
        $('#rest_endpoints').val(JSON.stringify(endpoints || []));
    } catch (e) {
        $('#rest_endpoints').val('[]');
    }
}

// Toggle endpoint enabled status
$(document).on('change', '.endpoint-enabled', function() {
    const index = $(this).data('index');
    if (endpoints[index]) {
        endpoints[index].enabled = $(this).is(':checked');
        updateEndpointsHiddenField();
    }
});

// Toggle all endpoints
$('#toggle-all-endpoints').on('change', function() {
    const checked = $(this).is(':checked');
    $('.endpoint-enabled').prop('checked', checked).each(function() {
        const index = $(this).data('index');
        if (endpoints[index]) {
            endpoints[index].enabled = checked;
        }
    });
    updateEndpointsHiddenField();
});

// Add endpoint
$('#add-endpoint-btn').on('click', function() {
    showEndpointModal();
});

// Edit endpoint
$(document).on('click', '.edit-endpoint', function() {
    const index = $(this).data('index');
    showEndpointModal(endpoints[index] || null, index);
});

// Delete endpoint
$(document).on('click', '.delete-endpoint', function() {
    if (!confirm('Are you sure you want to delete this endpoint?')) {
        return;
    }

    const index = $(this).data('index');
    endpoints.splice(index, 1);
    renderEndpointsTable();
    updateEndpointsHiddenField();
});

// Show endpoint modal (simplified - using prompt for now)
function showEndpointModal(endpoint = null, index = null) {
    const isEdit = endpoint !== null;

    const name = prompt('Endpoint Name:', endpoint?.name || '');
    if (!name) return;

    const path = prompt('Endpoint Path (e.g., /api/status):', endpoint?.path || '/');
    if (!path) return;

    const method = prompt('HTTP Method (GET/POST):', (endpoint?.method || 'GET').toUpperCase());
    const category = prompt('Category:', endpoint?.category || 'general');
    const pollInterval = parseInt(prompt('Poll Interval (seconds):', endpoint?.poll_interval || 60), 10);
    const description = prompt('Description:', endpoint?.description || '');

    const newEndpoint = {
        id: endpoint?.id || ('custom_' + Date.now()),
        name: name,
        path: path,
        method: method.toUpperCase(),
        category: category,
        poll_interval: pollInterval,
        description: description,
        enabled: endpoint?.enabled ?? true
    };

    if (isEdit && index !== null) {
        endpoints[index] = newEndpoint;
    } else {
        endpoints.push(newEndpoint);
    }

    renderEndpointsTable();
    updateEndpointsHiddenField();
    toastr.success(isEdit ? 'Endpoint updated' : 'Endpoint added');
}

// Helper function
function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
}

// Test API connection
$('#test-api-connection').on('click', function() {
    const btn = $(this);
    const originalHtml = btn.html();
    btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Testing...');

    fetch('{{ route("device.test-api-connection", $device->device_id) }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            rest_enabled: $('#rest_enabled').is(':checked'),
            rest_template: $('#rest_template').val(),
            rest_vendor: $('#rest_vendor').val(),
            rest_base_url: $('#rest_base_url').val(),
            rest_auth_type: $('#rest_auth_type').val(),
            rest_token: $('#rest_token').val(),
            rest_username: $('#rest_username').val(),
            rest_password: $('#rest_password').val(),
            rest_verify_tls: $('#rest_verify_tls').is(':checked'),
            rest_timeout_ms: $('#rest_timeout_ms').val()
        })
    })
    .then(r => r.json())
    .then(d => {
        if (d && d.success) {
            let message = d.message || 'Connection successful!';
            if (d.test_path) {
                message += ' (tested: ' + d.test_path + ')';
            }
            toastr.success(message);
        } else {
            toastr.error('Connection failed: ' + ((d && d.error) || 'Unknown error'));
        }
    })
    .catch(e => {
        toastr.error('Connection test failed: ' + e.message);
    })
    .finally(() => {
        btn.prop('disabled', false).html(originalHtml);
    });
});

// Reset circuit breaker
$('#reset-circuit-breaker').on('click', function() {
    const btn = $(this);
    if (!confirm('Reset the error counter and circuit breaker for this device?')) {
        return;
    }

    btn.prop('disabled', true);

    fetch('{{ route("device.reset-circuit-breaker", $device->device_id) }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        }
    })
    .then(r => r.json())
    .then(d => {
        if (d && d.success) {
            toastr.success('Circuit breaker reset successfully');
            setTimeout(() => location.reload(), 1000);
        } else {
            toastr.error('Failed to reset circuit breaker');
        }
    })
    .catch(() => {
        toastr.error('Failed to reset circuit breaker');
    })
    .finally(() => {
        btn.prop('disabled', false);
    });
});
</script>
@endpush