{{-- resources/views/device/partials/device_api.blade.php --}}
@if(!empty($device->getAttrib('rest_last_error_message')))
    <div class="alert alert-warning">
        <strong>Last Error:</strong> {{ $device->getAttrib('rest_last_error_message') }}
        @if(!empty($device->getAttrib('rest_last_error')))
            <br><small>{{ \Carbon\Carbon::createFromTimestamp($device->getAttrib('rest_last_error'))->diffForHumans() }}</small>
        @endif
    </div>
@endif

<div class="form-group">
    <div class="col-sm-offset-2 col-sm-6">
        <div class="checkbox">
            <label>
                <input type="checkbox" id="rest_enabled" name="rest_enabled" value="1"
                       {{ old('rest_enabled', $device->getAttrib('rest_enabled', 0)) ? 'checked' : '' }}>
                <strong>Enable REST API discovery/polling</strong>
            </label>
        </div>
    </div>
</div>

{{-- Template Selector --}}
<div class="form-group">
    <label for="rest_template" class="col-sm-2 control-label">Template</label>
    <div class="col-sm-6">
        @php $selectedTemplate = old('rest_template', $device->getAttrib('rest_template', '')); @endphp
        <select class="form-control" id="rest_template" name="rest_template">
            <option value="">Custom (no template)</option>
            @foreach($templates as $vendor => $template)
                <option value="{{ $vendor }}" {{ $selectedTemplate === $vendor ? 'selected' : '' }}>
                    {{ $template['name'] }}
                </option>
            @endforeach
        </select>
        <small class="text-muted">
            @if(count($templates) === 0)
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
<input type="hidden" id="rest_vendor" name="rest_vendor" value="{{ old('rest_vendor', $device->getAttrib('rest_vendor', '')) }}">

{{-- Authentication Type Selector --}}
<div class="form-group">
    <label for="rest_auth_type" class="col-sm-2 control-label">Authentication Type <span class="text-danger">*</span></label>
    <div class="col-sm-6">
        @php $authType = old('rest_auth_type', $device->getAttrib('rest_auth_type', '')); @endphp
        <select class="form-control" id="rest_auth_type" name="rest_auth_type">
            <option value="">Select authentication type...</option>
            @foreach($authTypes as $type => $config)
                <option value="{{ $type }}" {{ $authType === $type ? 'selected' : '' }}>
                    {{ $config['name'] }}
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
               value="{{ old('rest_base_url', $device->getAttrib('rest_base_url', '')) }}"
               placeholder="https://device.example/api">
        <small class="text-muted base-url-hint"></small>
    </div>
</div>

{{-- Auth Fields (dynamically shown based on auth type) --}}

{{-- Bearer Token --}}
<div class="form-group auth-field auth-bearer auth-apikey" style="display: none;">
    <label for="rest_token" class="col-sm-2 control-label">Token / API Key</label>
    <div class="col-sm-6">
        <input type="password" id="rest_token" class="form-control" name="rest_token"
               placeholder="Enter to set or replace" value="">
        @if(!empty($device->getAttrib('rest_token_enc')))
            <small class="text-muted">A token is stored. Enter a new value to replace.</small>
        @endif
    </div>
</div>

{{-- Basic Auth --}}
<div class="form-group auth-field auth-basic" style="display: none;">
    <label for="rest_username" class="col-sm-2 control-label">Username</label>
    <div class="col-sm-6">
        <input type="text" id="rest_username" class="form-control" name="rest_username"
               value="{{ old('rest_username', $device->getAttrib('rest_username', '')) }}">
    </div>
</div>

<div class="form-group auth-field auth-basic" style="display: none;">
    <label for="rest_password" class="col-sm-2 control-label">Password</label>
    <div class="col-sm-6">
        <input type="password" id="rest_password" class="form-control" name="rest_password" value="">
        @if(!empty($device->getAttrib('rest_password_enc')))
            <small class="text-muted">A password is stored. Enter a new value to replace.</small>
        @endif
    </div>
</div>

{{-- Proxmox Token Auth --}}
<div class="form-group auth-field auth-token" style="display: none;">
    <label for="proxmox_token_user" class="col-sm-2 control-label">Token User@Realm</label>
    <div class="col-sm-6">
        <input type="text" id="proxmox_token_user" class="form-control" name="proxmox_token_user"
               value="{{ old('proxmox_token_user', $device->getAttrib('proxmox_token_user', '')) }}"
               placeholder="user@pve">
    </div>
</div>

<div class="form-group auth-field auth-token" style="display: none;">
    <label for="proxmox_token_id" class="col-sm-2 control-label">Token ID</label>
    <div class="col-sm-6">
        <input type="text" id="proxmox_token_id" class="form-control" name="proxmox_token_id"
               value="{{ old('proxmox_token_id', $device->getAttrib('proxmox_token_id', '')) }}"
               placeholder="tokenid">
    </div>
</div>

<div class="form-group auth-field auth-token" style="display: none;">
    <label for="proxmox_token" class="col-sm-2 control-label">Token Secret</label>
    <div class="col-sm-6">
        <input type="password" id="proxmox_token" class="form-control" name="proxmox_token"
               placeholder="Enter to set or replace" value="">
        @if(!empty($device->getAttrib('proxmox_token_enc')))
            <small class="text-muted">A token secret is stored. Enter a new value to replace.</small>
        @endif
    </div>
</div>

{{-- Proxmox Ticket Auth --}}
<div class="form-group auth-field auth-ticket" style="display: none;">
    <label for="proxmox_username" class="col-sm-2 control-label">Username@Realm</label>
    <div class="col-sm-6">
        <input type="text" id="proxmox_username" class="form-control" name="proxmox_username"
               value="{{ old('proxmox_username', $device->getAttrib('proxmox_username', '')) }}"
               placeholder="root@pam">
    </div>
</div>

<div class="form-group auth-field auth-ticket" style="display: none;">
    <label for="proxmox_password" class="col-sm-2 control-label">Password</label>
    <div class="col-sm-6">
        <input type="password" id="proxmox_password" class="form-control" name="proxmox_password" value="">
        @if(!empty($device->getAttrib('proxmox_password_enc')))
            <small class="text-muted">A password is stored. Enter a new value to replace.</small>
        @endif
    </div>
</div>

{{-- Other Settings --}}
<div class="form-group">
    <label for="rest_headers" class="col-sm-2 control-label">Extra Headers (JSON)</label>
    <div class="col-sm-6">
        <textarea id="rest_headers" class="form-control" name="rest_headers" rows="2"
                  placeholder='{"X-Custom-Header":"value"}'>{{ old('rest_headers', $device->getAttrib('rest_headers', '')) }}</textarea>
    </div>
</div>

<div class="form-group">
    <div class="col-sm-offset-2 col-sm-6">
        <div class="checkbox">
            <label>
                @php $verify = old('rest_verify_tls', $device->getAttrib('rest_verify_tls', 1)); @endphp
                <input type="checkbox" id="rest_verify_tls" name="rest_verify_tls" value="1"
                       {{ $verify ? 'checked' : '' }}>
                Verify TLS/SSL certificates
            </label>
            <small class="text-muted d-block">Uncheck to disable SSL certificate verification (not recommended for production).</small>
        </div>
    </div>
</div>

<div class="form-group">
    <label for="rest_timeout_ms" class="col-sm-2 control-label">Timeout (ms)</label>
    <div class="col-sm-6">
        <input type="number" id="rest_timeout_ms" class="form-control" name="rest_timeout_ms"
               value="{{ old('rest_timeout_ms', $device->getAttrib('rest_timeout_ms', 5000)) }}">
    </div>
</div>

<div class="form-group">
    <label for="rest_proxy" class="col-sm-2 control-label">Proxy (optional)</label>
    <div class="col-sm-6">
        <input type="text" id="rest_proxy" class="form-control" name="rest_proxy"
               value="{{ old('rest_proxy', $device->getAttrib('rest_proxy', '')) }}"
               placeholder="http://user:pass@proxy:3128">
    </div>
</div>

<div class="form-group">
    <label for="rest_rate_limit_qps" class="col-sm-2 control-label">Rate Limit (queries/second)</label>
    <div class="col-sm-6">
        <input type="number" id="rest_rate_limit_qps" class="form-control" name="rest_rate_limit_qps" min="1" max="100"
               value="{{ old('rest_rate_limit_qps', $device->getAttrib('rest_rate_limit_qps', 10)) }}">
        <small class="text-muted">Maximum API requests per second (default: 10).</small>
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

        @if(!empty($device->getAttrib('rest_error_count')) && $device->getAttrib('rest_error_count') > 0)
            <button type="button" id="reset-circuit-breaker" class="btn btn-warning">
                <i class="fa fa-refresh"></i> Reset Error Counter
            </button>
        @endif
    </div>
</div>

@if(!empty($device->getAttrib('rest_last_success')))
<div class="form-group">
    <div class="col-sm-offset-2 col-sm-6">
        <small class="text-muted">
            <i class="fa fa-check-circle text-success"></i>
            Last success: {{ \Carbon\Carbon::createFromTimestamp($device->getAttrib('rest_last_success'))->diffForHumans() }}
            @if(!empty($device->getAttrib('rest_avg_latency_ms')))
                (avg {{ $device->getAttrib('rest_avg_latency_ms') }}ms)
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
const autoSelectTemplate = {{ isset($autoSelectTemplate) && $autoSelectTemplate ? 'true' : 'false' }};

// Template metadata and auth config
const templates = @json($templates);
const authTypes = @json($authTypes);
const configuredEndpoints = @json($configuredEndpoints);

// Pre-load all template data for instant switching
const allTemplateData = {
@foreach($templates as $vendor => $template)
    @php
        $fullTemplate = \LibreNMS\Util\ApiTemplateManager::loadTemplate($vendor);
    @endphp
    '{{ $vendor }}': @json($fullTemplate),
@endforeach
};

// Endpoints storage
let endpoints = configuredEndpoints && Array.isArray(configuredEndpoints) ? configuredEndpoints : [];

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
        $('.auth-description').text(authTypes[authType].description);
    }

    // Auto-select and apply template if only one matches device OS and nothing is configured yet
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
        $('#rest_verify_tls').prop('checked', template.default_settings.verify_tls ?? true);
        $('#rest_timeout_ms').val(template.default_settings.timeout_ms ?? 5000);
        $('#rest_rate_limit_qps').val(template.default_settings.rate_limit_qps ?? 10);
    }

    // Load endpoints from template (always replace when template is selected)
    if (template.endpoints && template.endpoints.length > 0) {
        endpoints = template.endpoints.map(ep => ({...ep})); // Deep copy
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
        $('.auth-description').text(authTypes[authType].description);
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

    if (endpoints.length === 0) {
        $('#no-endpoints-msg').show();
        return;
    }

    $('#no-endpoints-msg').hide();

    endpoints.forEach((endpoint, index) => {
        const row = `
            <tr data-index="${index}">
                <td>
                    <input type="checkbox" class="endpoint-enabled" data-index="${index}"
                           ${endpoint.enabled ? 'checked' : ''}>
                </td>
                <td>${escapeHtml(endpoint.name)}</td>
                <td><code>${escapeHtml(endpoint.path)}</code></td>
                <td><span class="label label-info">${endpoint.method || 'GET'}</span></td>
                <td>${endpoint.category || 'general'}</td>
                <td>${endpoint.poll_interval || 60}</td>
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
    $('#rest_endpoints').val(JSON.stringify(endpoints));
}

// Toggle endpoint enabled status
$(document).on('change', '.endpoint-enabled', function() {
    const index = $(this).data('index');
    endpoints[index].enabled = $(this).is(':checked');
    updateEndpointsHiddenField();
});

// Toggle all endpoints
$('#toggle-all-endpoints').on('change', function() {
    const checked = $(this).is(':checked');
    $('.endpoint-enabled').prop('checked', checked).each(function() {
        const index = $(this).data('index');
        endpoints[index].enabled = checked;
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
    showEndpointModal(endpoints[index], index);
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

    const method = prompt('HTTP Method (GET/POST):', endpoint?.method || 'GET');
    const category = prompt('Category:', endpoint?.category || 'general');
    const pollInterval = parseInt(prompt('Poll Interval (seconds):', endpoint?.poll_interval || 60));
    const description = prompt('Description:', endpoint?.description || '');

    const newEndpoint = {
        id: endpoint?.id || 'custom_' + Date.now(),
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
    return String(text).replace(/[&<>"']/g, m => map[m]);
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
        if (d.success) {
            toastr.success('Connection successful! Detected: ' + (d.vendor || 'Unknown') + ' ' + (d.version || ''));
        } else {
            toastr.error('Connection failed: ' + (d.error || 'Unknown error'));
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
        if (d.success) {
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
